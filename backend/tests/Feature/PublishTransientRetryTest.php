<?php

namespace Tests\Feature;

use App\Exceptions\TikTokPublishException;
use App\Models\SocialAccount;
use App\Services\TikTokPublishService;
use App\Services\YouTubePublishService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * One TikTok/YouTube blip (connection error, 429, 5xx) must not fail a post for
 * good: the publish calls retry a few times with a pause. A 4xx
 * is the platform's real answer and is never retried.
 */
class PublishTransientRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function tiktokAccount(): SocialAccount
    {
        return new SocialAccount(['platform' => 'tiktok', 'access_token' => 'token']);
    }

    public function test_tiktok_init_retries_a_5xx_then_succeeds(): void
    {
        Http::fake([
            '*/post/publish/content/init/' => Http::sequence()
                ->push(['error' => ['code' => 'internal_error']], 503)
                ->push(['data' => ['publish_id' => 'pub-x']], 200),
        ]);

        $result = app(TikTokPublishService::class)->publishPhotosFromUrls(
            $this->tiktokAccount(), 'Slides', ['https://cdn.example/1.jpg'], []
        );

        $this->assertSame('pub-x', $result['publish_id']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_tiktok_init_retries_a_connection_error(): void
    {
        Http::fake([
            '*/post/publish/content/init/' => Http::sequence()
                ->pushResponse(Http::failedConnection())
                ->push(['data' => ['publish_id' => 'pub-x']], 200),
        ]);

        $result = app(TikTokPublishService::class)->publishPhotosFromUrls(
            $this->tiktokAccount(), 'Slides', ['https://cdn.example/1.jpg'], []
        );

        $this->assertSame('pub-x', $result['publish_id']);
        Http::assertSentCount(2);
    }

    public function test_tiktok_init_gives_up_after_three_attempts(): void
    {
        Http::fake([
            '*/post/publish/content/init/' => Http::response(['error' => ['code' => 'internal_error']], 500),
        ]);

        try {
            app(TikTokPublishService::class)->publishPhotosFromUrls(
                $this->tiktokAccount(), 'Slides', ['https://cdn.example/1.jpg'], []
            );
            $this->fail('Expected the init to fail.');
        } catch (TikTokPublishException $e) {
            // The existing body-inspection path still produces the message and log.
            $this->assertStringContainsString('TikTok rejected the slideshow', $e->getMessage());
            $this->assertCount(1, $e->context['log']);
        }

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_tiktok_init_never_retries_a_4xx(): void
    {
        Http::fake([
            '*/post/publish/content/init/' => Http::response(['error' => ['code' => 'invalid_params']], 400),
        ]);

        $this->expectException(TikTokPublishException::class);

        try {
            app(TikTokPublishService::class)->publishPhotosFromUrls(
                $this->tiktokAccount(), 'Slides', ['https://cdn.example/1.jpg'], []
            );
        } finally {
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    /** The unaudited-client fallback inspects the init body, so a 4xx must still reach it. */
    public function test_tiktok_inbox_fallback_still_sees_the_4xx_body(): void
    {
        Http::fake([
            '*/post/publish/video/init/' => Http::response(['error' => ['code' => 'unaudited_client_can_only_post_to_private_accounts']], 403),
            '*/post/publish/inbox/video/init/' => Http::response(['data' => ['publish_id' => 'pub-inbox']], 200),
        ]);

        $result = app(TikTokPublishService::class)->publishVideoFromUrl(
            $this->tiktokAccount(), 'Clip', 'https://app.example/media/download/x', ['privacy_level' => 'PUBLIC_TO_EVERYONE']
        );

        $this->assertSame('inbox', $result['mode']);
        $this->assertSame('pub-inbox', $result['publish_id']);
        Http::assertSentCount(2);
    }

    public function test_tiktok_chunk_put_retries_a_5xx(): void
    {
        Http::fake([
            '*/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'pub-v', 'upload_url' => 'https://upload.example/put'],
            ], 200),
            'upload.example/*' => Http::sequence()->push('', 502)->push('', 201),
        ]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'fake-video-bytes');
        rewind($stream);

        $result = app(TikTokPublishService::class)->publishVideoFromStream(
            $this->tiktokAccount(), 'Clip', $stream, strlen('fake-video-bytes'), []
        );
        fclose($stream);

        $this->assertSame('pub-v', $result['publish_id']);
        $this->assertCount(2, $result['log'], 'init + one upload event; the retry is transparent');
        Http::assertSentCount(3);
    }

    public function test_tiktok_status_fetch_retries_a_429(): void
    {
        Http::fake([
            '*/post/publish/status/fetch/' => Http::sequence()
                ->push(['error' => ['code' => 'rate_limit_exceeded']], 429)
                ->push(['data' => ['status' => 'PUBLISH_COMPLETE']], 200),
        ]);

        $status = app(TikTokPublishService::class)->fetchStatus($this->tiktokAccount(), 'pub-x');

        $this->assertSame('PUBLISH_COMPLETE', $status['status']);
        Http::assertSentCount(2);
    }

    public function test_youtube_session_init_retries_a_5xx_but_never_the_put(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::sequence()
                ->push(['error' => ['message' => 'Backend Error']], 503)
                ->push('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'fake-video-bytes');
        rewind($stream);

        $result = app(YouTubePublishService::class)->uploadVideo('tok', $stream, strlen('fake-video-bytes'), ['title' => 'T'], 'public');
        fclose($stream);

        $this->assertSame('yt-vid-1', $result['video_id']);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
    }

    public function test_youtube_session_init_never_retries_a_refusal(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response(['error' => [
                'errors' => [['reason' => 'quotaExceeded']], 'message' => 'quota',
            ]], 403),
        ]);

        $stream = fopen('php://temp', 'r+');

        try {
            app(YouTubePublishService::class)->uploadVideo('tok', $stream, 16, ['title' => 'T'], 'public');
            $this->fail('Expected the refusal to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('daily API quota', $e->getMessage());
        } finally {
            fclose($stream);
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_youtube_session_init_surfaces_a_persistent_connection_error(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::failedConnection(),
        ]);

        $stream = fopen('php://temp', 'r+');
        $this->expectException(ConnectionException::class);

        try {
            app(YouTubePublishService::class)->uploadVideo('tok', $stream, 16, ['title' => 'T'], 'public');
        } finally {
            fclose($stream);
            Http::assertSentCount(3);
        }
    }
}
