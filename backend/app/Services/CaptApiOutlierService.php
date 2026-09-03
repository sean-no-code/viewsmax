<?php

namespace App\Services;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ingests TikTok / Instagram outlier videos via CaptAPI's per-video detail endpoints
 * (there is no profile/listing endpoint — videos are fetched one URL at a time), reusing
 * the same CAPTAPIKEY as the transcript tools. Stored into the shared outlier tables with
 * platform = tiktok|instagram.
 *
 *  - TikTok gives views + likes + comments + follower count + duration.
 *  - Instagram gives likes + comments only — NO views, NO follower count — so its videos
 *    carry a null view count and no views-based outlier score (comment-count only on cards).
 */
class CaptApiOutlierService
{
    private const PATHS = [
        'tiktok' => '/tiktok/video-details',
        'instagram' => '/instagram/details',
    ];

    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.captapi.api_key');
        $this->baseUrl = rtrim((string) config('services.captapi.base_url'), '/');
    }

    /**
     * The canonical video id inside a full TikTok/Instagram video URL, or null
     * (e.g. vm.tiktok.com short links carry no id — those need a live fetch).
     * Matches the id CaptAPI reports, which is what we store as youtube_video_id.
     */
    public static function extractVideoId(string $platform, string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if ($platform === 'tiktok') {
            return str_ends_with($host, 'tiktok.com') && preg_match('#/video/(\d+)#', $path, $m) ? $m[1] : null;
        }

        if ($platform === 'instagram') {
            $igHost = str_ends_with($host, 'instagram.com') || str_ends_with($host, 'instagr.am');

            return $igHost && preg_match('#/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#', $path, $m) ? $m[1] : null;
        }

        return null;
    }

    /** Hosts whose only job is to redirect to the canonical TikTok video URL. */
    private const TIKTOK_SHORT_HOSTS = ['vm.tiktok.com', 'vt.tiktok.com'];

    /**
     * Resolves a TikTok share short-link (vm.tiktok.com/…, vt.tiktok.com/…,
     * tiktok.com/t/…) to its canonical /@user/video/{id} URL by following the
     * redirect manually — CaptAPI rejects short links outright. Hosts are
     * allow-listed and redirects leaving tiktok.com are refused (no SSRF via
     * arbitrary user URLs). Returns null when it doesn't resolve.
     */
    public static function resolveTiktokShortLink(string $url): ?string
    {
        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $isShort = in_array($host, self::TIKTOK_SHORT_HOSTS, true)
            || (str_ends_with($host, 'tiktok.com') && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/t/'));
        if (! $isShort) {
            return null;
        }

        $current = $url;
        for ($hop = 0; $hop < 3; $hop++) {
            try {
                $response = Http::timeout(10)->withoutRedirecting()->get($current);
            } catch (\Throwable) {
                return null;
            }

            $location = (string) $response->header('Location');
            if ($location === '') {
                return null;
            }
            if (! str_starts_with($location, 'http')) {
                $location = 'https://'.$host.$location;
            }

            $locationHost = strtolower((string) parse_url($location, PHP_URL_HOST));
            if (! str_ends_with($locationHost, 'tiktok.com')) {
                return null;
            }
            if (self::extractVideoId('tiktok', $location) !== null) {
                return $location;
            }
            $current = $location;
            $host = $locationHost;
        }

        return null;
    }

    /** Fetch a TikTok/Instagram video by URL, normalize it, and upsert it as an outlier. */
    public function fetchAndStore(string $platform, string $url): OutlierVideo
    {
        $platform = strtolower(trim($platform));
        if (! isset(self::PATHS[$platform])) {
            throw new \InvalidArgumentException("Unsupported outlier platform: {$platform}.");
        }
        if ($this->apiKey === '') {
            throw new \RuntimeException('Outlier service is not configured.');
        }

        // Instagram scrapes regularly take 60–100s at the provider; 60s guaranteed-kills them.
        // Instagram's graphql path also times out sometimes and the provider falls back
        // to an Open-Graph scrape with no views/followers/caption — retry once for a
        // full response rather than ingesting a hollow row.
        $attempts = $platform === 'instagram' ? 2 : 1;
        $data = [];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = Http::withToken($this->apiKey)->acceptJson()->timeout(120)
                ->get($this->baseUrl.self::PATHS[$platform], ['url' => trim($url)]);

            if (! $response->successful() || $response->json('success') !== true) {
                // CaptAPI errors are objects ({code, message}); older responses may be strings.
                $error = $response->json('error');
                $message = is_array($error) ? ($error['message'] ?? null) : $error;
                throw new \RuntimeException(is_string($message) && $message !== ''
                    ? $message
                    : 'Could not fetch that video. Check the URL and try again.');
            }

            $data = $response->json('data') ?? [];
            if (! self::isSparseInstagram($platform, $data) || $attempt === $attempts) {
                break;
            }
        }

        return $platform === 'tiktok' ? $this->storeTiktok($data) : $this->storeInstagram($data);
    }

    /** Instagram fallback-scrape responses: no views and no caption — worth one retry. */
    private static function isSparseInstagram(string $platform, array $d): bool
    {
        return $platform === 'instagram'
            && ! isset($d['engagement']['views'])
            && trim((string) ($d['caption'] ?? '')) === '';
    }

    private function storeTiktok(array $d): OutlierVideo
    {
        $author = $d['author'] ?? [];
        $eng = $d['engagement'] ?? [];

        $channel = OutlierChannel::updateOrCreate(
            ['platform' => 'tiktok', 'youtube_channel_id' => (string) ($author['id'] ?? $author['username'] ?? '')],
            [
                'channel_name' => $author['displayName'] ?? $author['username'] ?? 'Unknown',
                'profile_image_url' => $author['avatar'] ?? $author['profileImage'] ?? null,
                'subscriber_count' => $author['followers'] ?? null,
            ],
        );

        $views = (int) ($eng['views'] ?? 0);
        // Score = views vs the channel's average across the TikTok videos we've stored
        // for it (starts at 1.0 for the first video, sharpens as more are ingested).
        $avg = OutlierVideo::where('platform', 'tiktok')->where('channel_id', $channel->id)->avg('views');
        $score = $avg && $avg > 0 ? round($views / $avg, 1) : 1.0;

        return OutlierVideo::updateOrCreate(
            ['platform' => 'tiktok', 'youtube_video_id' => (string) ($d['id'] ?? '')],
            [
                'channel_id' => $channel->id,
                'title' => $this->titleFrom($d['caption'] ?? ''),
                'description' => $d['caption'] ?? null,
                'thumbnail_url' => $d['thumbnailUrl'] ?? null,
                'thumbnail_medium_url' => $d['thumbnailUrl'] ?? null,
                'views' => $views,
                'like_count' => $eng['likes'] ?? null,
                'comment_count' => $eng['comments'] ?? null,
                'outlier_score' => $score,
                'duration' => $this->secondsToIso((int) ((float) ($d['durationSeconds'] ?? 0))),
                'published_at' => isset($d['publishedAt']) ? Carbon::parse($d['publishedAt']) : null,
                'manually_added' => true, // CaptAPI ingest is per-URL only — always a deliberate add.
                'is_short' => true, // TikTok is short-form by definition.
                'format_checked_at' => now(),
            ],
        );
    }

    private function storeInstagram(array $d): OutlierVideo
    {
        $author = $d['author'] ?? [];
        $eng = $d['engagement'] ?? [];

        $channel = OutlierChannel::updateOrCreate(
            ['platform' => 'instagram', 'youtube_channel_id' => (string) ($author['username'] ?? '')],
            array_filter([
                'channel_name' => $author['displayName'] ?? $author['username'] ?? 'Unknown',
                'profile_image_url' => $author['avatar'] ?? $author['profileImage'] ?? null,
                'subscriber_count' => $author['followers'] ?? null,
            ], fn ($v) => $v !== null),
        );

        // Views arrive now (they didn't originally) → score like TikTok: views vs the
        // channel's average across the Instagram videos we've stored for it.
        $views = isset($eng['views']) ? (int) $eng['views'] : null;
        $score = null;
        if ($views !== null) {
            $avg = OutlierVideo::where('platform', 'instagram')->where('channel_id', $channel->id)
                ->where('youtube_video_id', '!=', (string) ($d['shortcode'] ?? $d['id'] ?? ''))->avg('views');
            $score = $avg && $avg > 0 ? round($views / $avg, 1) : 1.0;
        }

        // Re-fetches (media-URL refresh) sometimes come back without caption or
        // engagement — only overwrite fields the provider actually sent, so a
        // refresh can never downgrade a good row to "Untitled"/null counts.
        $attrs = [
            'channel_id' => $channel->id,
            'manually_added' => true, // CaptAPI ingest is per-URL only — always a deliberate add.
            'is_short' => true, // Instagram reels are short-form by definition.
            'format_checked_at' => now(),
        ];
        if ($views !== null) {
            $attrs['views'] = $views;
            $attrs['outlier_score'] = $score;
        }
        if (trim((string) ($d['caption'] ?? '')) !== '') {
            $attrs['title'] = $this->titleFrom($d['caption']);
            $attrs['description'] = $d['caption'];
        }
        if (! empty($d['thumbnailUrl'])) {
            $attrs['thumbnail_url'] = $d['thumbnailUrl'];
            $attrs['thumbnail_medium_url'] = $d['thumbnailUrl'];
        }
        if (isset($eng['likes'])) {
            $attrs['like_count'] = $eng['likes'];
        }
        if (isset($eng['comments'])) {
            $attrs['comment_count'] = $eng['comments'];
        }
        if (isset($d['durationSeconds'])) {
            $attrs['duration'] = $this->secondsToIso((int) ((float) $d['durationSeconds']));
        }
        if (isset($d['publishedAt'])) {
            $attrs['published_at'] = Carbon::parse($d['publishedAt']);
        }
        // Direct CDN media for native playback (Instagram's embed is refused for
        // most creator accounts). Signed + short-lived → refreshed on demand.
        if (! empty($d['videoUrl'])) {
            $attrs['video_url'] = $d['videoUrl'];
            $attrs['video_url_expires_at'] = isset($d['mediaUrlsExpireAt']) ? Carbon::parse($d['mediaUrlsExpireAt']) : null;
        }

        // Key by the URL shortcode: CaptAPI's `id` is Instagram's numeric media pk
        // (it used to be the shortcode), but every lookup — breakdown page, ingest →
        // breakdown job chain, nativeUrl — uses the shortcode from the URL.
        $video = OutlierVideo::updateOrCreate(
            ['platform' => 'instagram', 'youtube_video_id' => (string) ($d['shortcode'] ?? $d['id'] ?? '')],
            $attrs,
        );
        if ($video->title === null || $video->title === '' || $video->title === 'Untitled') {
            $handle = $author['username'] ?? null;
            $video->forceFill(['title' => $handle ? "Reel by @{$handle}" : 'Instagram reel'])->save();
        }

        return $video;
    }

    /** First line of the caption, trimmed to a card-friendly length. */
    private function titleFrom(string $caption): string
    {
        $firstLine = trim(strtok($caption, "\n") ?: $caption);

        return Str::limit($firstLine !== '' ? $firstLine : 'Untitled', 200);
    }

    private function secondsToIso(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'PT0S';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return 'PT'.($h ? "{$h}H" : '').($m ? "{$m}M" : '').($s ? "{$s}S" : '');
    }
}
