<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Services\YouTubePublishService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * YouTube uploads go out in 8 MB chunks through a resumable session that is
 * probed before anything is re-sent, and every Data API refusal
 * is turned into plain advice.
 */
class YouTubeResumableUploadTest extends TestCase
{
    private const SESSION = 'https://upload.youtube/session';

    private const INIT = 'googleapis.com/upload/youtube/v3/videos*';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    /** @return resource */
    private function temp(string $bytes)
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);

        return $fh;
    }

    private function upload(string $bytes, ?string $resumeUrl = null, ?callable $onStep = null): array
    {
        $stream = $this->temp($bytes);
        try {
            return app(YouTubePublishService::class)->uploadVideo('tok', $stream, strlen($bytes), ['title' => 'T'], 'public', $onStep, $resumeUrl);
        } finally {
            fclose($stream);
        }
    }

    /** What each PUT on the session carried: [Content-Range, body length]. */
    private function puts(): array
    {
        $puts = [];
        Http::recorded(function (Request $request) use (&$puts) {
            if ($request->method() === 'PUT') {
                $puts[] = [$request->header('Content-Range')[0] ?? null, strlen($request->body())];
            }
        });

        return $puts;
    }

    public function test_a_video_larger_than_one_chunk_goes_out_in_8mb_pieces_with_content_range(): void
    {
        $chunk = YouTubePublishService::CHUNK_BYTES;
        $size = $chunk + 5;
        Http::fake([
            self::INIT => Http::response('', 200, ['Location' => self::SESSION]),
            'upload.youtube/*' => Http::sequence()
                ->push('', 308, ['Range' => 'bytes=0-'.($chunk - 1)])
                ->push(['id' => 'yt-vid-1'], 200),
        ]);

        $result = $this->upload(str_repeat('a', $size));

        $this->assertSame('yt-vid-1', $result['video_id']);
        $this->assertSame([
            ["bytes 0-".($chunk - 1)."/{$size}", $chunk],
            ["bytes {$chunk}-".($size - 1)."/{$size}", 5],
        ], $this->puts());
    }

    public function test_a_stored_session_is_probed_and_resumed_from_the_committed_offset(): void
    {
        $chunk = YouTubePublishService::CHUNK_BYTES;
        $size = $chunk + 5;
        Http::fake([
            self::INIT => fn () => $this->fail('a live session must not be reopened'),
            'upload.youtube/*' => Http::sequence()
                ->push('', 308, ['Range' => 'bytes=0-'.($chunk - 1)]) // the probe: first chunk already arrived
                ->push(['id' => 'yt-vid-1'], 200),
        ]);

        $steps = [];
        $result = $this->upload(str_repeat('a', $size), self::SESSION, function (string $step, ?string $url = null) use (&$steps) {
            $steps[] = [$step, $url];
        });

        $this->assertSame('yt-vid-1', $result['video_id']);
        $this->assertSame([
            ["bytes */{$size}", 0],
            ["bytes {$chunk}-".($size - 1)."/{$size}", 5],
        ], $this->puts(), 'only the missing tail is sent');
        $this->assertSame([['probe', null], ['upload', self::SESSION]], $steps);
    }

    public function test_a_probe_that_finds_the_video_never_uploads_again(): void
    {
        Http::fake([
            self::INIT => fn () => $this->fail('no new session'),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-done'], 200),
        ]);

        $result = $this->upload('fake-video-bytes', self::SESSION);

        $this->assertSame('yt-vid-done', $result['video_id']);
        $this->assertSame('https://www.youtube.com/watch?v=yt-vid-done', $result['video_url']);
        $this->assertSame([['bytes */16', 0]], $this->puts());
    }

    public function test_a_gone_session_is_replaced_by_a_new_one(): void
    {
        Http::fake([
            self::INIT => Http::response('', 200, ['Location' => 'https://upload.youtube/session-2']),
            'upload.youtube/session' => Http::response(['error' => ['message' => 'gone']], 404),
            'upload.youtube/session-2' => Http::response(['id' => 'yt-vid-2'], 200),
        ]);

        $steps = [];
        $result = $this->upload('fake-video-bytes', self::SESSION, function (string $step, ?string $url = null) use (&$steps) {
            $steps[] = [$step, $url];
        });

        $this->assertSame('yt-vid-2', $result['video_id']);
        $this->assertSame([['probe', null], ['session-init', null], ['upload', 'https://upload.youtube/session-2']], $steps);
        Http::assertSentCount(3);
    }

    public function test_a_308_that_committed_fewer_bytes_than_sent_resends_from_that_offset(): void
    {
        Http::fake([
            self::INIT => Http::response('', 200, ['Location' => self::SESSION]),
            'upload.youtube/*' => Http::sequence()
                ->push('', 308, ['Range' => 'bytes=0-9']) // Google kept 10 of 16
                ->push(['id' => 'yt-vid-1'], 200),
        ]);

        $this->upload('0123456789abcdef');

        $puts = $this->puts();
        $this->assertSame(['bytes 0-15/16', 16], $puts[0]);
        $this->assertSame(['bytes 10-15/16', 6], $puts[1]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->body() === 'abcdef');
    }

    public function test_a_5xx_mid_upload_is_transient_so_the_job_resumes_later(): void
    {
        Http::fake([
            self::INIT => Http::response('', 200, ['Location' => self::SESSION]),
            'upload.youtube/*' => Http::response(['error' => ['message' => 'Backend Error']], 503),
        ]);

        $this->expectException(TransientPublishException::class);
        $this->expectExceptionMessage('temporary error while uploading the video');

        $this->upload('fake-video-bytes');
    }

    public function test_a_dropped_connection_mid_upload_is_transient(): void
    {
        Http::fake([
            self::INIT => Http::response('', 200, ['Location' => self::SESSION]),
            'upload.youtube/*' => Http::failedConnection(),
        ]);

        $this->expectException(TransientPublishException::class);
        $this->expectExceptionMessage('dropped at byte 0 of 16');

        $this->upload('fake-video-bytes');
    }

    public function test_a_transient_probe_answer_is_transient_too(): void
    {
        Http::fake([
            self::INIT => fn () => $this->fail('no new session while the old one may still be alive'),
            'upload.youtube/*' => Http::response('', 429),
        ]);

        $this->expectException(TransientPublishException::class);
        $this->expectExceptionMessage('rate limit hit while checking the upload session');

        $this->upload('fake-video-bytes', self::SESSION);
    }

    public function test_session_init_5xx_that_outlives_the_retries_is_transient(): void
    {
        Http::fake([
            self::INIT => Http::response(['error' => ['message' => 'Backend Error']], 503),
        ]);

        $this->expectException(TransientPublishException::class);
        $this->expectExceptionMessage('opening the upload session (HTTP 503)');

        $this->upload('fake-video-bytes');
    }

    public function test_data_api_reasons_become_plain_advice(): void
    {
        $cases = [
            ['invalidTitle', 'YouTube rejected the video title'],
            ['invalidDescription', 'YouTube rejected the video description'],
            ['invalidTags', 'YouTube rejected the video tags'],
            ['invalidCategoryId', 'YouTube rejected the video category'],
            ['mediaBodyRequired', 'did not receive the video file'],
            ['rateLimitExceeded', 'too quickly'],
            ['uploadLimitExceeded', "reached YouTube's upload limit"],
            ['youtubeSignupRequired', 'no YouTube channel'],
            ['quotaExceeded', 'daily API quota'],
            ['insufficientPermissions', 'grant video upload permission'],
            ['somethingNew', 'YouTube rejected the upload: Something new happened'],
        ];

        foreach ($cases as [$reason, $expected]) {
            $message = YouTubePublishService::refusalMessage(400, [
                'message' => 'Something new happened',
                'errors' => [['reason' => $reason, 'domain' => 'youtube.video']],
            ]);
            $this->assertStringContainsString($expected, $message, $reason);
        }
    }

    public function test_dead_tokens_are_named_whatever_shape_google_sends(): void
    {
        $cases = [
            [401, ['errors' => [['reason' => 'authError']], 'message' => 'Invalid Credentials']],
            [403, ['status' => 'UNAUTHENTICATED', 'message' => 'Request had invalid authentication credentials.']],
            [400, ['message' => 'invalid_grant: Token has been expired or revoked.']],
            [403, ['message' => 'Unauthorized']],
        ];

        foreach ($cases as [$status, $error]) {
            $this->assertSame(YouTubePublishService::SESSION_EXPIRED, YouTubePublishService::refusalMessage($status, $error), json_encode($error));
        }

        $this->assertStringContainsString('daily API quota', YouTubePublishService::refusalMessage(403, [
            'errors' => [['reason' => 'quotaExceeded']], 'message' => 'The request cannot be completed because you have exceeded your quota.',
        ]), 'a quota 403 is not a dead token');
    }

    public function test_upload_put_refusals_use_the_same_map_not_the_raw_body(): void
    {
        Http::fake([
            self::INIT => Http::response('', 200, ['Location' => self::SESSION]),
            'upload.youtube/*' => Http::response(['error' => [
                'message' => 'Media body required', 'errors' => [['reason' => 'mediaBodyRequired']],
            ]], 400),
        ]);

        try {
            $this->upload('fake-video-bytes');
            $this->fail('Expected the refusal to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not receive the video file', $e->getMessage());
            $this->assertStringNotContainsString('{"error"', $e->getMessage());
        }
    }

    public function test_thumbnail_refusals_are_mapped(): void
    {
        $cases = [
            [['reason' => 'forbidden', 'domain' => 'youtube.thumbnail'], 'not verified'],
            [['reason' => 'imageFormatUnsupported', 'domain' => 'youtube.thumbnail'], 'JPEG or PNG'],
            [['reason' => 'imageTooTall', 'domain' => 'youtube.thumbnail'], 'too tall'],
            [['reason' => 'imageTooWide', 'domain' => 'youtube.thumbnail'], 'too wide'],
            [['reason' => 'failedPrecondition', 'domain' => 'global'], 'too large'],
            // An unknown reason on a non-auth status falls back to Google's message, not the raw body.
            [['reason' => 'backendError', 'domain' => 'global', 'status' => 400], 'YouTube rejected the thumbnail: Backend Error'],
        ];

        $current = null;
        Http::fake([
            'googleapis.com/upload/youtube/v3/thumbnails/set*' => function () use (&$current) {
                return Http::response(['error' => ['message' => 'Backend Error', 'errors' => [$current]]], $current['status'] ?? 403);
            },
        ]);

        foreach ($cases as [$current, $expected]) {
            $stream = $this->temp('fake-jpeg');
            try {
                app(YouTubePublishService::class)->setThumbnail('tok', 'yt-vid-1', $stream);
                $this->fail('Expected the refusal to throw.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $current['reason']);
            } finally {
                fclose($stream);
            }
        }
    }

    public function test_privacy_falls_back_to_private(): void
    {
        Http::fake([
            self::INIT => Http::response('', 200, ['Location' => self::SESSION]),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);

        $stream = $this->temp('fake-video-bytes');
        app(YouTubePublishService::class)->uploadVideo('tok', $stream, 16, ['title' => 'T'], 'bogus');
        fclose($stream);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['status']['privacyStatus'] === 'private');
    }
}
