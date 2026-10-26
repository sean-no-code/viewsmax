<?php

namespace App\Services;

use App\Exceptions\TranscriptUnavailableException;
use App\Models\Transcript;
use App\Support\VideoSource;
use Illuminate\Database\QueryException;

/**
 * Transcripts for the free tools (YouTube / TikTok / Instagram): Gemini
 * listens to the video itself. Results are cached in the `transcripts` table
 * so a repeat request for the same URL reads from the DB instead of paying
 * for the video again.
 *
 * YouTube goes to Gemini by URL. Instagram needs the file: one CaptAPI details
 * call gives a downloadable link, without storing any outlier rows (this
 * endpoint is public). TikTok has no obtainable file, so CaptAPI's own
 * transcript endpoint is used instead.
 */
class TranscriptService
{
    public const PLATFORMS = ['youtube', 'tiktok', 'instagram'];

    /** Base domains used to reject obviously-wrong links before spending anything. */
    private const HOSTS = [
        'youtube' => ['youtube.com', 'youtu.be'],
        'tiktok' => ['tiktok.com'],
        'instagram' => ['instagram.com', 'instagr.am'],
    ];

    public function __construct(
        private GeminiVideoService $gemini,
        private CaptApiOutlierService $captApiOutliers,
    ) {}

    public function isSupported(string $platform): bool
    {
        return in_array($platform, self::PLATFORMS, true);
    }

    /**
     * Return a transcript for the given platform + URL, from the DB cache if we
     * already have it, otherwise from Gemini (and cached for next time).
     *
     * @return array{transcript: Transcript, cached: bool}
     *
     * @throws \InvalidArgumentException for an unsupported platform or a link that doesn't match it
     * @throws TranscriptUnavailableException when the video has no spoken audio
     * @throws \App\Exceptions\GeminiVideoException when Gemini can't access or analyse the video
     * @throws \RuntimeException when CaptAPI has no downloadable file for a TikTok / Instagram video
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

        if ($platform === 'tiktok') {
            // No TikTok media file is obtainable for Gemini; CaptAPI transcribes the audio itself.
            $result = $this->captApiOutliers->fetchTranscript('tiktok', CaptApiOutlierService::resolveTiktokShortLink($url) ?? $url);
            if (trim($result['text']) === '') {
                throw new TranscriptUnavailableException('No spoken audio was detected in this video.');
            }
        } else {
            $result = $this->gemini->transcribe($platform, $url, $this->source($platform, $url));

            // Music-only / silent: the provider's answer, shown to the user, not cached
            // (a retry is cheap and the video may get a voice-over later).
            if (! $result['has_speech']) {
                throw new TranscriptUnavailableException('No spoken audio was detected in this video.');
            }
        }

        try {
            $transcript = Transcript::create([
                'platform' => $platform,
                'source_url' => $url,
                'url_hash' => $hash,
                'text' => $result['text'],
                'segments' => $result['segments'],
                'language' => $result['language'],
                'provider_fetched_at' => now(),
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

    private function source(string $platform, string $url): VideoSource
    {
        if ($platform === 'youtube') {
            // youtu.be / shorts / embed links all become the canonical watch URL Google fetches.
            $id = YouTubeChannelService::extractVideoId($url);
            if ($id === null) {
                throw new \InvalidArgumentException("That doesn't look like a valid youtube link.");
            }

            return VideoSource::youtube($id);
        }

        return VideoSource::remote($this->captApiOutliers->fetchMediaUrl($platform, $url)['url']);
    }

    /** Reject links whose host doesn't belong to the requested platform. */
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
