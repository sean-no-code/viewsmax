<?php

namespace App\Services\Social;

use App\Models\BoostCheck;
use App\Models\BoostSetting;
use App\Models\PostTarget;

/**
 * Seeds Boost checks when a target publishes: one pending check per enabled
 * feature on the account that published it, first run 6 hours out. The unique
 * (post_target_id, feature) key makes re-fired publish events harmless.
 */
class BoostScheduler
{
    public static function onTargetPublished(PostTarget $target): void
    {
        // X-only v1, and only account-pinned targets (the settings hang off
        // the account, so an unpinned legacy target has nothing to look up).
        if ($target->platform !== 'x' || ! $target->social_account_id || ! $target->platform_post_id) {
            return;
        }

        $settings = BoostSetting::where('social_account_id', $target->social_account_id)
            ->where('enabled', true)
            ->get();

        foreach ($settings as $setting) {
            BoostCheck::firstOrCreate(
                ['post_target_id' => $target->id, 'feature' => $setting->feature],
                [
                    'boost_setting_id' => $setting->id,
                    'runs_completed' => 0,
                    'next_run_at' => now()->addHours(BoostCheck::RUN_INTERVAL_HOURS),
                    'status' => BoostCheck::STATUS_PENDING,
                ]
            );
        }
    }
}
