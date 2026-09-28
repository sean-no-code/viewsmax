<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Best-effort country resolution for a client IP.
 *
 * Prefers the country header a CDN already stamped on the request (no
 * network call), then falls back to the free ip-api.com lookup with a short
 * timeout. Results are cached per IP so a page load never repeats the lookup.
 * Every path degrades to "unknown" rather than throwing: callers decide what
 * unknown means (login just skips the country; the cookie banner shows).
 */
class GeoLocationService
{
    /** EU-27 + EEA (IS, LI, NO) + UK + CH: where cookie-consent law applies. */
    public const CONSENT_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        'IS', 'LI', 'NO',
        'GB', 'CH',
    ];

    private const CACHE_TTL_HOURS = 24;

    /**
     * Resolve [country name, ISO 3166-1 alpha-2 code] for an IP. A country code
     * a CDN already stamped on the request ($cdnCountry) wins without a lookup.
     * Returns [null, null] when it cannot be determined.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function lookup(?string $ip, ?string $cdnCountry = null): array
    {
        $cdnCountry = strtoupper(trim((string) $cdnCountry));
        if (preg_match('/^[A-Z]{2}$/', $cdnCountry)) {
            return [null, $cdnCountry];
        }

        // Only public IPs are worth resolving — skip localhost / LAN / reserved
        // ranges (this also keeps tests from hitting the network on 127.0.0.1).
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [null, null];
        }

        return Cache::remember('geo:'.$ip, now()->addHours(self::CACHE_TTL_HOURS), function () use ($ip) {
            try {
                $res = Http::timeout(2)->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,country,countryCode']);
                if ($res->ok() && $res->json('status') === 'success') {
                    return [$res->json('country'), strtoupper((string) $res->json('countryCode'))];
                }
            } catch (\Throwable) {
                // Best-effort only — a slow or failed lookup must never break the caller.
            }

            return [null, null];
        });
    }

    /**
     * Whether visitors from this country must be asked before non-essential
     * cookies are set. Unknown country → true, so a failed lookup errs on the
     * side of showing the banner.
     */
    public function requiresCookieConsent(?string $countryCode): bool
    {
        return $countryCode === null || in_array(strtoupper($countryCode), self::CONSENT_COUNTRIES, true);
    }
}
