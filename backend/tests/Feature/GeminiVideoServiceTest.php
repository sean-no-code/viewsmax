<?php

namespace Tests\Feature;

use App\Exceptions\GeminiVideoException;
use App\Services\GeminiVideoService;
use App\Support\SafeHttp;
use App\Support\VideoSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * GeminiVideoService sends the video itself (YouTube by URL, anything else
 * through the File API), asks for schema-enforced JSON, and records tokens +
 * cost for every call. The suite never reaches Google: local/testing return
 * canned results unless app.live_apis_in_development is on, and then Http is faked.
 */
class GeminiVideoServiceTest extends TestCase
{
    private const GEN = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent';

    private const META = ['title' => 'Gas station sushi is a $2B business', 'channel' => 'kaiexplains', 'platform' => 'youtube',
        'duration_seconds' => 38, 'views' => 2100000, 'video_id' => 'abc'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'testing',
            'services.gemini.api_key' => 'g-test',
            'app.live_apis_in_development' => true,
            'services.gemini.video_model' => 'gemini-3.8-flash',
            'services.gemini.price_input_per_m' => 0.75,
            'services.gemini.price_output_per_m' => 3.75,
            'services.gemini.price_doubles_after' => '2026-12-31',
            'services.gemini.poll_interval' => 0,
        ]);
        SafeHttp::resolveUsing(fn (string $host) => $host === 'cdn.test' ? ['93.184.216.34'] : []);
    }

    protected function tearDown(): void
    {
        SafeHttp::resolveUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function payload(): array
    {
        return [
            'has_speech' => true,
            'idea' => ['topic' => 'Convenience-store food economics', 'idea_seed' => 'seed', 'unique_angle' => 'angle'],
            'hook' => [['time' => '0.0s', 'line' => 'Gas station sushi…', 'note' => 'Stat shock.']],
            'structure' => ['summary' => '2 beats over 38s', 'beats' => [
                ['label' => 'HOOK', 'start' => '0:00', 'end' => '0:03', 'pct' => 30, 'title' => 'Hook', 'note' => 'n', 'highlight' => 'red'],
                ['label' => 'PAYOFF', 'start' => '0:03', 'end' => '0:38', 'pct' => 70, 'title' => 'Payoff', 'note' => 'n', 'highlight' => 'volt'],
            ]],
            'visual' => [['title' => 'Talking head · 60%', 'note' => 'Centered, kitchen behind.']],
            'transcript' => [['time' => '0:00', 'text' => 'Gas station sushi…', 'label' => 'hook']],
        ];
    }

    /** A successful generateContent reply carrying $data as the JSON text. */
    private static function reply(array $data, array $usage = []): array
    {
        return [
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($data)]]]]],
            'usageMetadata' => $usage ?: [
                'promptTokenCount' => 45000, 'candidatesTokenCount' => 4500, 'thoughtsTokenCount' => 500,
                'promptTokensDetails' => [['modality' => 'VIDEO', 'tokenCount' => 44000], ['modality' => 'TEXT', 'tokenCount' => 1000]],
            ],
        ];
    }

    private function fakeGenerate(array $data): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(self::reply($data))]);
    }

    // ---- canned mode ----

    public function test_canned_mode_sends_nothing_in_local_development_and_testing(): void
    {
        Http::fake();
        config(['app.live_apis_in_development' => false]);

        foreach (['local', 'development', 'testing'] as $env) {
            config(['app.env' => $env]);
            $service = app(GeminiVideoService::class);

            $breakdown = $service->breakdown(self::META, VideoSource::youtube('abc'));
            $transcript = $service->transcribe('youtube', 'https://youtu.be/abc', VideoSource::youtube('abc'));

            $this->assertSame(self::META['title'], $breakdown['payload']['idea']['topic'], "env {$env} should be canned");
            $this->assertSame('canned', $breakdown['usage']['model']);
            $this->assertSame(0.0, $breakdown['usage']['cost_usd']);
            $this->assertTrue($transcript['has_speech']);
            $this->assertNotEmpty($transcript['segments']);
        }

        Http::assertNothingSent();
    }

    public function test_live_flag_forces_real_calls_in_local(): void
    {
        config(['app.env' => 'local', 'app.live_apis_in_development' => true]);
        $this->fakeGenerate(self::payload());

        $result = app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'));

        $this->assertSame('Convenience-store food economics', $result['payload']['idea']['topic']);
        Http::assertSentCount(1);
    }

    // ---- request shape ----

    public function test_youtube_breakdown_sends_the_url_clipped_with_a_schema_and_the_key_in_a_header(): void
    {
        config(['services.gemini.breakdown_max_video_seconds' => 1500]);
        $this->fakeGenerate(self::payload());

        app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'));

        Http::assertSent(function (Request $r) {
            $part = $r['contents'][0]['parts'][0];
            $cfg = $r['generationConfig'];

            return $r->url() === self::GEN
                && ! str_contains($r->url(), 'key=')
                && $r->hasHeader('x-goog-api-key', 'g-test')
                && $part['fileData']['fileUri'] === 'https://www.youtube.com/watch?v=abc'
                && ! isset($part['fileData']['mimeType'])
                && $part['videoMetadata']['endOffset'] === '1500s'
                && $cfg['responseMimeType'] === 'application/json'
                && $cfg['responseSchema']['required'] === ['has_speech', 'idea', 'hook', 'structure', 'visual', 'transcript']
                && $cfg['thinkingConfig']['thinkingLevel'] === 'LOW'
                && str_contains($r['contents'][0]['parts'][1]['text'], 'Gas station sushi is a $2B business');
        });
    }

    public function test_transcribe_uses_its_own_cap_and_schema(): void
    {
        config(['services.gemini.transcript_max_video_seconds' => 900]);
        $this->fakeGenerate(['has_speech' => true, 'language' => 'en', 'segments' => [['text' => 'Hi', 'start' => '0:00', 'end' => '0:01']]]);

        app(GeminiVideoService::class)->transcribe('youtube', 'https://youtu.be/abc', VideoSource::youtube('abc'));

        Http::assertSent(fn (Request $r) => $r['contents'][0]['parts'][0]['videoMetadata']['endOffset'] === '900s'
            && $r['generationConfig']['responseSchema']['required'] === ['has_speech', 'language', 'segments']);
    }

    // ---- usage + cost ----

    public function test_usage_and_cost_are_extracted_from_usage_metadata(): void
    {
        $this->fakeGenerate(self::payload());

        $usage = app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'))['usage'];

        $this->assertSame('gemini-3.8-flash', $usage['model']);
        $this->assertSame('youtube', $usage['source']);
        $this->assertSame(45000, $usage['prompt_tokens']);
        $this->assertSame(44000, $usage['video_tokens']);
        $this->assertSame(1000, $usage['text_tokens']);
        $this->assertSame(4500, $usage['output_tokens']);
        $this->assertSame(500, $usage['thinking_tokens']);
        // 45000 × $0.75/M + (4500 + 500) × $3.75/M
        $this->assertSame(0.05250, $usage['cost_usd']);
        $this->assertIsInt($usage['ms']);
    }

    public function test_price_doubles_after_the_intro_pricing_cutover(): void
    {
        Carbon::setTestNow('2027-01-01 00:00:01');
        $this->fakeGenerate(self::payload());

        $usage = app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'))['usage'];

        $this->assertSame(0.105, $usage['cost_usd']);
    }

    public function test_each_call_writes_one_line_to_the_gemini_channel(): void
    {
        $this->fakeGenerate(self::payload());
        $spy = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('gemini')->andReturn($spy);

        app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'));

        $spy->shouldHaveReceived('info')->once()->with('gemini.video', \Mockery::on(fn ($ctx) => $ctx['feature'] === 'breakdown'
            && $ctx['video_id'] === 'abc' && $ctx['status'] === 'ok' && $ctx['cost_usd'] === 0.0525 && $ctx['video_tokens'] === 44000));
    }

    // ---- remote (TikTok / Instagram) sources ----

    private function fakeRemoteFlow(array $data): void
    {
        Http::fake([
            'https://cdn.test/v.mp4' => Http::response('MP4DATA', 200, ['Content-Type' => 'video/mp4']),
            'https://generativelanguage.googleapis.com/upload/v1beta/files' => Http::response('', 200, [
                'X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=u1',
            ]),
            'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=u1' => Http::response([
                'file' => ['name' => 'files/abc', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'state' => 'PROCESSING', 'mimeType' => 'video/mp4'],
            ]),
            'https://generativelanguage.googleapis.com/v1beta/files/abc' => Http::sequence()
                ->push(['name' => 'files/abc', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'state' => 'ACTIVE', 'mimeType' => 'video/mp4'])
                ->push('', 200), // the DELETE
            self::GEN => Http::response(self::reply($data)),
        ]);
    }

    public function test_remote_source_is_downloaded_uploaded_polled_analysed_and_deleted(): void
    {
        $this->fakeRemoteFlow(self::payload());

        $result = app(GeminiVideoService::class)->breakdown(self::META + ['platform' => 'tiktok'], VideoSource::remote('https://cdn.test/v.mp4'));

        $this->assertSame('Convenience-store food economics', $result['payload']['idea']['topic']);
        $this->assertSame('remote', $result['usage']['source']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/upload/v1beta/files'
            && $r->hasHeader('X-Goog-Upload-Protocol', 'resumable') && $r->hasHeader('X-Goog-Upload-Command', 'start')
            && $r->hasHeader('X-Goog-Upload-Header-Content-Length', '7') && $r->hasHeader('X-Goog-Upload-Header-Content-Type', 'video/mp4')
            && $r->hasHeader('x-goog-api-key', 'g-test'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'upload_id=u1')
            && $r->hasHeader('X-Goog-Upload-Command', 'upload, finalize') && $r->hasHeader('X-Goog-Upload-Offset', '0') && $r->body() === 'MP4DATA');
        Http::assertSent(fn (Request $r) => $r->url() === self::GEN
            && $r['contents'][0]['parts'][0]['fileData'] === ['fileUri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'mimeType' => 'video/mp4']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1beta/files/abc'));
        $this->assertSame([], glob(sys_get_temp_dir().'/gemini-video-*'), 'the downloaded clip must be removed');
    }

    public function test_remote_download_failure_is_media_unavailable_and_nothing_is_uploaded(): void
    {
        Http::fake(['https://cdn.test/v.mp4' => Http::response('', 403)]);

        try {
            app(GeminiVideoService::class)->breakdown(self::META + ['platform' => 'tiktok'], VideoSource::remote('https://cdn.test/v.mp4'));
            $this->fail('expected GeminiVideoException');
        } catch (GeminiVideoException $e) {
            $this->assertSame(GeminiVideoException::MEDIA_UNAVAILABLE, $e->reason);
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
        $this->assertSame([], glob(sys_get_temp_dir().'/gemini-video-*'));
    }

    // ---- transcript-only breakdown (TikTok) ----

    public function test_breakdown_from_transcript_sends_no_video_and_flags_inferred_visuals(): void
    {
        $this->fakeGenerate(self::payload());

        $result = app(GeminiVideoService::class)->breakdownFromTranscript(
            self::META + ['platform' => 'tiktok'],
            'Gas station sushi is a two billion dollar business.',
            [['text' => 'Gas station sushi is a two billion dollar business.', 'startMs' => 1500, 'endMs' => 4000]],
        );

        $this->assertTrue($result['payload']['visual_inferred']);
        $this->assertSame('transcript', $result['usage']['source']);
        Http::assertSent(function (Request $r) {
            $parts = $r['contents'][0]['parts'];

            return count($parts) === 1 && ! isset($parts[0]['fileData'])
                && str_contains($parts[0]['text'], '[0:01] Gas station sushi')
                && str_contains($parts[0]['text'], 'You cannot see the video')
                && $r['generationConfig']['responseSchema']['required'][0] === 'has_speech';
        });
    }

    // ---- transcript post-processing ----

    public function test_transcribe_converts_timestamps_to_milliseconds(): void
    {
        $this->fakeGenerate(['has_speech' => true, 'language' => 'pt-BR', 'segments' => [
            ['text' => 'Olá pessoal.', 'start' => '0:00', 'end' => '0:02'],
            ['text' => 'Até mais.', 'start' => '1:07', 'end' => '1:09.5'],
            ['text' => '', 'start' => '1:10', 'end' => '1:11'],
        ]]);

        $t = app(GeminiVideoService::class)->transcribe('youtube', 'https://youtu.be/abc', VideoSource::youtube('abc'));

        $this->assertTrue($t['has_speech']);
        $this->assertSame('pt-BR', $t['language']);
        $this->assertSame('Olá pessoal. Até mais.', $t['text']);
        $this->assertSame([
            ['text' => 'Olá pessoal.', 'startMs' => 0, 'endMs' => 2000],
            ['text' => 'Até mais.', 'startMs' => 67000, 'endMs' => 69500],
        ], $t['segments']);
    }

    public function test_transcribe_flags_a_video_with_no_speech(): void
    {
        $this->fakeGenerate(['has_speech' => false, 'language' => null, 'segments' => []]);

        $t = app(GeminiVideoService::class)->transcribe('youtube', 'https://youtu.be/abc', VideoSource::youtube('abc'));

        $this->assertFalse($t['has_speech']);
        $this->assertSame('', $t['text']);
        $this->assertSame([], $t['segments']);
    }

    // ---- breakdown post-processing ----

    public function test_breakdown_normalises_beat_percentages_to_sum_to_100(): void
    {
        $payload = self::payload();
        $payload['structure']['beats'][0]['pct'] = 40;
        $payload['structure']['beats'][1]['pct'] = 80;
        $this->fakeGenerate($payload);

        $beats = app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'))['payload']['structure']['beats'];

        $this->assertSame([33, 67], array_column($beats, 'pct'));
    }

    // ---- failures ----

    public function test_unavailable_youtube_video_is_not_reportable_and_hides_the_provider_text(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 400,
            'message' => 'The video https://www.youtube.com/watch?v=abc is not available for processing.']], 400)]);

        try {
            app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'));
            $this->fail('expected GeminiVideoException');
        } catch (GeminiVideoException $e) {
            $this->assertSame(GeminiVideoException::VIDEO_UNAVAILABLE, $e->reason);
            $this->assertFalse($e->reportable);
            $this->assertStringNotContainsString('processing', $e->getMessage());
            $this->assertStringContainsString('not available', $e->detail);
        }
    }

    public function test_a_missing_or_private_youtube_id_is_a_403_permission_error_from_google(): void
    {
        // Observed live for an id that doesn't exist: HTTP 403, "The caller does not have permission".
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED',
            'message' => 'The caller does not have permission']], 403)]);

        try {
            app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('aaaaaaaaaaa'));
            $this->fail('expected GeminiVideoException');
        } catch (GeminiVideoException $e) {
            $this->assertSame(GeminiVideoException::VIDEO_UNAVAILABLE, $e->reason);
            $this->assertFalse($e->reportable);
        }
    }

    public function test_rate_limit_and_server_errors_are_provider_faults(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push(['error' => ['message' => 'Resource exhausted']], 429)->push('', 503)]);
        $service = app(GeminiVideoService::class);

        $reasons = [];
        foreach ([1, 2] as $_) {
            try {
                $service->breakdown(self::META, VideoSource::youtube('abc'));
            } catch (GeminiVideoException $e) {
                $reasons[] = [$e->reason, $e->reportable];
            }
        }

        $this->assertSame([[GeminiVideoException::BUSY, false], [GeminiVideoException::TIMEOUT, false]], $reasons);
    }

    public function test_connection_timeout_is_a_timeout(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28')]);

        $this->expectException(GeminiVideoException::class);
        $this->expectExceptionMessage('took too long');

        app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'));
    }

    public function test_malformed_or_incomplete_json_is_a_reportable_bad_response(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'not json']]]]]])
            ->push(self::reply(['idea' => ['topic' => 't']])), // missing sections
        ]);
        $service = app(GeminiVideoService::class);

        foreach ([1, 2] as $_) {
            try {
                $service->breakdown(self::META, VideoSource::youtube('abc'));
                $this->fail('expected GeminiVideoException');
            } catch (GeminiVideoException $e) {
                $this->assertSame(GeminiVideoException::BAD_RESPONSE, $e->reason);
                $this->assertTrue($e->reportable);
            }
        }
    }

    public function test_a_safety_block_is_reportable(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []])]);

        try {
            app(GeminiVideoService::class)->breakdown(self::META, VideoSource::youtube('abc'));
            $this->fail('expected GeminiVideoException');
        } catch (GeminiVideoException $e) {
            $this->assertSame(GeminiVideoException::BLOCKED, $e->reason);
            $this->assertTrue($e->reportable);
        }
    }

    public function test_service_builds_without_an_api_key(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->assertInstanceOf(GeminiVideoService::class, new GeminiVideoService);
    }
}
