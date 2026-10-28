<?php

namespace App\Support\Quota;

use App\Contracts\Quota\QuotaDecision;
use App\Contracts\Quota\QuotaGate;
use App\Models\User;

/**
 * What a refused user is told. Names their plan, says when the limit resets,
 * and — for free users only — that a higher plan has higher limits. The
 * upgrade sentence is always last and starts with "Upgrade", so the MCP
 * layer can drop it: AI clients may not be shown upgrade promotion
 * (see ViewsMaxTool::userFacingError).
 */
final class QuotaMessage
{
    public const RESET = 'midnight Pacific time';

    public static function for(QuotaDecision $decision, ?User $user): string
    {
        $plan = self::planLabel($user);
        $thing = match ($decision->resource) {
            QuotaGate::VIDEOS_INSERT => 'YouTube uploads',
            QuotaGate::BREAKDOWNS => 'outlier breakdowns',
            default => 'fresh YouTube searches',
        };
        $free = $decision->tier === Tier::FREE;

        if ($decision->scope === QuotaDecision::SCOPE_USER) {
            return "You've used today's {$decision->cap} {$thing} on the {$plan} plan. They reset at ".self::RESET.'.'
                .($free ? ' Upgrade to get higher limits.' : '');
        }

        $budget = $decision->resource === QuotaGate::VIDEOS_INSERT ? 'YouTube upload budget' : 'YouTube search budget';
        $meanwhile = $decision->resource === QuotaGate::VIDEOS_INSERT ? '' : ' — showing results from the library';

        return "ViewsMax's {$budget} for today is used up{$meanwhile}; {$thing} resume at ".self::RESET.'.'
            .($free ? ' Upgrade for priority access.' : '');
    }

    /** "Free", "Pro", … — the plan as the user knows it. */
    public static function planLabel(?User $user): string
    {
        $name = $user?->activePlan()?->name;

        return $name ? ucfirst(strtolower($name)) : 'Free';
    }

    /** Whether the message ends with an upgrade nudge (free tier). */
    public static function offersUpgrade(QuotaDecision $decision): bool
    {
        return $decision->tier === Tier::FREE;
    }
}
