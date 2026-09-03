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

    public static function isSafe(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || in_array(get_class($e), [\RuntimeException::class, \InvalidArgumentException::class], true);
    }
}
