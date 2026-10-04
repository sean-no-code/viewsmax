<?php

namespace App\Support;

use Laravel\Passport\ClientRepository;
use Throwable;

/**
 * Which OAuth client sent a guest to /login or /register. Laravel stores the
 * authorize URL the guest was heading for in session('url.intended'); its
 * client_id names the agent (e.g. "Claude") that started the connection.
 */
class OAuthIntendedClient
{
    /** The client's display name, or null when the intended URL isn't an authorize request. */
    public static function nameFrom(?string $intendedUrl): ?string
    {
        $id = self::clientIdFrom($intendedUrl);
        if ($id === null) {
            return null;
        }

        try {
            $name = app(ClientRepository::class)->find($id)?->name;
        } catch (Throwable) {
            return null; // a lookup must never break signup
        }

        return $name ? mb_substr($name, 0, 255) : null;
    }

    /** True when the guest was on their way to the OAuth consent screen. */
    public static function isAuthorizeUrl(?string $intendedUrl): bool
    {
        return self::clientIdFrom($intendedUrl) !== null || self::pathIsAuthorize($intendedUrl);
    }

    private static function clientIdFrom(?string $url): ?string
    {
        if (! self::pathIsAuthorize($url)) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = $query['client_id'] ?? null;

        return is_string($id) && $id !== '' && strlen($id) <= 64 && preg_match('/^[A-Za-z0-9-]+$/', $id) ? $id : null;
    }

    private static function pathIsAuthorize(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_ends_with(rtrim($path, '/'), '/oauth/authorize');
    }
}
