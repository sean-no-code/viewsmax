<?php

namespace App\Support;

use App\Jobs\ImportChannelVideosJob;
use App\Jobs\ImportRecentPostsJob;
use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one answer to "does this user's plan include analytics?", shared by every
 * place that would otherwise spend provider API calls on it:
 *
 *  - Connect     YouTubeOAuthController, SocialConnect  -> no import is queued
 *  - Console     withAnalytics() when a command selects in bulk
 *                (channels:sync-videos, audience:refresh, posts:refresh-metrics,
 *                posts:import-recent)
 *  - Jobs        allows() per record in ImportChannelVideosJob /
 *                ImportRecentPostsJob (a job can outlive a downgrade)
 *  - REST        ChannelController::refresh (it re-runs the analytics fetch)
 *  - SPA         the `can_use_analytics` flag in User::apiPayload()
 *
 * Analytics is the Pro tier upsell: channel analytics, the uploads import, the
 * recent-posts import and the daily follower/engagement snapshots. Reads of
 * already-stored analytics are not gated here — they cost nothing — the pages
 * that show them are gated in the SPA.
 *
 * Admins and promotional customers are exempt, matching UserAccess and
 * Quota\Tier::of. Access (UserAccess) is a separate, orthogonal question: a
 * lapsed Pro user has the plan but not the access, and both must say yes.
 */
final class AnalyticsAccess
{
    /**
     * Tiers that include analytics. The three `creator_*` rows are the retired
     * legacy tiers PlanSeeder marks inactive while keeping their subscribers,
     * grandfathered in so no paying legacy customer silently loses analytics.
     */
    public const PLANS = ['pro', 'agency', 'creator_pro', 'creator_elite', 'creator_agency'];

    /** How long a backfill is remembered, so webhook churn can't re-queue it. */
    private const BACKFILL_TTL_HOURS = 24;

    /** Does this user's plan include analytics right now? */
    public static function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin() || $user->isPromotional()) {
            return true;
        }

        $plan = $user->activePlan();

        return $plan !== null && in_array(strtolower($plan->name), self::PLANS, true);
    }

    /**
     * Queue the imports a user missed while they were on a plan without
     * analytics. Called from every path that writes the user_plans pivot (the
     * pivot fires no model events — two of those paths are raw DB::table
     * updates — so there is nothing to observe). A no-op for a plan without
     * analytics, and remembered for a day so a stream of Stripe webhooks for
     * one upgrade queues one backfill.
     */
    public static function backfill(User $user): void
    {
        if (! self::allows($user)) {
            return;
        }

        if (! Cache::add(self::backfillKey($user), true, now()->addHours(self::BACKFILL_TTL_HOURS))) {
            return;
        }

        $channelIds = Channel::where('user_id', $user->id)
            ->whereNotNull('youtube_channel_id')
            ->pluck('id');

        $accountIds = SocialAccount::where('user_id', $user->id)
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->pluck('id');

        foreach ($channelIds as $id) {
            ImportChannelVideosJob::dispatch($id);
        }

        foreach ($accountIds as $id) {
            ImportRecentPostsJob::dispatch($id);
        }

        if ($channelIds->isEmpty() && $accountIds->isEmpty()) {
            return;
        }

        Log::channel('post_import')->info('Analytics backfill queued', [
            'user_id' => $user->id,
            'plan' => $user->activePlan()?->name,
            'channels' => $channelIds->count(),
            'accounts' => $accountIds->count(),
        ]);
    }

    /** Log a skipped import in one shape, whatever skipped it. */
    public static function logSkip(string $what, array $context = []): void
    {
        Log::channel('post_import')->info("Analytics {$what} skipped: plan has no analytics", $context);
    }

    private static function backfillKey(User $user): string
    {
        return "analytics-backfill:{$user->id}";
    }
}
