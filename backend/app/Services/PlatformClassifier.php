<?php

namespace App\Services;

/**
 * Maps an inbound HTTP referrer (or a utm_source) to a coarse traffic platform,
 * so untagged/organic social traffic gets a real source label instead of the
 * honour-system `placement` on the tracking link. The `?trk=` param remains the
 * authoritative attribution key; this is the fallback that makes origin visible.
 */
class PlatformClassifier
{
    /**
     * Registrable-domain => platform key. Matched by exact host or subdomain
     * suffix (host === domain OR ends with ".$domain"), so "m.youtube.com"
     * resolves to youtube while "reddit.com" never collides with "t.co".
     */
    private const DOMAIN_MAP = [
        'instagram.com' => 'instagram',
        'tiktok.com' => 'tiktok',
        'youtube.com' => 'youtube',
        'youtube-nocookie.com' => 'youtube',
        'youtu.be' => 'youtube',
        'twitter.com' => 'x',
        'x.com' => 'x',
        't.co' => 'x',
        'linkedin.com' => 'linkedin',
        'lnkd.in' => 'linkedin',
        'facebook.com' => 'facebook',
        'fb.me' => 'facebook',
        'fb.com' => 'facebook',
        'threads.net' => 'threads',
        'threads.com' => 'threads',
        'reddit.com' => 'reddit',
        'bing.com' => 'bing',
        'duckduckgo.com' => 'duckduckgo',
    ];

    /**
     * Classify a referrer URL/host. Returns a platform key, 'direct' when the
     * referrer is empty, or 'other' for an unrecognised external referrer.
     */
    public static function fromReferrer(?string $referrer): string
    {
        $referrer = trim((string) $referrer);
        if ($referrer === '') {
            return 'direct';
        }

        $host = strtolower((string) (parse_url($referrer, PHP_URL_HOST) ?: $referrer));
        // Strip a leading "www." so www.youtube.com matches youtube.com.
        $host = preg_replace('/^www\./', '', $host);

        foreach (self::DOMAIN_MAP as $domain => $platform) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $platform;
            }
        }

        // Search engines span many ccTLDs (google.co.uk, google.de …).
        if (preg_match('/(^|\.)google\./', $host)) {
            return 'google';
        }
        if (preg_match('/(^|\.)pinterest\./', $host)) {
            return 'pinterest';
        }

        return 'other';
    }

    /**
     * Best-effort platform for a click: prefer an explicit utm_source we
     * recognise, else classify the referrer. Never returns null so every click
     * carries an origin label ('direct' when nothing is known).
     */
    public static function forClick(?string $referrer, ?string $utmSource = null): string
    {
        $utm = strtolower(trim((string) $utmSource));
        if ($utm !== '') {
            // Normalise common utm_source spellings onto our platform keys.
            $aliases = [
                'ig' => 'instagram', 'insta' => 'instagram', 'instagram' => 'instagram',
                'tt' => 'tiktok', 'tiktok' => 'tiktok',
                'yt' => 'youtube', 'youtube' => 'youtube',
                'twitter' => 'x', 'x' => 'x',
                'li' => 'linkedin', 'linkedin' => 'linkedin',
                'fb' => 'facebook', 'facebook' => 'facebook', 'meta' => 'facebook',
                'threads' => 'threads',
            ];
            if (isset($aliases[$utm])) {
                return $aliases[$utm];
            }
        }

        return self::fromReferrer($referrer);
    }
}
