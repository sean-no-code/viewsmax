<?php

namespace App\Services;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ingests TikTok / Instagram outlier videos via CaptAPI, reusing the same CAPTAPIKEY as
 * the transcript tools. Stored into the shared outlier tables with platform = tiktok|instagram.
 *
 * Two entry points:
 *  - fetchAndStore(): one video by URL (the paste-a-link flow) via the per-video detail endpoints.
 *  - ingestChannel(): a creator's recent videos by @handle via channel-details + channel-posts /
 *    channel-reels. The batch is scored against its own median so the channel gets a real
 *    baseline immediately; the channel row keeps that median for later single-video adds.
 *
 * TikTok gives views + likes + comments + follower count + duration. Instagram's per-video
 * endpoint is sometimes sparse (no views), so its fields are written only when present.
 */
class CaptApiOutlierService
{
    private const PATHS = [
        'tiktok' => '/tiktok/video-details',
        'instagram' => '/instagram/details',
    ];

    private const CHANNEL_PATHS = [
        'tiktok' => ['details' => '/tiktok/channel-details', 'posts' => '/tiktok/channel-posts'],
        'instagram' => ['details' => '/instagram/channel-details', 'posts' => '/instagram/channel-reels'],
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

        // Instagram scrapes regularly take 60–100s at the provider; 60s guaranteed-kills them.
        // Instagram's graphql path also times out sometimes and the provider falls back
        // to an Open-Graph scrape with no views/followers/caption — retry once for a
        // full response rather than ingesting a hollow row.
        $attempts = $platform === 'instagram' ? 2 : 1;
        $data = [];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $data = $this->get(self::PATHS[$platform], ['url' => trim($url)], 'Could not fetch that video. Check the URL and try again.');
            if (! self::isSparseInstagram($platform, $data) || $attempt === $attempts) {
                break;
            }
        }

        return $platform === 'tiktok' ? $this->storeTiktok($data) : $this->storeInstagram($data);
    }

    /**
     * Pull a creator's recent videos into the outlier DB by @handle. Returns the
     * channel row and the native ids that were (up)serted. Throws exact-class
     * RuntimeException / InvalidArgumentException with user-safe messages.
     *
     * @return array{channel: OutlierChannel, video_ids: string[]}
     */
    public function ingestChannel(string $platform, string $handle, int $limit = 10): array
    {
        $platform = strtolower(trim($platform));
        if (! isset(self::CHANNEL_PATHS[$platform])) {
            throw new \InvalidArgumentException("Unsupported outlier platform: {$platform}.");
        }
        $handle = OutlierChannel::normalizeHandle($handle);
        if ($handle === null) {
            throw new \InvalidArgumentException('Enter a channel @handle.');
        }

        // Profile stats are nice-to-have (the posts carry an author block too), so a
        // details miss must not block the import; the posts call is the one that matters.
        try {
            $details = $this->fetchChannelDetails($platform, $handle);
        } catch (\RuntimeException $e) {
            Log::info('[channel-ingest] channel-details unavailable, using post author data', [
                'platform' => $platform, 'handle' => $handle, 'error' => $e->getMessage(),
            ]);
            $details = [];
        }

        $posts = $this->fetchChannelPosts($platform, $handle, $limit);
        if ($posts === []) {
            throw new \RuntimeException('That channel has no public videos to import.');
        }

        return $this->storeChannelBatch($platform, $handle, $details, $posts);
    }

    /** Profile-level stats for a creator (followers, post count, avatar, display name). */
    public function fetchChannelDetails(string $platform, string $handle): array
    {
        return $this->get(
            self::CHANNEL_PATHS[$platform]['details'],
            ['url' => '@'.$handle],
            'Could not load that channel. Check the handle and try again.',
        );
    }

    /**
     * The creator's most recent videos, newest first. CaptAPI wraps the list in
     * `data.items` (with nextCursor/hasMore); tolerate a bare list too.
     *
     * @return array<int, array>
     */
    public function fetchChannelPosts(string $platform, string $handle, int $limit = 10): array
    {
        $data = $this->get(
            self::CHANNEL_PATHS[$platform]['posts'],
            ['url' => '@'.$handle, 'limit' => max(1, min($limit, 200))],
            'Could not load that channel\'s videos. Check the handle and try again.',
        );

        $items = $data['items'] ?? $data['posts'] ?? $data['reels'] ?? $data['videos'] ?? (array_is_list($data) ? $data : []);

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * Upsert a channel + its batch of videos. The batch median becomes the
     * channel's average_views, every video in the batch is scored against it,
     * and any earlier rows for the channel are re-scored so the whole channel
     * shares one baseline.
     *
     * @return array{channel: OutlierChannel, video_ids: string[]}
     */
    public function storeChannelBatch(string $platform, string $handle, array $details, array $posts): array
    {
        $channel = $this->upsertChannel($platform, $handle, $details, $posts[0]['author'] ?? []);

        $viewsOf = fn (array $p) => isset($p['engagement']['views']) ? (int) $p['engagement']['views'] : null;
        $knownViews = collect($posts)->map($viewsOf)->filter(fn ($v) => $v !== null);
        $median = $knownViews->isNotEmpty() ? (int) $knownViews->median() : null;

        $ids = [];
        foreach ($posts as $post) {
            $videoId = $this->videoIdFrom($platform, $post);
            if ($videoId === '') {
                continue;
            }
            $ids[] = $videoId;

            $attrs = $this->captVideoAttrs($platform, $post) + ['channel_id' => $channel->id];
            $views = $viewsOf($post);
            if ($views !== null) {
                $attrs['views'] = $views;
                $attrs['outlier_score'] = $median && $median > 0 ? round($views / $median, 1) : 1.0;
            }

            $video = OutlierVideo::updateOrCreate(['platform' => $platform, 'youtube_video_id' => $videoId], $attrs);
            $this->ensureTitle($video, $platform, $post['author']['username'] ?? $handle);
        }

        $channel->forceFill([
            'average_views' => $median,
            'average_calculated_at' => now(),
            'average_video_ids' => $ids,
        ])->save();

        if ($median && $median > 0) {
            OutlierVideo::where('platform', $platform)->where('channel_id', $channel->id)
                ->whereNotIn('youtube_video_id', $ids)->whereNotNull('views')
                ->get()
                ->each(fn (OutlierVideo $v) => $v->forceFill(['outlier_score' => round($v->views / $median, 1)])->save());
        }

        return ['channel' => $channel->fresh(), 'video_ids' => $ids];
    }

    /**
     * The channel row's identity. Same as the single-video path so the two
     * flows share a row: TikTok by the numeric author id, Instagram by username.
     * Null when nothing in hand can identify the channel yet (TikTok before the
     * first page, with no profile stats).
     */
    private function channelKey(string $platform, array $author, array $details, string $handle): ?string
    {
        $key = $platform === 'tiktok'
            ? ($author['id'] ?? $details['id'] ?? null)
            : ($author['username'] ?? $details['username'] ?? $handle);

        return $key !== null && (string) $key !== '' ? (string) $key : null;
    }

    private function upsertChannel(string $platform, string $handle, array $details, array $author): OutlierChannel
    {
        return OutlierChannel::updateOrCreate(
            ['platform' => $platform, 'youtube_channel_id' => $this->channelKey($platform, $author, $details, $handle) ?? $handle],
            array_filter([
                'handle' => $handle,
                'channel_name' => $details['displayName'] ?? $author['displayName'] ?? $author['username'] ?? $details['handle'] ?? $handle,
                'profile_image_url' => $details['avatar'] ?? $details['profileImage'] ?? $author['avatar'] ?? $author['profileImage'] ?? null,
                'subscriber_count' => $details['followers'] ?? $author['followers'] ?? null,
                'video_count' => $details['postCount'] ?? null,
            ], fn ($v) => $v !== null),
        );
    }

    /** Authenticated GET against CaptAPI; unwraps {success, data} and surfaces provider errors. */
    private function get(string $path, array $params, string $fallback): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Outlier service is not configured.');
        }

        $response = Http::withToken($this->apiKey)->acceptJson()->timeout(120)
            ->get($this->baseUrl.$path, $params);

        if (! $response->successful() || $response->json('success') !== true) {
            // CaptAPI errors are objects ({code, message}); older responses may be strings.
            $error = $response->json('error');
            $message = is_array($error) ? ($error['message'] ?? null) : $error;
            throw new \RuntimeException(is_string($message) && $message !== '' ? $message : $fallback);
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
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
        $videoId = $this->videoIdFrom('tiktok', $d);

        $channel = OutlierChannel::updateOrCreate(
            ['platform' => 'tiktok', 'youtube_channel_id' => (string) ($author['id'] ?? $author['username'] ?? '')],
            [
                'channel_name' => $author['displayName'] ?? $author['username'] ?? 'Unknown',
                'profile_image_url' => $author['avatar'] ?? $author['profileImage'] ?? null,
                'subscriber_count' => $author['followers'] ?? null,
            ],
        );

        $views = (int) ($eng['views'] ?? 0);

        return OutlierVideo::updateOrCreate(
            ['platform' => 'tiktok', 'youtube_video_id' => $videoId],
            $this->captVideoAttrs('tiktok', $d) + [
                'channel_id' => $channel->id,
                'views' => $views,
                'outlier_score' => $this->scoreAgainstChannel($channel, $views, $videoId),
                'manually_added' => true, // A pasted URL is always a deliberate add.
            ],
        );
    }

    private function storeInstagram(array $d): OutlierVideo
    {
        $author = $d['author'] ?? [];
        $eng = $d['engagement'] ?? [];
        $videoId = $this->videoIdFrom('instagram', $d);

        $channel = OutlierChannel::updateOrCreate(
            ['platform' => 'instagram', 'youtube_channel_id' => (string) ($author['username'] ?? '')],
            array_filter([
                'channel_name' => $author['displayName'] ?? $author['username'] ?? 'Unknown',
                'profile_image_url' => $author['avatar'] ?? $author['profileImage'] ?? null,
                'subscriber_count' => $author['followers'] ?? null,
            ], fn ($v) => $v !== null),
        );

        $attrs = $this->captVideoAttrs('instagram', $d) + [
            'channel_id' => $channel->id,
            'manually_added' => true, // A pasted URL is always a deliberate add.
        ];
        // Views arrive now (they didn't originally) → score like TikTok.
        if (isset($eng['views'])) {
            $views = (int) $eng['views'];
            $attrs['views'] = $views;
            $attrs['outlier_score'] = $this->scoreAgainstChannel($channel, $views, $videoId);
        }

        // Key by the URL shortcode: CaptAPI's `id` is Instagram's numeric media pk
        // (it used to be the shortcode), but every lookup — breakdown page, ingest →
        // breakdown job chain, nativeUrl — uses the shortcode from the URL.
        $video = OutlierVideo::updateOrCreate(['platform' => 'instagram', 'youtube_video_id' => $videoId], $attrs);
        $this->ensureTitle($video, 'instagram', $author['username'] ?? null);

        return $video;
    }

    /**
     * views ÷ the channel's baseline. The channel row's average_views (set by a
     * channel ingest, or YouTube's median) is the source of truth; before one
     * exists, fall back to the mean of the videos already stored for the channel
     * (1.0 for the very first video, sharpening as more arrive).
     */
    private function scoreAgainstChannel(OutlierChannel $channel, int $views, string $excludeVideoId): float
    {
        $avg = $channel->average_views;
        if (! $avg || $avg <= 0) {
            $avg = OutlierVideo::where('platform', $channel->platform)->where('channel_id', $channel->id)
                ->where('youtube_video_id', '!=', $excludeVideoId)->avg('views');
        }

        return $avg && $avg > 0 ? round($views / $avg, 1) : 1.0;
    }

    private function videoIdFrom(string $platform, array $d): string
    {
        return (string) ($platform === 'instagram' ? ($d['shortcode'] ?? $d['id'] ?? '') : ($d['id'] ?? ''));
    }

    /**
     * Map one CaptAPI video payload (per-video or channel-list item — same
     * shape) onto OutlierVideo columns. Views and score are the caller's job.
     *
     * Instagram re-fetches (media-URL refresh) sometimes come back without
     * caption or engagement — only overwrite fields the provider actually sent,
     * so a refresh can never downgrade a good row to "Untitled"/null counts.
     * TikTok payloads are complete, so its fields are always written.
     */
    private function captVideoAttrs(string $platform, array $d): array
    {
        $eng = $d['engagement'] ?? [];
        $partial = $platform === 'instagram';

        $attrs = [
            'is_short' => true, // TikTok and Instagram reels are short-form by definition.
            'format_checked_at' => now(),
        ];

        $caption = (string) ($d['caption'] ?? '');
        if (! $partial || trim($caption) !== '') {
            $attrs['title'] = $this->titleFrom($caption);
            $attrs['description'] = $caption !== '' ? $caption : null;
        }
        if (! $partial || ! empty($d['thumbnailUrl'])) {
            $thumb = ! empty($d['thumbnailUrl'])
                ? ($this->rehostThumbnail($platform, $this->videoIdFrom($platform, $d), $d['thumbnailUrl']) ?? $d['thumbnailUrl'])
                : null;
            $attrs['thumbnail_url'] = $thumb;
            $attrs['thumbnail_medium_url'] = $thumb;
        }
        if (! $partial || isset($eng['likes'])) {
            $attrs['like_count'] = $eng['likes'] ?? null;
        }
        if (! $partial || isset($eng['comments'])) {
            $attrs['comment_count'] = $eng['comments'] ?? null;
        }
        if (! $partial || isset($d['durationSeconds'])) {
            $attrs['duration'] = $this->secondsToIso((int) ((float) ($d['durationSeconds'] ?? 0)));
        }
        if (! $partial || isset($d['publishedAt'])) {
            $attrs['published_at'] = isset($d['publishedAt']) ? Carbon::parse($d['publishedAt']) : null;
        }
        // Direct CDN media for native playback (Instagram's embed is refused for
        // most creator accounts). Signed + short-lived → refreshed on demand.
        if (! empty($d['videoUrl'])) {
            $expires = $d['videoUrlExpiresAt'] ?? $d['mediaUrlsExpireAt'] ?? null;
            $attrs['video_url'] = $d['videoUrl'];
            $attrs['video_url_expires_at'] = $expires ? Carbon::parse($expires) : null;
        }

        return $attrs;
    }

    private const THUMBNAIL_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Copy a provider CDN thumbnail onto our media disk and return its public
     * URL, or null when re-hosting is off or the download fails (the caller
     * then keeps the CDN URL). TikTok/Instagram thumbnail URLs are signed and
     * expire within days, and fbcdn sits on most tracker blocklists, so the raw
     * URL is not a reliable <img> source. Same disk and URL handling as
     * upload_media, one small object per video, overwritten on re-fetch.
     */
    public function rehostThumbnail(string $platform, string $videoId, string $url): ?string
    {
        if (! config('services.outliers.rehost_thumbnails') || $videoId === '') {
            return null;
        }
        $disk = config('filesystems.media_disk') ?: config('filesystems.default');
        if ($this->isHostedUrl($url)) {
            return $url;
        }

        try {
            $response = Http::timeout(20)->get($url);
            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! $response->successful() || ! isset(self::THUMBNAIL_MIMES[$mime]) || $response->body() === '') {
                Log::info('[outlier-thumbs] skipped', ['platform' => $platform, 'video_id' => $videoId, 'status' => $response->status(), 'mime' => $mime]);

                return null;
            }

            $path = sprintf('outliers/thumbs/%s/%s.%s', $platform, $videoId, self::THUMBNAIL_MIMES[$mime]);
            Storage::disk($disk)->put($path, $response->body());
            $hosted = Storage::disk($disk)->url($path);

            return preg_match('#^https?://#i', $hosted) ? $hosted : url($hosted);
        } catch (\Throwable $e) {
            Log::warning('[outlier-thumbs] failed', ['platform' => $platform, 'video_id' => $videoId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** True when the URL already points at our media disk (nothing to re-host). */
    public function isHostedUrl(?string $url): bool
    {
        if (! $url) {
            return false;
        }
        $disk = config('filesystems.media_disk') ?: config('filesystems.default');
        $base = rtrim((string) config("filesystems.disks.{$disk}.url"), '/');

        return ($base !== '' && str_starts_with($url, $base.'/')) || str_contains($url, '/outliers/thumbs/');
    }

    /** Instagram rows without a caption get a descriptive title instead of "Untitled". */
    private function ensureTitle(OutlierVideo $video, string $platform, ?string $handle): void
    {
        if ($platform !== 'instagram') {
            return;
        }
        if ($video->title === null || $video->title === '' || $video->title === 'Untitled') {
            $video->forceFill(['title' => $handle ? "Reel by @{$handle}" : 'Instagram reel'])->save();
        }
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
