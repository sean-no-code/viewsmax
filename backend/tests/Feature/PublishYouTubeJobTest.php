<?php

namespace Tests\Feature;

use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\YouTubePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * YouTube publishing now resolves the target's account from the multi-account
 * `social_accounts` store (per channel), not the single `connections` row.
 */
class PublishYouTubeJobTest extends TestCase
{
    use RefreshDatabase;

    private function ytAccount(User $user, string $channelId = 'yt-chan-1'): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => $channelId,
            'name' => 'My Channel',
            'access_token' => 'yt-token',
            'refresh_token' => 'yt-refresh',
            'token_expires_at' => now()->addDay(), // valid -> ensureFreshToken is a no-op
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function videoPost(User $user): Post
    {
        config(['filesystems.default' => 'local', 'filesystems.media_disk' => 'r2test']);
        Storage::fake('local');
        Storage::fake('r2test');
        Storage::disk('r2test')->put('posts/1/clip.mp4', 'fake-video-bytes');

        return Post::create([
            'user_id' => $user->id,
            'caption' => 'My video',
            'media' => [['type' => 'video', 'path' => 'posts/1/clip.mp4', 'disk' => 'r2test']],
            'status' => Post::STATUS_POSTED,
        ]);
    }

    public function test_publishes_video_through_the_resolved_channel_account(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);

        $user = User::factory()->create();
        $account = $this->ytAccount($user);
        $post = $this->videoPost($user);
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_status' => 'public'],
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('yt-vid-1', $target->platform_post_id);
    }

    public function test_pinned_target_with_missing_account_fails_clearly(): void
    {
        $user = User::factory()->create();
        $post = $this->videoPost($user);
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => 999, // no such account
            'status' => PostTarget::STATUS_PENDING,
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('reconnect', strtolower((string) $target->error));
    }
}
