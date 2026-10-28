<?php

namespace App\Contracts\Quota;

/**
 * "May the app spend this much YouTube API quota right now?"
 *
 * The app asks at its two spend points (queuing an outlier search fetch,
 * dispatching a YouTube publish) and never counts anything itself. The default
 * binding (App\Support\Quota\NullQuotaGate) always says yes, so the app runs
 * unchanged without a quota package; a package binds its own answer.
 */
interface QuotaGate
{
    /** One search.list call: 100 units, and capped at 100 calls/day by default. */
    public const SEARCH_LIST = 'search_list';

    /** One videos.insert call: 1600 units, and capped at 100 calls/day by default. */
    public const VIDEOS_INSERT = 'videos_insert';

    /** One outlier breakdown (a Gemini video analysis): per-user allowance only, no shared cap. */
    public const BREAKDOWNS = 'breakdowns';

    /**
     * @param string   $resource one of the constants above
     * @param string   $tier     App\Support\Quota\Tier::FREE / PAID / ADMIN
     * @param int      $calls    how many calls the operation is about to make
     * @param int|null $userId   who is asking; with it, their own daily
     *                           allowance is checked too (null: shared cap only)
     */
    public function allows(string $resource, string $tier, int $calls = 1, ?int $userId = null): QuotaDecision;

    /** One fetch / upload / breakdown was just started for this user: count it against their daily allowance. */
    public function recordUserFetch(int $userId, string $resource): void;
}
