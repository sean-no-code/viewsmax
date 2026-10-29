<?php

namespace App\Support\Quota;

use App\Models\User;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Whose YouTube quota a call spends. The ledger wants a user on every row, but
 * most calls are made where nobody is signed in: queue workers and the nightly
 * commands. Those set the actor from the row they work for (channel owner,
 * account owner, post author) and the spend listener reads it back. It lives
 * in Laravel's Context, which travels with every queued job, so a job
 * dispatched from a signed-in request inherits the requester (dehydrating()).
 * Hidden context: it rides along without landing on every log line.
 */
final class Actor
{
    public const KEY = 'youtube_quota.user_id';

    /** From here on (and in jobs dispatched from here) YouTube calls count against this user. */
    public static function set(User|int|null $user): void
    {
        $id = $user instanceof User ? $user->id : $user;

        $id === null ? Context::forgetHidden(self::KEY) : Context::addHidden(self::KEY, $id);
    }

    /** The user the current YouTube call is for: the explicit actor, else whoever is signed in. */
    public static function id(): ?int
    {
        return Context::getHidden(self::KEY) ?? Auth::id();
    }

    /** Context::dehydrating hook: a job queued by a signed-in request inherits the requester. */
    public static function dehydrating(Repository $context): void
    {
        if (! $context->hasHidden(self::KEY) && ($id = Auth::id()) !== null) {
            $context->addHidden(self::KEY, $id);
        }
    }
}
