<?php

namespace App\Services;

use App\Exceptions\TranscriptUnavailableException;
use App\Models\Transcript;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for CaptAPI (https://api.captapi.com) transcript endpoints. One class
 * handles all platforms — the platform just swaps the URL segment
 * (/v1/{platform}/transcript). Results are cached in the `transcripts` table so a
 * repeat request for the same URL reads from the DB instead of spending API credits.
 */
class CaptApiService
{
    public const PLATFORMS = ['youtube', 'tiktok', 'instagram'];

    /** Base domains used to reject obviously-wrong links before spending credits. */
    private const HOSTS = [
        'youtube' => ['youtube.com', 'youtu.be'],
        'tiktok' => ['tiktok.com'],
        'instagram' => ['instagram.com', 'instagr.am'],
    ];

    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('services.captapi.api_key');
        $this->baseUrl = rtrim((string) config('services.captapi.base_url'), '/');
        $this->timeout = max(1, (int) config('services.captapi.transcript_timeout', 60));
    }

    public function isSupported(string $platform): bool
    {
        return in_array($platform, self::PLATFORMS, true);
    }

    /**
     * Return a transcript for the given platform + URL, from the DB cache if we
     * already have it, otherwise from CaptAPI (and cached for next time).
     *
     * @return array{transcript: Transcript, cached: bool}
     *
     * @throws TranscriptUnavailableException when the provider has no transcript for the video (404)
     * @throws ConnectionException when CaptAPI is unreachable
     *                             or exceeds the configured timeout
     */
    public function getTranscript(string $platform, string $url): array
    {
        $platform = strtolower(trim($platform));
        $url = trim($url);

        if (! $this->isSupported($platform)) {
            throw new \InvalidArgumentException("Unsupported platform: {$platform}.");
        }
        $this->assertUrlMatchesPlatform($platform, $url);

        $hash = hash('sha256', $platform.'|'.$url);

        $existing = Transcript::where('platform', $platform)->where('url_hash', $hash)->first();
        if ($existing) {
            return ['transcript' => $existing, 'cached' => true];
        }

        if ($this->apiKey === '') {
            throw new \RuntimeException('Transcript service is not configured.');
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout)
            ->get("{$this->baseUrl}/{$platform}/transcript", ['url' => $url]);

        if (! $response->successful() || $response->json('success') !== true) {
            // CaptAPI errors are objects ({code, message}); older responses may be strings.
            $error = $response->json('error');
            $message = is_array($error) ? ($error['message'] ?? null) : $error;
            $message = is_string($message) && $message !== '' ? $message : null;

            // 404 = the video has no captions. Expected, user-facing, not reported.
            if ($response->status() === 404) {
                Log::info('CaptAPI has no transcript for this video', ['platform' => $platform, 'message' => $message]);
                throw new TranscriptUnavailableException($message);
            }

            Log::warning('CaptAPI transcript request failed', [
                'platform' => $platform,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException($message ?? 'Could not fetch a transcript for that link. Please check the URL and try again.');
        }

        $data = $response->json('data') ?? [];

        try {
            $transcript = Transcript::create([
                'platform' => $platform,
                'source_url' => $url,
                'url_hash' => $hash,
                'text' => $data['text'] ?? '',
                'segments' => $data['segments'] ?? [],
                'language' => $data['language'] ?? null,
                'provider_fetched_at' => isset($data['fetchedAt']) ? Carbon::parse($data['fetchedAt']) : now(),
                'request_id' => $response->json('requestId'),
                'credits_used' => $response->json('creditsUsed'),
            ]);
        } catch (QueryException $e) {
            // Concurrent request already cached it — return that row.
            $transcript = Transcript::where('platform', $platform)->where('url_hash', $hash)->first();
            if (! $transcript) {
                throw $e;
            }

            return ['transcript' => $transcript, 'cached' => true];
        }

        return ['transcript' => $transcript, 'cached' => false];
    }

    /** Reject links whose host doesn't belong to the requested platform (saves credits). */
    private function assertUrlMatchesPlatform(string $platform, string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::HOSTS[$platform] ?? [] as $base) {
            if ($host === $base || str_ends_with($host, '.'.$base)) {
                return;
            }
        }
        throw new \InvalidArgumentException("That doesn't look like a valid {$platform} link.");
    }
}
