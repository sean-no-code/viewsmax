<?php

namespace Tests\Feature;

use App\Exceptions\MediaReadException;
use App\Exceptions\TikTokPublishException;
use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\TikTokPublishService;
use App\Services\YouTubePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A media file that stalls or ends early while being streamed to a platform
 * must fail with a re-upload message, not run the job into a generic timeout.
 */
class PublishMediaReadTest extends TestCase
{
    use RefreshDatabase;

    /** @return resource */
    private function temp(string $bytes)
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);

        return $fh;
    }

    /** A stub that consumes the PUT body the way a real transfer would. */
    private function consumingUploadStub(string $videoId): \Closure
    {
        return function (Request $request) use ($videoId) {
            $request->body();

            return Http::response(['id' => $videoId], 200);
        };
    }

    public function test_tiktok_chunk_upload_fails_clearly_when_the_file_is_shorter_than_declared(): void
    {
        Http::fake([
            '*/post/publish/video/init/' => Http::response([
                'data' => ['publish_id' => 'pub-v', 'upload_url' => 'https://upload.example/put'],
            ], 200),
            'upload.example/*' => Http::response('', 201),
        ]);

        $stream = $this->temp('short');

        try {
            app(TikTokPublishService::class)->publishVideoFromStream(
                new SocialAccount(['platform' => 'tiktok', 'access_token' => 'token']),
                'Clip', $stream, 5000, []
            );
            $this->fail('A short file must fail the upload.');
        } catch (TikTokPublishException $e) {
            $this->assertSame(MediaReadException::MESSAGE, $e->getMessage());
            $this->assertSame('init:direct', $e->context['log'][0]['step'], 'the audit trail so far is kept');
        } finally {
            fclose($stream);
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_youtube_put_streams_the_file_through_the_bounded_stream(): void
    {
        $seen = [];
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => function (Request $request) use (&$seen) {
                // Consume the body here, while the job still holds the handle open.
                $seen = ['body' => $request->body(), 'length' => $request->header('Content-Length')[0] ?? null];

                return Http::response(['id' => 'yt-vid-1'], 200);
            },
        ]);

        $stream = $this->temp('fake-video-bytes');
        $result = app(YouTubePublishService::class)->uploadVideo('tok', $stream, 16, ['title' => 'T'], 'public');
        fclose($stream);

        $this->assertSame('yt-vid-1', $result['video_id']);
        $this->assertSame('fake-video-bytes', $seen['body']);
        $this->assertSame('16', $seen['length'], 'the declared size becomes Content-Length');
    }

    public function test_youtube_put_fails_clearly_when_the_file_is_shorter_than_declared(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => $this->consumingUploadStub('yt-vid-1'),
        ]);

        $stream = $this->temp('short');
        $this->expectException(MediaReadException::class);
        $this->expectExceptionMessage(MediaReadException::MESSAGE);

        try {
            app(YouTubePublishService::class)->uploadVideo('tok', $stream, 5000, ['title' => 'T'], 'public');
        } finally {
            fclose($stream);
        }
    }

    public function test_youtube_job_stores_the_reupload_message_on_the_target(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => $this->consumingUploadStub('yt-vid-1'),
        ]);

        config(['filesystems.default' => 'local', 'filesystems.media_disk' => 'r2test']);
        Storage::fake('r2test');
        Storage::disk('r2test')->put('posts/1/clip.mp4', 'fake-video-bytes');

        $user = User::factory()->create();
        $account = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => 'yt-chan-1',
            'name' => 'My Channel',
            'access_token' => 'yt-token',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'My video',
            'media' => [['type' => 'video', 'path' => 'posts/1/clip.mp4', 'disk' => 'r2test']],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PENDING,
        ]);

        // The file is truncated after the job has already learned its size.
        $service = new class extends YouTubePublishService
        {
            public function uploadVideo(string $accessToken, $videoStream, int $size, array $snippet, string $privacyStatus, ?callable $onStep = null): array
            {
                return parent::uploadVideo($accessToken, $videoStream, $size + 100, $snippet, $privacyStatus, $onStep);
            }
        };

        (new PublishYouTubeJob($target->id))->handle($service);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame(MediaReadException::MESSAGE, $target->error);
    }
}
