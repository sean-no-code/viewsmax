<?php

namespace Tests\Feature;

use App\Support\SafeHttp;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /api/free-tools/transcript: Gemini listens to the video (YouTube by URL,
 * Instagram via CaptAPI's media link + the File API; TikTok comes from CaptAPI's
 * own transcript endpoint since no TikTok file is obtainable), results are
 * cached per platform+URL in the transcripts table. Public endpoint: no outlier
 * rows may be written, and provider answers (no speech, private video, slow)
 * must not be reported as our bugs.
 */
class FreeToolTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private const YT = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private const GEN = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'testing',
            'services.gemini.api_key' => 'g-test',
            'app.live_apis_in_development' => true,
            'services.gemini.video_model' => 'gemini-3.8-flash',
            'services.gemini.poll_interval' => 0,
            'services.captapi.api_key' => 'capt_live_test',
            'services.captapi.base_url' => 'https://api.captapi.com/v1',
        ]);
        SafeHttp::resolveUsing(fn (string $host) => $host === 'cdn.test' ? ['93.184.216.34'] : []);
    }

    protected function tearDown(): void
    {
        SafeHttp::resolveUsing(null);
        parent::tearDown();
    }

    private static function geminiReply(array $data): array
    {
        return [
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($data)]]]]],
            'usageMetadata' => ['promptTokenCount' => 3900, 'candidatesTokenCount' => 180, 'thoughtsTokenCount' => 0,
                'promptTokensDetails' => [['modality' => 'VIDEO', 'tokenCount' => 3800], ['modality' => 'TEXT', 'tokenCount' => 100]]],
        ];
    }

    private static function transcript(): array
    {
        return ['has_speech' => true, 'language' => 'en', 'segments' => [['text' => 'Never gonna give you up.', 'start' => '0:00', 'end' => '0:02']]];
    }

    private function fakeSuccess(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(self::geminiReply(self::transcript()))]);
    }

    public function test_fetches_and_stores_a_transcript(): void
    {
        $this->fakeSuccess();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertOk()
            ->assertJsonPath('data.text', 'Never gonna give you up.')
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.segments.0.startMs', 0)
            ->assertJsonPath('data.segments.0.endMs', 2000);

        $this->assertDatabaseHas('transcripts', ['platform' => 'youtube', 'source_url' => self::YT, 'language' => 'en'], 'outlier_db');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::GEN
            && $r['contents'][0]['parts'][0]['fileData']['fileUri'] === self::YT
            && $r->hasHeader('x-goog-api-key', 'g-test'));
    }

    public function test_second_request_is_served_from_the_db_cache(): void
    {
        $this->fakeSuccess();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])->assertOk();
        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertOk()
            ->assertJsonPath('data.cached', true);

        Http::assertSentCount(1); // Gemini was paid only the first time
        $this->assertDatabaseCount('transcripts', 1, 'outlier_db');
    }

    public function test_youtube_short_links_are_normalised_to_the_watch_url(): void
    {
        $this->fakeSuccess();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ?si=abc'])
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r['contents'][0]['parts'][0]['fileData']['fileUri'] === self::YT);
    }

    public function test_tiktok_uses_captapi_transcript_without_gemini_or_outlier_rows(): void
    {
        Http::fake([
            'api.captapi.com/v1/tiktok/transcript*' => Http::response([
                'success' => true,
                'data' => ['platform' => 'tiktok', 'url' => 'x', 'text' => 'hi there', 'segments' => [['text' => 'hi there', 'startMs' => 0, 'endMs' => 900]], 'language' => 'en'],
                'creditsUsed' => 2,
            ]),
        ]);

        $this->postJson('/api/free-tools/transcript', ['platform' => 'tiktok', 'url' => 'https://www.tiktok.com/@promakeuppro/video/7652684315989462290'])
            ->assertOk()
            ->assertJsonPath('data.platform', 'tiktok')
            ->assertJsonPath('data.text', 'hi there')
            ->assertJsonPath('data.segments.0.endMs', 900);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/tiktok/transcript') && $r['url'] === 'https://www.tiktok.com/@promakeuppro/video/7652684315989462290');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $this->assertDatabaseCount('videos', 0, 'outlier_db');
        $this->assertDatabaseCount('transcripts', 1, 'outlier_db');
    }

    public function test_tiktok_without_captions_is_a_404_and_not_reported(): void
    {
        config(['app.debug' => false]);
        Http::fake(['api.captapi.com/v1/tiktok/transcript*' => Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Transcript not available for this video']], 404)]);
        $reported = &$this->captureReportsRef();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'tiktok', 'url' => 'https://www.tiktok.com/@promakeuppro/video/7652684315989462290'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Transcript not available for this video');

        $this->assertSame([], $reported);
    }

    public function test_instagram_uses_captapi_media_url_and_the_file_api_without_storing_outlier_rows(): void
    {
        Http::fake([
            'api.captapi.com/v1/instagram/details*' => Http::response(['success' => true, 'data' => [
                'id' => 'DVzhP3Cj8ZL', 'videoUrl' => 'https://cdn.test/v.mp4', 'videoUrlExpiresAt' => '2026-10-27T10:00:00Z',
            ]]),
            'https://cdn.test/v.mp4' => Http::response('MP4DATA', 200, ['Content-Type' => 'video/mp4']),
            'https://generativelanguage.googleapis.com/upload/v1beta/files' => Http::response('', 200, [
                'X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=u1',
            ]),
            'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=u1' => Http::response([
                'file' => ['name' => 'files/abc', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'state' => 'ACTIVE', 'mimeType' => 'video/mp4'],
            ]),
            'https://generativelanguage.googleapis.com/v1beta/files/abc' => Http::response('', 200),
            self::GEN => Http::response(self::geminiReply(self::transcript())),
        ]);

        $this->postJson('/api/free-tools/transcript', ['platform' => 'instagram', 'url' => 'https://www.instagram.com/p/DVzhP3Cj8ZL/'])
            ->assertOk()
            ->assertJsonPath('data.platform', 'instagram')
            ->assertJsonPath('data.text', 'Never gonna give you up.');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/instagram/details'));
        Http::assertSent(fn (Request $r) => $r->url() === self::GEN
            && $r['contents'][0]['parts'][0]['fileData']['fileUri'] === 'https://generativelanguage.googleapis.com/v1beta/files/abc');
        $this->assertDatabaseCount('videos', 0, 'outlier_db');
        $this->assertDatabaseCount('channels', 0, 'outlier_db');
        $this->assertDatabaseCount('transcripts', 1, 'outlier_db');
    }

    public function test_rejects_a_url_that_does_not_match_the_platform(): void
    {
        Http::fake();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => 'https://www.instagram.com/p/DZFsjH9E3gK/'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_validates_the_platform(): void
    {
        $this->postJson('/api/free-tools/transcript', ['platform' => 'facebook', 'url' => self::YT])
            ->assertStatus(422);
    }

    public function test_returns_502_on_a_bad_provider_response(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Internal error']], 400)]);

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertStatus(502);

        $this->assertDatabaseCount('transcripts', 0, 'outlier_db');
    }

    public function test_no_speech_is_a_404_and_is_not_reported_or_cached(): void
    {
        config(['app.debug' => false]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(self::geminiReply(['has_speech' => false, 'language' => null, 'segments' => []]))]);
        $reported = &$this->captureReportsRef();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No spoken audio was detected in this video.');

        $this->assertSame([], $reported, 'A video with no speech is not our bug and must not be reported to Sentry.');
        $this->assertDatabaseCount('transcripts', 0, 'outlier_db');
    }

    public function test_private_youtube_video_is_a_404_with_a_safe_message_and_is_not_reported(): void
    {
        config(['app.debug' => false]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 400,
            'message' => 'The video https://www.youtube.com/watch?v=dQw4w9WgXcQ is not available for processing.']], 400)]);
        $reported = &$this->captureReportsRef();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertStatus(404)
            ->assertJsonPath('message', "We couldn't access this video. It may be private, age-restricted, removed, or not supported.");

        $this->assertSame([], $reported);
    }

    public function test_provider_timeout_is_a_504_with_a_retry_message_and_is_not_reported(): void
    {
        config(['app.debug' => false]);
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $reported = &$this->captureReportsRef();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The video analysis took too long. Please try again in a minute.');

        $this->assertSame([], $reported, 'A provider timeout must not be reported to Sentry.');
        $this->assertDatabaseCount('transcripts', 0, 'outlier_db');
    }

    public function test_captapi_media_lookup_timeout_is_a_504(): void
    {
        Http::fake(['api.captapi.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->postJson('/api/free-tools/transcript', ['platform' => 'instagram', 'url' => 'https://www.instagram.com/reel/DeovNRbSn-e/'])
            ->assertStatus(504)
            ->assertJsonPath('message', 'The video provider took too long to respond. Please try again in a minute.');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
    }

    /**
     * Collects what report() receives so a test can assert nothing reached Sentry.
     *
     * @return array<int, \Throwable>
     */
    private function &captureReportsRef(): array
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        return $reported;
    }
}
