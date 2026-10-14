<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;

/**
 * Decides whether an exception message may be shown to end users.
 *
 * Our provider services throw plain \RuntimeException / \InvalidArgumentException
 * with user-safe messages ("Video not found"). But framework exceptions SUBCLASS
 * RuntimeException — ConnectionException carries raw cURL text and QueryException
 * carries raw SQL — so only exact-class matches are trusted; everything else gets
 * the caller's fallback.
 */
class UserSafeError
{
    public static function message(\Throwable $e, string $fallback): string
    {
        if ($e instanceof ConnectionException) {
            return 'The video provider took too long to respond. Please try again in a minute.';
        }

        $safe = in_array(get_class($e), [\RuntimeException::class, \InvalidArgumentException::class], true);

        return $safe && $e->getMessage() !== '' ? $e->getMessage() : $fallback;
    }

    /**
     * Exception text for an API response body: the real message while
     * debugging, our own user-safe exceptions' message, otherwise null —
     * framework/driver exceptions carry SQL, hosts and URLs (with API keys).
     */
    public static function detail(\Throwable $e): ?string
    {
        if (config('app.debug')) {
            return $e->getMessage();
        }

        // Stripe card errors ("Your card was declined.") are written for customers.
        $safe = in_array(get_class($e), [\RuntimeException::class, \InvalidArgumentException::class], true)
            || $e instanceof \Stripe\Exception\CardException;

        return $safe && $e->getMessage() !== '' ? $e->getMessage() : null;
    }

    /** "Failed to X: <detail>" when the detail is safe to show, else "Failed to X." */
    public static function withDetail(string $prefix, \Throwable $e): string
    {
        $detail = self::detail($e);

        return $detail !== null ? $prefix.$detail : rtrim($prefix, " :.\t").'.';
    }

    public static function isSafe(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || in_array(get_class($e), [\RuntimeException::class, \InvalidArgumentException::class], true);
    }
}
