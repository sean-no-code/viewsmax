<?php

namespace App\Support\Quota;

/**
 * What the YouTube API calls the app makes cost, and how far each tier may
 * spend. All of it is config (services.youtube.quota) and so .env.
 */
final class Resources
{
    /** @return list<string> every resource that is counted */
    public static function all(): array
    {
        return array_keys((array) config('services.youtube.quota.units', []));
    }

    /** Quota units one call costs, as Google bills them. */
    public static function units(string $resource): int
    {
        return (int) (config('services.youtube.quota.units.'.$resource) ?? 1);
    }

    /** @return list<string> resources that have tier thresholds (the gated ones) */
    public static function gated(): array
    {
        return array_keys((array) config('services.youtube.quota.thresholds', []));
    }

    /**
     * Calls of a resource the day's shared counter may reach before this tier
     * is refused. null = unlimited (no threshold, or the env value left blank).
     */
    public static function cap(string $resource, string $tier): ?int
    {
        $value = config("services.youtube.quota.thresholds.{$resource}.{$tier}");

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Fresh fetches of a resource one user of this tier may trigger per day.
     * null = unlimited (no allowance configured, or the env value left blank).
     */
    public static function userCap(string $resource, string $tier): ?int
    {
        $value = config("services.youtube.quota.per_user.{$resource}.{$tier}");

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * The resource a request to Google spends, or null when it is not a
     * YouTube API call at all.
     *
     * @return array{resource: string, endpoint: string}|null
     */
    public static function match(string $method, string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host === 'youtubeanalytics.googleapis.com') {
            return ['resource' => 'analytics_reports', 'endpoint' => $path];
        }

        if ($host !== 'www.googleapis.com') {
            return null;
        }

        if (str_starts_with($path, '/upload/youtube/v3/')) {
            $rest = substr($path, strlen('/upload/youtube/v3/'));
            if ($rest === 'videos') {
                // Only the POST that opens a resumable session is the insert; the
                // PUTs that stream chunks and probe the session cost nothing.
                return strtoupper($method) === 'POST' ? ['resource' => 'videos_insert', 'endpoint' => $path] : null;
            }
            if ($rest === 'thumbnails/set') {
                return ['resource' => 'thumbnails_set', 'endpoint' => $path];
            }

            return ['resource' => 'other', 'endpoint' => $path];
        }

        if (! str_starts_with($path, '/youtube/v3/')) {
            return null;
        }

        $resource = match (substr($path, strlen('/youtube/v3/'))) {
            'search' => 'search_list',
            'videos' => 'videos_list',
            'channels' => 'channels_list',
            'playlistItems' => 'playlist_items_list',
            'playlists' => 'playlists_list',
            default => 'other',
        };

        return ['resource' => $resource, 'endpoint' => $path];
    }
}
