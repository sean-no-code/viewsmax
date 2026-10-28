<?php

namespace App\Support\Quota;

use App\Models\User;

/**
 * Which quota tier a user draws from. Kept in the app (not the quota package)
 * because it is the one place that knows how plans and roles work; the package
 * only ever sees the tier name.
 */
final class Tier
{
    public const FREE = 'free';
    public const PAID = 'paid';
    public const ADMIN = 'admin';

    public const ALL = [self::FREE, self::PAID, self::ADMIN];

    public static function of(?User $user): string
    {
        if (! $user) {
            return self::FREE;
        }
        if ($user->isAdmin()) {
            return self::ADMIN;
        }
        // Comped (promotional_customer role) customers are not rationed as free;
        // a free signup inside its promo window is.
        if ($user->isPromotional()) {
            return self::PAID;
        }
        $plan = $user->activePlan();

        return $plan && strtolower($plan->name) !== 'free' ? self::PAID : self::FREE;
    }
}
