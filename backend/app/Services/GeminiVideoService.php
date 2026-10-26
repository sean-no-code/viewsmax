<?php

namespace App\Services;

use App\Exceptions\GeminiVideoException;
use App\Support\LiveApis;
use App\Support\SafeHttp;
use App\Support\UnsafeUrlException;
use App\Support\VideoSource;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gemini video understanding: the model watches the actual video, so an
 * outlier breakdown's visual cards describe real frames and a Short with no
 * speech is read from its on-screen text. One class for both features:
 *
 *  - breakdown():  one call → transcript + idea / hook / structure / visual.
 *  - transcribe(): one call → timestamped segments for the free transcript tools.
 *
 * YouTube videos are fetched by Google from the public watch URL. Anything
 * else is a file we stream to disk (SafeHttp) and upload through the File API.
 * Every request clips the input (videoMetadata.endOffset) because billing is
 * per second of video. Each call writes one line to the `gemini` log channel
 * with tokens by modality and cost, and returns the same `usage` array for the
 * caller to persist.
 */
class GeminiVideoService
{
    private string $apiKey;

    private string $apiUrl;

    private string $baseUrl;

    private string $model;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key');
        $this->apiUrl = rtrim((string) config('services.gemini.api_url'), '/');
        $this->baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $this->model = (string) config('services.gemini.video_model');
        $this->timeout = max(1, (int) config('services.gemini.timeout', 200));
    }

    /** Canned results in local/development/testing unless USE_LIVE_IN_APIS_DEVELOPMENT=true (App\Support\LiveApis). */
    public static function usesCannedResponses(): bool
    {
        return ! LiveApis::enabled();
    }

    /**
     * @param  array{title: ?string, channel: string, platform: string, duration_seconds: mixed, views: mixed, video_id: string}  $meta
     * @return array{payload: array, usage: array}
     *
     * @throws GeminiVideoException
     */
    public function breakdown(array $meta, VideoSource $src): array
    {
        if (self::usesCannedResponses()) {
            return ['payload' => self::fakeBreakdown($meta), 'usage' => $this->cannedUsage($src)];
        }

        $prompt = $this->loadPrompt('Outliers/breakdown.txt', [
            'title' => (string) ($meta['title'] ?? ''),
            'channel' => (string) ($meta['channel'] ?? 'Unknown'),
            'platform' => (string) ($meta['platform'] ?? 'youtube'),
            'duration_seconds' => (string) ($meta['duration_seconds'] ?? 'unknown'),
            'views' => (string) ($meta['views'] ?? 'unknown'),
        ]);
        $log = ['feature' => 'breakdown', 'platform' => $meta['platform'] ?? null, 'video_id' => $meta['video_id'] ?? null];

        $result = $this->withVideo($src, (int) config('services.gemini.breakdown_max_video_seconds', 1500), $log,
            fn (array $videoPart) => $this->generateContent([$videoPart], self::breakdownSchema(), $prompt, $log));

        return ['payload' => $this->validatedBreakdown($result['data']), 'usage' => $result['usage']];
    }

    /** The five sections the FE renders must exist; beat shares must add up to a full bar. */
    private function validatedBreakdown(array $payload): array
    {
        foreach (['idea', 'hook', 'structure', 'visual', 'transcript'] as $key) {
            if (! isset($payload[$key])) {
                throw GeminiVideoException::badResponse("missing '{$key}'");
            }
        }
        $payload['has_speech'] = (bool) ($payload['has_speech'] ?? true);
        $payload['structure']['beats'] = self::normalisePct($payload['structure']['beats'] ?? []);

        return $payload;
    }

    /**
     * Breakdown from words alone — for platforms where no video file can be
     * obtained (TikTok: CaptAPI exposes no media). The visual cards are then
     * inferred from the transcript and platform norms, and the payload says so.
     *
     * @param  list<array{text: string, startMs?: int, start?: float}>  $segments
     * @return array{payload: array, usage: array}
     *
     * @throws GeminiVideoException
     */
    public function breakdownFromTranscript(array $meta, string $transcriptText, array $segments = []): array
    {
        if (self::usesCannedResponses()) {
            return ['payload' => self::fakeBreakdown($meta) + ['visual_inferred' => true], 'usage' => ['model' => 'canned'] + $this->usageFrom([], 0, 'transcript')];
        }

        $lines = collect($segments)->map(function ($s) {
            $seconds = isset($s['startMs']) ? ((int) $s['startMs']) / 1000 : (float) ($s['start'] ?? 0);

            return sprintf('[%d:%02d] %s', floor($seconds / 60), (int) $seconds % 60, $s['text'] ?? '');
        })->implode("\n");

        $prompt = $this->loadPrompt('Outliers/breakdown-transcript.txt', [
            'title' => (string) ($meta['title'] ?? ''),
            'channel' => (string) ($meta['channel'] ?? 'Unknown'),
            'platform' => (string) ($meta['platform'] ?? 'tiktok'),
            'duration_seconds' => (string) ($meta['duration_seconds'] ?? 'unknown'),
            'views' => (string) ($meta['views'] ?? 'unknown'),
            'transcript' => $lines !== '' ? $lines : $transcriptText,
        ]);
        $log = ['feature' => 'breakdown', 'platform' => $meta['platform'] ?? null, 'video_id' => $meta['video_id'] ?? null, 'source' => 'transcript'];

        $result = $this->generateContent([], self::breakdownSchema(), $prompt, $log);

        $payload = $this->validatedBreakdown($result['data']);
        $payload['visual_inferred'] = true;

        return ['payload' => $payload, 'usage' => $result['usage']];
    }

    /**
     * @return array{text: string, segments: list<array{text: string, startMs: int, endMs: int}>, language: ?string, has_speech: bool, usage: array}
     *
     * @throws GeminiVideoException
     */
    public function transcribe(string $platform, string $url, VideoSource $src): array
    {
        if (self::usesCannedResponses()) {
            return self::fakeTranscript() + ['usage' => $this->cannedUsage($src)];
        }

        $prompt = $this->loadPrompt('Outliers/transcript.txt', ['platform' => $platform]);
        $log = ['feature' => 'transcript', 'platform' => $platform, 'video_id' => $url];

        $result = $this->withVideo($src, (int) config('services.gemini.transcript_max_video_seconds', 900), $log,
            fn (array $videoPart) => $this->generateContent([$videoPart], self::transcriptSchema(), $prompt, $log));

        $segments = [];
        foreach ($result['data']['segments'] ?? [] as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = ['text' => $text, 'startMs' => self::toMs($s['start'] ?? null), 'endMs' => self::toMs($s['end'] ?? null)];
        }
        $text = implode(' ', array_column($segments, 'text'));
        $hasSpeech = (bool) ($result['data']['has_speech'] ?? false) && $text !== '';

        return [
            'text' => $hasSpeech ? $text : '',
            'segments' => $hasSpeech ? $segments : [],
            'language' => $hasSpeech ? ($result['data']['language'] ?: null) : null,
            'has_speech' => $hasSpeech,
            'usage' => $result['usage'],
        ];
    }

    /**
     * Resumable upload to the File API, then wait until Google has processed
     * the file (state ACTIVE). Files expire after 48h; callers delete them anyway.
     *
     * @return array{name: string, uri: string, mime: string}
     *
     * @throws GeminiVideoException
     */
    public function uploadFile(string $path, string $mime, int $pollSeconds = 120): array
    {
        $size = (int) filesize($path);

        try {
            $start = $this->client()->withHeaders([
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $size,
                'X-Goog-Upload-Header-Content-Type' => $mime,
            ])->timeout(30)->post("{$this->baseUrl}/upload/v1beta/files", ['file' => ['display_name' => basename($path)]]);

            $uploadUrl = (string) $start->header('X-Goog-Upload-URL');
            if (! $start->successful() || $uploadUrl === '') {
                throw $this->httpError($start, 'file upload start');
            }

            // Streamed from disk (the PSR-7 stream owns and closes the handle):
            // a 200MB clip never becomes a PHP string.
            $done = $this->client()->withHeaders([
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])->withBody(Utils::streamFor(fopen($path, 'rb')), $mime)->timeout($this->timeout)->post($uploadUrl);
            if (! $done->successful()) {
                throw $this->httpError($done, 'file upload');
            }

            $file = $done->json('file') ?? [];
            $deadline = time() + $pollSeconds;
            while (($file['state'] ?? '') !== 'ACTIVE') {
                if (($file['state'] ?? '') === 'FAILED' || time() > $deadline || empty($file['name'])) {
                    throw GeminiVideoException::badResponse('file processing: '.json_encode($file['state'] ?? null));
                }
                sleep(max(0, (int) config('services.gemini.poll_interval', 2)));
                $file = $this->client()->get("{$this->baseUrl}/v1beta/{$file['name']}")->json() ?? [];
            }
        } catch (ConnectionException $e) {
            throw GeminiVideoException::timeout('file upload: '.$e->getMessage(), $e);
        }

        return ['name' => $file['name'], 'uri' => $file['uri'], 'mime' => $file['mimeType'] ?? $mime];
    }

    /** Best effort: the file expires on its own after 48h, so a failure here is only logged. */
    public function deleteFile(string $name): void
    {
        try {
            $this->client()->timeout(15)->delete("{$this->baseUrl}/v1beta/{$name}");
        } catch (\Throwable $e) {
            Log::channel('gemini')->info('gemini.file_delete_failed', ['file' => $name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Turn a VideoSource into a request part and run $call with it. Remote
     * sources are downloaded + uploaded first and always cleaned up after.
     *
     * @param  callable(array): array  $call
     */
    private function withVideo(VideoSource $src, int $maxSeconds, array $log, callable $call): array
    {
        $clip = ['endOffset' => "{$maxSeconds}s"];

        if ($src->isYoutube()) {
            return $call(['fileData' => ['fileUri' => $src->url], 'videoMetadata' => $clip]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'gemini-video-');
        $file = null;
        try {
            try {
                SafeHttp::download($src->url, $tmp, (int) config('services.gemini.download_max_bytes'), (int) config('services.gemini.download_timeout', 60));
            } catch (UnsafeUrlException $e) {
                $this->logCall($log + ['source' => $src->kind], null, 0, 'media_unavailable', $e->getMessage());
                throw str_contains($e->getMessage(), 'too large')
                    ? GeminiVideoException::tooLarge($e->getMessage(), $e)
                    : GeminiVideoException::mediaUnavailable($e->getMessage(), $e);
            }

            $file = $this->uploadFile($tmp, $src->mime);
            @unlink($tmp);

            return $call(['fileData' => ['fileUri' => $file['uri'], 'mimeType' => $file['mime']], 'videoMetadata' => $clip]);
        } finally {
            @unlink($tmp);
            if ($file !== null) {
                $this->deleteFile($file['name']);
            }
        }
    }

    /**
     * @return array{data: array, usage: array}
     *
     * @throws GeminiVideoException
     */
    private function generateContent(array $parts, array $schema, string $prompt, array $log): array
    {
        $log += ['source' => $parts === [] ? 'transcript' : (isset($parts[0]['fileData']['mimeType']) ? VideoSource::REMOTE : VideoSource::YOUTUBE)];
        $started = microtime(true);

        try {
            $response = $this->client()->timeout($this->timeout)->post("{$this->apiUrl}/{$this->model}:generateContent", [
                'contents' => [['role' => 'user', 'parts' => [...$parts, ['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
                    'thinkingConfig' => ['thinkingLevel' => 'LOW'],
                ],
            ]);
        } catch (ConnectionException $e) {
            $this->logCall($log, null, $this->elapsed($started), 'timeout', $e->getMessage());
            throw GeminiVideoException::timeout($e->getMessage(), $e);
        }

        $ms = $this->elapsed($started);
        $usage = $this->usageFrom($response->json('usageMetadata') ?? [], $ms, $log['source']);

        if (! $response->successful()) {
            $e = $this->httpError($response, 'generateContent');
            $this->logCall($log, $usage, $ms, $e->reason, $e->detail);
            throw $e;
        }

        $block = $response->json('promptFeedback.blockReason');
        $finish = $response->json('candidates.0.finishReason');
        if ($block || in_array($finish, ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true)) {
            $this->logCall($log, $usage, $ms, 'blocked', (string) ($block ?: $finish));
            throw GeminiVideoException::blocked((string) ($block ?: $finish));
        }

        $text = collect($response->json('candidates.0.content.parts') ?? [])->pluck('text')->filter()->implode('');
        $data = json_decode($text, true);
        if (! is_array($data) || ($finish !== null && $finish !== 'STOP')) {
            $detail = 'finish='.json_encode($finish).' body='.mb_substr($text, 0, 300);
            $this->logCall($log, $usage, $ms, 'bad_response', $detail);
            throw GeminiVideoException::badResponse($detail);
        }

        $this->logCall($log, $usage, $ms, 'ok');

        return ['data' => $data, 'usage' => $usage];
    }

    private function client(): PendingRequest
    {
        // A blank key reaches Google as a 403 "unregistered caller" that reads like a
        // video problem. Fail here instead, so the report names the misconfiguration.
        if ($this->apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is not set (services.gemini.api_key); restart the queue worker after setting it.');
        }

        // The key goes in a header, never the URL: URLs end up in logs and traces.
        return Http::withHeaders(['x-goog-api-key' => $this->apiKey])->acceptJson();
    }

    private function httpError(Response $response, string $what): GeminiVideoException
    {
        $status = $response->status();
        $message = (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 300));
        $detail = "{$what}: HTTP {$status} {$message}";

        if ($status === 429) {
            return GeminiVideoException::busy($detail);
        }
        if ($status >= 500) {
            return GeminiVideoException::timeout($detail);
        }
        // Google answers a private / removed / age-gated / non-existent YouTube id with a
        // 403 "The caller does not have permission" or a 400 "not available" — the video's fault.
        if (in_array($status, [400, 403, 404], true)
            && preg_match('/not (be )?(available|found|accessible)|private|unavailable|unsupported|cannot (be )?(access|fetch)|age|permission/i', $message)) {
            return GeminiVideoException::videoUnavailable($detail);
        }

        return GeminiVideoException::badResponse($detail);
    }

    private function usageFrom(array $u, int $ms, string $source): array
    {
        $byModality = collect($u['promptTokensDetails'] ?? [])->pluck('tokenCount', 'modality');
        $in = (int) ($u['promptTokenCount'] ?? 0);
        $out = (int) ($u['candidatesTokenCount'] ?? 0);
        $think = (int) ($u['thoughtsTokenCount'] ?? 0);

        return [
            'model' => $this->model,
            'source' => $source,
            'prompt_tokens' => $in,
            'video_tokens' => (int) ($byModality['VIDEO'] ?? 0),
            'audio_tokens' => (int) ($byModality['AUDIO'] ?? 0),
            'text_tokens' => (int) ($byModality['TEXT'] ?? 0),
            'output_tokens' => $out,
            'thinking_tokens' => $think,
            'cost_usd' => $this->costUsd($in, $out + $think),
            'ms' => $ms,
        ];
    }

    /** $ for one call at the configured per-1M prices; doubles after Google's intro pricing ends. */
    private function costUsd(int $inputTokens, int $outputTokens): float
    {
        $cutover = Carbon::parse((string) config('services.gemini.price_doubles_after', '2026-12-31'))->endOfDay();
        $multiplier = now()->gt($cutover) ? 2 : 1;
        $cost = ($inputTokens * (float) config('services.gemini.price_input_per_m', 0.75)
            + $outputTokens * (float) config('services.gemini.price_output_per_m', 3.75)) * $multiplier / 1_000_000;

        return round($cost, 6);
    }

    private function cannedUsage(VideoSource $src): array
    {
        return ['model' => 'canned'] + $this->usageFrom([], 0, $src->kind);
    }

    private function logCall(array $log, ?array $usage, int $ms, string $status, string $detail = ''): void
    {
        $line = $log + ($usage ?? ['model' => $this->model]) + ['ms' => $ms, 'status' => $status];
        if ($detail !== '') {
            $line['detail'] = mb_substr($detail, 0, 500);
        }

        Log::channel('gemini')->{$status === 'ok' ? 'info' : 'warning'}('gemini.video', $line);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** "m:ss", "h:mm:ss" or "12.5s" → milliseconds (0 when unparsable). */
    private static function toMs(mixed $time): int
    {
        $time = trim((string) $time);
        if (preg_match('/^(\d+(?:\.\d+)?)s$/', $time, $m)) {
            return (int) round((float) $m[1] * 1000);
        }
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', $time, $m)) {
            return (int) round(((int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3]) * 1000);
        }

        return 0;
    }

    /** The FE draws beats as a 100%-wide bar: make integer shares that really sum to 100. */
    private static function normalisePct(array $beats): array
    {
        $beats = array_values($beats);
        $total = array_sum(array_map(fn ($b) => max(0, (int) ($b['pct'] ?? 0)), $beats));
        if ($beats === [] || $total === 100 || $total === 0) {
            return $beats;
        }

        $running = 0;
        foreach ($beats as $i => $b) {
            $pct = $i === array_key_last($beats)
                ? 100 - $running
                : (int) round(max(0, (int) ($b['pct'] ?? 0)) * 100 / $total);
            $beats[$i]['pct'] = $pct;
            $running += $pct;
        }

        return $beats;
    }

    private static function breakdownSchema(): array
    {
        $str = ['type' => 'string'];

        return ['type' => 'object', 'required' => ['has_speech', 'idea', 'hook', 'structure', 'visual', 'transcript'], 'properties' => [
            'has_speech' => ['type' => 'boolean'],
            'idea' => ['type' => 'object', 'required' => ['topic', 'idea_seed', 'unique_angle'],
                'properties' => ['topic' => $str, 'idea_seed' => $str, 'unique_angle' => $str]],
            'hook' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 4, 'items' => ['type' => 'object', 'required' => ['time', 'line', 'note'],
                'properties' => ['time' => $str, 'line' => $str, 'note' => $str]]],
            'structure' => ['type' => 'object', 'required' => ['summary', 'beats'], 'properties' => [
                'summary' => $str,
                'beats' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 6, 'items' => ['type' => 'object',
                    'required' => ['label', 'start', 'end', 'pct', 'title', 'note', 'highlight'],
                    'properties' => [
                        'label' => $str, 'start' => $str, 'end' => $str, 'pct' => ['type' => 'integer'],
                        'title' => $str, 'note' => $str,
                        'highlight' => ['type' => 'string', 'enum' => ['red', 'volt'], 'nullable' => true],
                    ]]],
            ]],
            'visual' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 5, 'items' => ['type' => 'object', 'required' => ['title', 'note'],
                'properties' => ['title' => $str, 'note' => $str]]],
            'transcript' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['time', 'text', 'label'],
                'properties' => ['time' => $str, 'text' => $str, 'label' => ['type' => 'string', 'nullable' => true]]]],
        ]];
    }

    private static function transcriptSchema(): array
    {
        $str = ['type' => 'string'];

        return ['type' => 'object', 'required' => ['has_speech', 'language', 'segments'], 'properties' => [
            'has_speech' => ['type' => 'boolean'],
            'language' => ['type' => 'string', 'nullable' => true, 'description' => 'BCP-47 code of the spoken language, e.g. en, pt-BR'],
            'segments' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['text', 'start', 'end'],
                'properties' => ['text' => $str, 'start' => $str, 'end' => $str]]],
        ]];
    }

    /** Deterministic payload for local/testing (mirrors the real shape). */
    private static function fakeBreakdown(array $meta): array
    {
        $title = (string) ($meta['title'] ?? 'This video') ?: 'This video';

        return [
            'has_speech' => true,
            'idea' => [
                'topic' => $title,
                'idea_seed' => 'The thing everyone dismisses is actually a serious, high-performing subject. The joke is the way in; the receipts are the payoff.',
                'unique_angle' => 'Defends the thing viewers expect to be roasted, forcing a reaction: agree, argue, or share it at someone.',
            ],
            'hook' => [
                ['time' => '0.0s', 'line' => $title, 'note' => 'Stat shock. A claim nobody expects, attached to a thing everybody has an opinion about. No greeting, no setup.'],
                ['time' => '1.4s', 'line' => 'The contradiction lands immediately.', 'note' => 'Flips the viewer\'s prior within two seconds — the comment section is pre-loaded with disagreement.'],
                ['time' => '2.6s', 'line' => 'Cut to b-roll, captions on', 'note' => 'Visual reset before second 3 — the pattern break lands where most viewers decide to swipe.'],
            ],
            'structure' => [
                'summary' => '5 beats over 38s',
                'beats' => [
                    ['label' => 'HOOK', 'start' => '0:00', 'end' => '0:03', 'pct' => 8, 'title' => 'Hook — stat shock', 'note' => 'Big claim in one breath. No intro, no channel branding.', 'highlight' => 'red'],
                    ['label' => 'FLIP', 'start' => '0:03', 'end' => '0:09', 'pct' => 16, 'title' => 'Flip — attack the prior', 'note' => 'Names the thing the viewer believes, then contradicts it directly.', 'highlight' => null],
                    ['label' => 'ESCALATION', 'start' => '0:09', 'end' => '0:22', 'pct' => 34, 'title' => 'Escalation — how it works', 'note' => 'Each fact raises the stakes of the opening claim instead of repeating it.', 'highlight' => null],
                    ['label' => 'RECEIPTS', 'start' => '0:22', 'end' => '0:32', 'pct' => 26, 'title' => 'Receipts — the numbers', 'note' => 'Proof lands after curiosity peaks, not before.', 'highlight' => null],
                    ['label' => 'PAYOFF', 'start' => '0:32', 'end' => '0:38', 'pct' => 16, 'title' => 'Payoff — the loop back', 'note' => 'Ends on the opening claim restated with the evidence behind it. Clean loop point for rewatches.', 'highlight' => 'volt'],
                ],
            ],
            'visual' => [
                ['title' => 'Talking head · ~40% of runtime', 'note' => 'Centered, chest-up, plain background. Face carries the hook and the payoff; b-roll carries everything in between.'],
                ['title' => 'Captions · always on', 'note' => '3–4 words per line, lower third, keyword highlighted per line.'],
                ['title' => 'Cut rate · every ~2s', 'note' => 'Angle or subject changes on every cut; never two identical clips back to back.'],
                ['title' => 'Zoom punches on numbers', 'note' => 'Every stat lands with a punch-in. The rhythm trains the viewer: zoom means "this is the point."'],
            ],
            'transcript' => [
                ['time' => '0:00', 'text' => $title, 'label' => 'hook'],
                ['time' => '0:10', 'text' => 'Middle section of the fake transcript for local development.', 'label' => null],
                ['time' => '0:30', 'text' => 'Closing line that loops back to the opening claim.', 'label' => 'loop point'],
            ],
        ];
    }

    private static function fakeTranscript(): array
    {
        $segments = [
            ['text' => 'This is a canned transcript for local development.', 'startMs' => 0, 'endMs' => 3000],
            ['text' => 'Set USE_LIVE_IN_APIS_DEVELOPMENT=true to transcribe a real video.', 'startMs' => 3000, 'endMs' => 6500],
        ];

        return ['text' => implode(' ', array_column($segments, 'text')), 'segments' => $segments, 'language' => 'en', 'has_speech' => true];
    }

    private function loadPrompt(string $filename, array $replacements): string
    {
        $path = app_path("Prompts/{$filename}");
        if (! file_exists($path)) {
            throw new \RuntimeException("Prompt file not found: {$path}");
        }

        $template = file_get_contents($path);
        foreach ($replacements as $key => $value) {
            $template = str_replace('{'.$key.'}', $value, $template);
        }

        return $template;
    }
}
