<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * Thrown when a platform account (YouTube channel, TikTok, X, ...) is being
 * connected to a ViewsMax account other than the one that first connected it.
 * The message is shown to the user as is, with the owner's email masked.
 */
class AccountAlreadyConnectedException extends RuntimeException
{
    public static function forOwner(User $owner, string $platform): self
    {
        $what = $platform === 'youtube'
            ? 'this channel'
            : 'this ' . (config("social.platforms.{$platform}.label") ?? ucfirst($platform)) . ' account';

        return new self(
            "You already created an account with {$what} on email " . self::maskEmail($owner->email)
            . '. Log in with that email to use it.'
        );
    }

    /** "sean@gmail.com" -> "se****@gmail.com": enough to recognise, not to read. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1))) . '****@' . $domain;
    }
}
