<?php

namespace Tests\Feature;

use App\Jobs\PublishToTikTokJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TikTok Content Sharing Guidelines, Technical Consideration 2:
 *
 *   (a) "PULL_FROM_URL should be used when API Clients already have the
 *        to-be-posted contents on server-side file storage services."
 *   (d) "If video resources are already on API Clients' servers, do not use
 *        FILE_UPLOAD; use PULL_FROM_URL instead."
 *
 * Post media lives on the media disk (R2 in production), so video must be handed
 * to TikTok as a URL on our verified domain rather than pushed as chunks. Photos
 * already did this; video did not, which is what these lock down.
 *
 * FILE_UPLOAD stays reachable for local development, where TikTok cannot fetch
 * from localhost — see TIKTOK_VIDEO_PULL_FROM_URL.
 */
class TikTokVideoTransferTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /** A posted video target whose file sits on the media disk, as in production. */
    private function videoTarget(User $user): PostTarget
    {
        config(['filesystems.media_disk' => 'r2test']);
        Storage::fake('r2test');
        Storage::disk('r2test')->put('posts/1/clip.mp4', str_repeat('v', 2048));

        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'Audit clip',
            'media' => [['type' => 'video', 'path' => 'posts/1/clip.mp4', 'disk' => 'r2test']],
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'tiktok',
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_level' => 'SELF_ONLY'],
        ]);
    }

    /** The decoded body of the first request made to the given endpoint. */
    private function bodyOf(string $endpoint): array
    {
        $sent = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(fn (Request $r) => str_contains($r->url(), $endpoint));

        $this->assertNotNull($sent, "No request was made to {$endpoint}");

        return json_decode($sent->body(), true) ?? [];
    }

    public function test_video_is_pulled_from_our_domain_by_default(): void
    {
        Http::fake([
            '*/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'v_1'],
                'error' => ['code' => 'ok'],
            ], 200),
            '*/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['777']],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->account($user);
        $target = $this->videoTarget($user);

        (new PublishToTikTokJob($target->id))->handle(app(\App\Services\TikTokPublishService::class));

        $source = $this->bodyOf('/post/publish/video/init/')['source_info'] ?? [];

        $this->assertSame('PULL_FROM_URL', $source['source'] ?? null,
            'Video already on our storage must be pulled by TikTok, not uploaded (Technical 2d).');
        $this->assertStringContainsString('/media/download/', $source['video_url'] ?? '',
            'The pull URL must be the signed proxy on our verified domain.');
        $this->assertArrayNotHasKey('video_size', $source,
            'PULL_FROM_URL carries no chunking fields.');
    }

    public function test_no_chunks_are_pushed_when_pulling(): void
    {
        Http::fake([
            '*/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'v_1'],
                'error' => ['code' => 'ok'],
            ], 200),
            '*/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => 'PUBLISH_COMPLETE'],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->account($user);
        $target = $this->videoTarget($user);

        (new PublishToTikTokJob($target->id))->handle(app(\App\Services\TikTokPublishService::class));

        $puts = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'PUT');

        $this->assertCount(0, $puts, 'Pulling means TikTok fetches the file; we push nothing.');
    }

    public function test_file_upload_is_still_available_for_local_development(): void
    {
        config(['services.tiktok.video_pull_from_url' => false]);

        Http::fake([
            '*/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'v_1', 'upload_url' => 'https://upload.tiktok.test/x'],
                'error' => ['code' => 'ok'],
            ], 200),
            'upload.tiktok.test/*' => Http::response('', 201),
            '*/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => 'PUBLISH_COMPLETE'],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->account($user);
        $target = $this->videoTarget($user);

        (new PublishToTikTokJob($target->id))->handle(app(\App\Services\TikTokPublishService::class));

        $source = $this->bodyOf('/post/publish/video/init/')['source_info'] ?? [];

        $this->assertSame('FILE_UPLOAD', $source['source'] ?? null,
            'Opting out must restore chunked upload, since TikTok cannot reach localhost.');
    }

    public function test_post_info_still_carries_the_users_choices_when_pulling(): void
    {
        Http::fake([
            '*/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'v_1'],
                'error' => ['code' => 'ok'],
            ], 200),
            '*/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => 'PUBLISH_COMPLETE'],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->account($user);
        $target = $this->videoTarget($user);
        $target->update(['options' => [
            'privacy_level' => 'SELF_ONLY',
            'disable_comment' => true,
            'disable_duet' => true,
            'disable_stitch' => false,
            'your_brand' => true,
        ]]);

        (new PublishToTikTokJob($target->id))->handle(app(\App\Services\TikTokPublishService::class));

        $postInfo = $this->bodyOf('/post/publish/video/init/')['post_info'] ?? [];

        $this->assertSame('SELF_ONLY', $postInfo['privacy_level'] ?? null);
        $this->assertTrue($postInfo['disable_comment'] ?? null);
        $this->assertTrue($postInfo['disable_duet'] ?? null);
        $this->assertFalse($postInfo['disable_stitch'] ?? null);
        $this->assertTrue($postInfo['brand_organic_toggle'] ?? null);
    }
}
