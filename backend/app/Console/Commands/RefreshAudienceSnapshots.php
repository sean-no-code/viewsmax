<?php

namespace App\Console\Commands;

use App\Models\AudienceSnapshot;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Snapshots each connected account's follower/subscriber count once per day so
 * the Audience Growth page can plot follower growth over time (day-over-day
 * deltas). Iterates connected SocialAccounts (RefreshSocialTokens pattern) and
 * upserts one row per account per day (RefreshTrackingReach pattern).
 *
 * Platforms where the API/plan can't supply a count return null and are skipped
 * (never zeroed). Coverage expands as scopes/approvals land — see config/social
 * (tiktok stats_enabled, instagram reach_enabled) and the provider methods.
 */
class RefreshAudienceSnapshots extends Command
{
    protected $signature = 'audience:refresh';

    protected $description = 'Snapshot each connected account\'s follower/subscriber count for audience-growth charts.';

    public function handle(SocialProviderManager $manager): int
    {
        $accounts = SocialAccount::where('status', SocialAccount::STATUS_CONNECTED)->get();

        $captured = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            if (! $manager->supports($account->platform)) {
                $skipped++;

                continue;
            }

            try {
                $provider = $manager->for($account->platform);
                $account = $provider->ensureFreshToken($account);
                $count = $provider->fetchFollowerCount($account);

                if ($count === null) {
                    $skipped++;

                    continue;
                }

                AudienceSnapshot::updateOrCreate(
                    ['social_account_id' => $account->id, 'snapshot_date' => now()->toDateString()],
                    ['follower_count' => (int) $count],
                );
                $captured++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Audience snapshot failed', [
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Accounts: {$accounts->count()}, captured {$captured}, skipped {$skipped}, failed {$failed}.");

        // Only a total wipeout (had work, captured nothing, all errored) fails.
        return ($failed > 0 && $captured === 0 && $skipped === 0) ? self::FAILURE : self::SUCCESS;
    }
}
