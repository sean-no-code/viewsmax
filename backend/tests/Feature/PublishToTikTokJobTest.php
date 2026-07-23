<?php

namespace Tests\Feature;

use App\Jobs\PublishToTikTokJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\TikTokPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PublishToTikTokJobTest extends TestCase
{
    use RefreshDatabase;

    /** A connected TikTok account in the multi-account store, with a live token. */
    private function tiktokAccount(User $user): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(), // valid -> ensureFreshToken is a no-op
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /** An in-memory account for direct service calls (no DB row needed). */
    private function looseAccount(): SocialAccount
    {
        return new SocialAccount(['platform' => 'tiktok', 'access_token' => 'token']);
    }

    /**
     * Regression: publishing to a second platform failed with "The uploaded
     * video file could not be found." because stored media items carry no
     * `disk` key, and the job fell back to filesystems.default (local) instead
     * of the media disk (R2 in prod) where post media actually lives.
     */
    public function test_reads_video_from_media_disk_when_stored_media_has_no_disk(): void
    {
        // Mirror prod: the media disk is NOT the default disk.
        config(['filesystems.default' => 'local']);
        config(['filesystems.media_disk' => 'r2test']);
        Storage::fake('local');   // deliberately EMPTY — the old bug looked here
        Storage::fake('r2test');

        $path = 'posts/1/clip.mp4';
        Storage::disk('r2test')->put($path, 'fake-video-bytes');

        $user = User::factory()->create();
        $this->tiktokAccount($user);

        // Media WITHOUT a `disk` key — the shape already-persisted posts have.
        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'Hello',
            'media' => [['type' => 'video', 'path' => $path, 'url' => 'https://cdn.example/clip.mp4']],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'tiktok',
            'status' => PostTarget::STATUS_PENDING,
        ]);

        $tiktok = Mockery::mock(TikTokPublishService::class);
        $tiktok->shouldReceive('publishVideoFromStream')
            ->once()
            ->andReturn(['publish_id' => 'pub-123', 'mode' => 'inbox', 'log' => []]);

        (new PublishToTikTokJob($target->id))->handle($tiktok);

        $target->refresh();
        $this->assertNotSame(PostTarget::STATUS_FAILED, $target->status, (string) $target->error);
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->status);
    }

    /**
     * A pinned target whose account was disconnected fails loudly (multi-account
     * resolution) rather than silently posting through a sibling account.
     */
    public function test_pinned_target_with_missing_account_fails_clearly(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'caption' => 'x', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/1.jpg']], 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create([
            'platform' => 'tiktok',
            'social_account_id' => 999, // no such account
            'status' => PostTarget::STATUS_PENDING,
        ]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('reconnect', strtolower((string) $target->error));
    }

    /**
     * A post with only images (no video) is published to TikTok as a photo
     * slideshow via the Content Posting API's PHOTO mode — not rejected for
     * "needs a video".
     */
    public function test_publishes_a_photo_slideshow_when_there_is_no_video(): void
    {
        $user = User::factory()->create();
        $this->tiktokAccount($user);

        $images = ['https://cdn.example/1.jpg', 'https://cdn.example/2.jpg'];
        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'Slides',
            'media' => [
                ['type' => 'image', 'url' => $images[0]],
                ['type' => 'image', 'url' => $images[1]],
            ],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'tiktok',
            'status' => PostTarget::STATUS_PENDING,
        ]);

        $tiktok = Mockery::mock(TikTokPublishService::class);
        $tiktok->shouldReceive('publishPhotosFromUrls')
            ->once()
            ->with(Mockery::any(), 'Slides', $images, Mockery::any())
            ->andReturn(['publish_id' => 'pub-photo', 'mode' => 'direct', 'log' => []]);
        $tiktok->shouldNotReceive('publishVideoFromStream');

        (new PublishToTikTokJob($target->id))->handle($tiktok);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->status, (string) $target->error);
        $this->assertSame('pub-photo', $target->meta['publish_id']);
    }

    /**
     * Music must NOT be auto-added to a TikTok slideshow unless the user opts in.
     * TikTok's `auto_add_music` (photo posts only) defaults to OFF for us.
     */
    public function test_photo_slideshow_does_not_auto_add_music_by_default(): void
    {
        Http::fake([
            'open.tiktokapis.com/*' => Http::response(['data' => ['publish_id' => 'pub-x']], 200),
        ]);

        app(TikTokPublishService::class)->publishPhotosFromUrls(
            $this->looseAccount(),
            'Slides',
            ['https://cdn.example/1.jpg'],
            [], // no options -> music stays off
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/post/publish/content/init/')
                && ($request->data()['post_info']['auto_add_music'] ?? null) === false;
        });
    }

    /**
     * When the user opts in, `auto_add_music: true` is forwarded to TikTok.
     */
    public function test_photo_slideshow_auto_adds_music_when_option_enabled(): void
    {
        Http::fake([
            'open.tiktokapis.com/*' => Http::response(['data' => ['publish_id' => 'pub-x']], 200),
        ]);

        app(TikTokPublishService::class)->publishPhotosFromUrls(
            $this->looseAccount(),
            'Slides',
            ['https://cdn.example/1.jpg'],
            ['auto_add_music' => true],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/post/publish/content/init/')
                && ($request->data()['post_info']['auto_add_music'] ?? null) === true;
        });
    }

    /**
     * TikTok audit compliance: the commercial-disclosure choice must reach
     * TikTok as brand_organic_toggle (your brand) / brand_content_toggle
     * (branded content). Photo path.
     */
    public function test_photo_slideshow_sends_brand_toggles_from_disclosure(): void
    {
        Http::fake([
            'open.tiktokapis.com/*' => Http::response(['data' => ['publish_id' => 'pub-x']], 200),
        ]);

        app(TikTokPublishService::class)->publishPhotosFromUrls(
            $this->looseAccount(),
            'Slides',
            ['https://cdn.example/1.jpg'],
            ['your_brand' => true, 'branded_content' => false],
        );

        Http::assertSent(function ($request) {
            $info = $request->data()['post_info'] ?? [];
            return str_contains($request->url(), '/post/publish/content/init/')
                && ($info['brand_organic_toggle'] ?? null) === true
                && ($info['brand_content_toggle'] ?? null) === false;
        });
    }

    /**
     * Same brand toggles must ride the video (FILE_UPLOAD) path — the live
     * one PublishToTikTokJob uses.
     */
    public function test_video_post_sends_brand_toggles_from_disclosure(): void
    {
        Http::fake([
            'open.tiktokapis.com/*/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'pub-v', 'upload_url' => 'https://upload.example/put'],
            ], 200),
            'upload.example/*' => Http::response('', 201),
        ]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'fake-video-bytes');
        rewind($stream);

        app(TikTokPublishService::class)->publishVideoFromStream(
            $this->looseAccount(),
            'Clip',
            $stream,
            strlen('fake-video-bytes'),
            ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'branded_content' => true, 'your_brand' => false],
        );
        fclose($stream);

        Http::assertSent(function ($request) {
            $info = $request->data()['post_info'] ?? [];
            return str_contains($request->url(), '/post/publish/video/init/')
                && ($info['brand_content_toggle'] ?? null) === true
                && ($info['brand_organic_toggle'] ?? null) === false;
        });
    }

    /**
     * Regression (real prod failure): TikTok counts the photo title limit in
     * UTF-16 runes (90) and the title is a short single-line field. A caption
     * whose first 90 code points include an emoji (2 UTF-16 units) or newlines
     * was rejected with invalid_params "post info is empty or incorrect".
     */
    public function test_photo_title_is_single_line_and_within_90_utf16_units(): void
    {
        Http::fake([
            'open.tiktokapis.com/*' => Http::response(['data' => ['publish_id' => 'pub-x']], 200),
        ]);

        // 88 chars + 📊 (2 UTF-16 units) = 90 units, then a newline + more text.
        $caption = str_repeat('a', 88)."📊more\nsecond line of the caption";

        app(TikTokPublishService::class)->publishPhotosFromUrls(
            $this->looseAccount(),
            $caption,
            ['https://cdn.example/1.jpg'],
            ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/post/publish/content/init/')) {
                return false;
            }
            $title = $request->data()['post_info']['title'] ?? '';
            $utf16Units = strlen(mb_convert_encoding($title, 'UTF-16BE', 'UTF-8')) / 2;

            return $utf16Units <= 90 && ! str_contains($title, "\n");
        });
    }

    /**
     * When TikTok reports the creator can't post right now (spam/rate code),
     * we surface an actionable "try again later" message instead of the raw
     * API body.
     */
    public function test_rate_limit_error_code_surfaces_actionable_message(): void
    {
        Http::fake([
            'open.tiktokapis.com/*' => Http::response(['error' => ['code' => 'spam_risk_too_many_posts']], 200),
        ]);

        $this->expectException(\App\Exceptions\TikTokPublishException::class);
        $this->expectExceptionMessage('posting limit');

        app(TikTokPublishService::class)->publishPhotosFromUrls(
            $this->looseAccount(),
            'Slides',
            ['https://cdn.example/1.jpg'],
            ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
        );
    }
}
