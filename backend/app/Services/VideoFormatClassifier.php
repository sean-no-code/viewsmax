<?php

namespace App\Services;

use App\Models\OutlierVideo;
use App\Services\Exceptions\ProbeRateLimited;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether an outlier video is a Short (vertical short-form) or long-form.
 *
 * Duration is NOT the answer — YouTube Shorts run up to 3 minutes and a 2-minute
 * landscape upload is not a Short. YouTube exposes the real answer through its
 * own URL: HEAD https://www.youtube.com/shorts/{id} returns 200 for a Short and
 * 303 → /watch?v={id} for everything else (404 for unknown ids). No API key, no
 * quota. TikTok and Instagram are short-form by definition.
 */
class VideoFormatClassifier
{
    public const PROBE_URL = 'https://www.youtube.com/shorts/%s';

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /** Anonymous "consent accepted" cookies YouTube honours (the same ones yt-dlp sends). */
    public const CONSENT_COOKIE = 'SOCS=CAI; CONSENT=YES+cb';

    /** true = Short, false = long-form, null = unknown (leave for the duration fallback). */
    public function classify(string $platform, string $videoId): ?bool
    {
        if (OutlierVideo::platformIsAlwaysShort($platform)) {
            return true;
        }

        return $platform === 'youtube' ? $this->probeYouTube($videoId) : null;
    }

    /**
     * @throws ProbeRateLimited when YouTube is throttling/bot-checking us — the
     *                          caller should stop the batch, not record "long".
     */
    public function probeYouTube(string $videoId): ?bool
    {
        if (! config('services.youtube.format_probe_enabled', true)) {
            return null;
        }

        try {
            $response = Http::withoutRedirecting()
                ->timeout(5)
                ->connectTimeout(3)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'en-US,en;q=0.9',
                    // Pre-accepted cookie consent: without it, UK/EU egress IPs get
                    // a deterministic redirect to consent.youtube.com instead of an answer.
                    'Cookie' => self::CONSENT_COOKIE,
                ])
                ->head(sprintf(self::PROBE_URL, $videoId));
        } catch (ConnectionException $e) {
            Log::info('[shorts-probe] connection failed', ['video_id' => $videoId, 'error' => $e->getMessage()]);

            return null;
        }

        $status = $response->status();

        if ($status === 200) {
            return true;
        }

        if ($status >= 300 && $status < 400) {
            $location = (string) $response->header('Location');
            if (str_contains($location, '/watch')) {
                return false;
            }
            // The consent wall is a fixed property of the egress region, not throttling:
            // retrying never clears it. Record "unknown" (stamps format_checked_at) so
            // the batch keeps moving and the daily backfill re-probes it later.
            if (str_contains($location, 'consent.youtube.com')) {
                Log::warning('[shorts-probe] consent wall despite cookie — recording unknown', ['video_id' => $videoId]);

                return null;
            }
            // /sorry/ and other interstitials — a bot check, not an answer.
            throw new ProbeRateLimited("YouTube redirected /shorts/{$videoId} to {$location}");
        }

        if ($status === 429 || $status >= 500) {
            throw new ProbeRateLimited("YouTube answered {$status} for /shorts/{$videoId}");
        }

        return null; // 404 (deleted/private) and other 4xx: unknown
    }

    /**
     * Classify once and persist. No-op for rows already classified; stamps
     * format_checked_at even when the answer is unknown so backfills don't
     * re-probe the same 404 forever.
     *
     * @throws ProbeRateLimited
     */
    public function ensureClassified(OutlierVideo $video): ?bool
    {
        if ($video->is_short !== null) {
            return $video->is_short;
        }

        $result = $this->classify($video->platform, $video->youtube_video_id);

        $video->forceFill(['is_short' => $result, 'format_checked_at' => now()])->save();

        return $result;
    }
}
