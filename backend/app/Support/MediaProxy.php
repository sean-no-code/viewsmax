<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Builds signed, expiring URLs that proxy post media from R2 through OUR domain.
 *
 * TikTok/Instagram PULL_FROM_URL only fetch media from a domain we've verified
 * with them — the raw Cloudflare R2 public URL is not that domain, so TikTok
 * rejects a slideshow whose photos point at R2. These URLs live on APP_URL
 * (the verified domain) and carry a temporary signature: since TikTok/Instagram
 * fetch server-side they can't send an auth header, so the HMAC signature IS the
 * access control — only links ViewsMax generated (and not yet expired) resolve.
 */
class MediaProxy
{
    /** How long a proxy link stays fetchable — long enough for TikTok's async pull + retries. */
    public const TTL_SECONDS = 21600; // 6h

    /** Encode a disk + object path into one URL-safe, opaque ref. */
    public static function ref(string $path, ?string $disk = null): string
    {
        $disk = $disk ?: (config('filesystems.media_disk') ?: config('filesystems.default'));

        return rtrim(strtr(base64_encode($disk.'|'.$path), '+/', '-_'), '=');
    }

    /** Decode a ref back into [disk, path], or null when it's malformed. */
    public static function decode(string $ref): ?array
    {
        $raw = base64_decode(strtr($ref, '-_', '+/'), true);
        if ($raw === false || ! str_contains($raw, '|')) {
            return null;
        }

        [$disk, $path] = explode('|', $raw, 2);
        if ($disk === '' || $path === '') {
            return null;
        }

        return [$disk, $path];
    }

    /** A signed, expiring URL on our domain that streams the given media object. */
    public static function url(string $path, ?string $disk = null): string
    {
        return URL::temporarySignedRoute('media.download', now()->addSeconds(self::TTL_SECONDS), [
            'ref' => self::ref($path, $disk),
        ]);
    }
}
