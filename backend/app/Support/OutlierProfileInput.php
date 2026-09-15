<?php

namespace App\Support;

use App\Models\OutlierChannel;
use App\Services\CaptApiOutlierService;
use App\Services\YouTubeChannelService;

/**
 * Turns what a user typed into the "add a creator channel" box — a profile URL
 * or a bare @handle — into {platform, handle, kind}. Pure; no network.
 *
 *  kind = handle      → @handle (all platforms)
 *  kind = channel_id  → YouTube /channel/UC… id, no resolution needed
 *  kind = username    → YouTube legacy /user/… name (forUsername lookup)
 *
 * Video links are rejected on purpose: those go through the existing
 * fetch-by-URL flow, and sending a video URL to a channel endpoint would spend
 * a provider credit on a guaranteed miss.
 */
class OutlierProfileInput
{
    public const PLATFORMS = ['youtube', 'tiktok', 'instagram'];

    /** Instagram path segments that are app pages, never usernames. */
    private const INSTAGRAM_RESERVED = ['p', 'reel', 'reels', 'tv', 'explore', 'stories', 'accounts', 'direct'];

    /**
     * @return array{platform: string, handle: string, kind: string}
     */
    public static function parse(?string $platform, string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Enter a channel URL or @handle.');
        }

        $platform = $platform !== null ? strtolower(trim($platform)) : null;
        if ($platform !== null && ! in_array($platform, self::PLATFORMS, true)) {
            throw new \InvalidArgumentException('Platform must be youtube, tiktok or instagram.');
        }

        $asUrl = str_starts_with($input, 'www.') ? 'https://'.$input : $input;
        if (preg_match('#^https?://#i', $asUrl)) {
            return self::parseUrl($asUrl);
        }

        // Bare handle — we can only route it with an explicit platform.
        if ($platform === null) {
            throw new \InvalidArgumentException('Tell me which platform that @handle is on (youtube, tiktok or instagram).');
        }

        $handle = self::cleanHandle($input);
        if ($handle === null) {
            throw new \InvalidArgumentException("That doesn't look like a valid @handle.");
        }

        if ($platform === 'youtube' && preg_match('/^UC[\w-]{20,}$/', $input)) {
            return ['platform' => 'youtube', 'handle' => $input, 'kind' => 'channel_id'];
        }

        return ['platform' => $platform, 'handle' => $handle, 'kind' => 'handle'];
    }

    private static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '/';

        if (str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtu.be')) {
            if (YouTubeChannelService::extractVideoId($url) !== null) {
                throw new \InvalidArgumentException("That's a video link — paste it in the search box to add just that video.");
            }
            if (preg_match('#^/@([^/?]+)#', $path, $m)) {
                return ['platform' => 'youtube', 'handle' => self::requireHandle($m[1]), 'kind' => 'handle'];
            }
            if (preg_match('#^/channel/(UC[\w-]+)#', $path, $m)) {
                return ['platform' => 'youtube', 'handle' => $m[1], 'kind' => 'channel_id'];
            }
            if (preg_match('#^/user/([^/?]+)#', $path, $m)) {
                return ['platform' => 'youtube', 'handle' => self::requireHandle($m[1]), 'kind' => 'username'];
            }
            if (preg_match('#^/c/#', $path)) {
                throw new \InvalidArgumentException("Custom /c/ links can't be resolved — use the channel's @handle URL instead.");
            }
            throw new \InvalidArgumentException("That doesn't look like a YouTube channel link.");
        }

        if (str_ends_with($host, 'tiktok.com')) {
            if (CaptApiOutlierService::extractVideoId('tiktok', $url) !== null) {
                throw new \InvalidArgumentException("That's a video link — paste it in the search box to add just that video.");
            }
            if (preg_match('#^/@([^/?]+)#', $path, $m)) {
                return ['platform' => 'tiktok', 'handle' => self::requireHandle($m[1]), 'kind' => 'handle'];
            }
            throw new \InvalidArgumentException("That doesn't look like a TikTok profile link.");
        }

        if (str_ends_with($host, 'instagram.com') || str_ends_with($host, 'instagr.am')) {
            if (CaptApiOutlierService::extractVideoId('instagram', $url) !== null) {
                throw new \InvalidArgumentException("That's a video link — paste it in the search box to add just that video.");
            }
            $first = explode('/', trim($path, '/'))[0] ?? '';
            if ($first === '' || in_array(strtolower($first), self::INSTAGRAM_RESERVED, true)) {
                throw new \InvalidArgumentException("That doesn't look like an Instagram profile link.");
            }

            return ['platform' => 'instagram', 'handle' => self::requireHandle($first), 'kind' => 'handle'];
        }

        throw new \InvalidArgumentException('Paste a YouTube, TikTok or Instagram channel link.');
    }

    private static function requireHandle(string $raw): string
    {
        $handle = self::cleanHandle(rawurldecode($raw));
        if ($handle === null) {
            throw new \InvalidArgumentException("That doesn't look like a valid @handle.");
        }

        return $handle;
    }

    private static function cleanHandle(string $raw): ?string
    {
        $handle = OutlierChannel::normalizeHandle($raw);

        return $handle !== null && preg_match('/^[\w.\-]{1,100}$/u', $handle) ? $handle : null;
    }
}
