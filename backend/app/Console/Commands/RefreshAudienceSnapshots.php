<?php

namespace App\Console\Commands;

use App\Models\AudienceSnapshot;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Snapshots each connected account's follower/subscriber count once per day so
 * the Audience Growth page can plot follower growth over time (day-over-day
 * deltas). Iterates connected SocialAccounts (RefreshSocialTokens pattern) and
 * upserts one row per account per day (RefreshTrackingReach pattern).
 *
 * Platforms without stats support (SocialProviderManager::supportsStats) are
 * skipped (never zeroed). A supported platform that fails or returns nothing
 * gets the reason written to social_accounts.follower_stats_error so the
 * Analytics page can show it; a later success clears it.
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
            if (! $manager->supports($account->platform) || ! $manager->supportsStats($account->platform)) {
                $skipped++;

                continue;
            }

            try {
                $provider = $manager->for($account->platform);
                $account = $provider->ensureFreshToken($account);
                $count = $provider->fetchFollowerCount($account);

                if ($count === null) {
                    // Supported platform, nothing back: surfaced on the Analytics page.
                    $skipped++;
                    $this->recordError($account, 'The platform returned no follower count.');

                    continue;
                }

                AudienceSnapshot::updateOrCreate(
                    ['social_account_id' => $account->id, 'snapshot_date' => now()->toDateString()],
                    ['follower_count' => (int) $count],
                );
                $this->recordError($account, null);
                $captured++;
            } catch (\Throwable $e) {
                $failed++;
                $this->recordError($account, $e->getMessage());
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

    /** Persist (or clear) why this run could not get a follower count for the account. */
    private function recordError(SocialAccount $account, ?string $message): void
    {
        $message = $message === null ? null : Str::limit($message, 1000, '…');
        if ($account->follower_stats_error !== $message) {
            $account->forceFill(['follower_stats_error' => $message])->save();
        }
    }
}
