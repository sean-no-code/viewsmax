<?php

namespace App\Support;

/**
 * Describes where an OAuth client sends the user after approval.
 *
 * The client's name comes from self-registration (RFC 7591), so any app can
 * call itself "Claude". The registered redirect URIs can't be swapped as
 * freely, which is why the MCP authorization spec — and Anthropic's connector
 * documentation — require the consent screen to show the redirect host, plus a
 * warning when every redirect points back at the user's own machine.
 */
class OAuthClientRedirects
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * Unique redirect hosts, in registration order.
     *
     * @param  string[]  $redirectUris
     * @return string[]
     */
    public static function hosts(array $redirectUris): array
    {
        return collect($redirectUris)
            ->map(fn ($uri) => parse_url((string) $uri, PHP_URL_HOST))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * True when every redirect returns to software running on the user's own
     * computer, which any local process could have registered.
     *
     * @param  string[]  $redirectUris
     */
    public static function isLoopbackOnly(array $redirectUris): bool
    {
        $hosts = self::hosts($redirectUris);

        return $hosts !== [] && collect($hosts)->every(
            fn (string $host) => in_array(strtolower($host), self::LOOPBACK_HOSTS, true)
        );
    }
}
