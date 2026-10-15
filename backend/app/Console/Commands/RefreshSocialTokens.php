<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps connected accounts alive WITHOUT the user publishing: every provider
 * with a refresh path is refreshed through its own ensureFreshToken() (same
 * code and per-account lock as publish time), for every connected account
 * whose token expires within the window. Runs daily.
 *
 * Why each platform needs it:
 * - Instagram / Threads (60-day tokens, no refresh token): refreshable only
 *   while still valid, so their providers refresh within 7 days of expiry.
 *   An account left idle past expiry is unrecoverable.
 * - X (2h), TikTok (24h), Google/YouTube (1h), LinkedIn (60d): the access
 *   token is renewed from the refresh token once expired, which keeps the
 *   grant exercised so an idle account never needs a reconnect. No early
 *   window is needed there — the refresh token outlives the access token —
 *   and publish-time behaviour is untouched.
 *
 * needs_reauth accounts are skipped (the user has to reconnect). A transient
 * refresh failure (429/5xx) leaves the account connected and is retried on
 * the next run; only a definitive rejection flags it.
 */
class RefreshSocialTokens extends Command
{
    protected $signature = 'social:refresh-tokens {--days=14 : Refresh tokens expiring within this many days}';

    protected $description = 'Proactively refresh soon-expiring social account tokens so connections never lapse.';

    public function handle(SocialProviderManager $manager): int
    {
        $due = SocialAccount::where('status', SocialAccount::STATUS_CONNECTED)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now()->addDays((int) $this->option('days')))
            ->get();

        $refreshed = $flagged = $failed = 0;
        foreach ($due as $account) {
            if (! $manager->supports($account->platform)) {
                continue;
            }

            $before = $account->access_token;

            try {
                $fresh = $manager->for($account->platform)->ensureFreshToken($account);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Proactive token refresh failed', [
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($fresh->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $flagged++;
                Log::warning('Proactive token refresh: account needs reconnect', [
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'error' => $fresh->last_error,
                ]);
            } elseif ($fresh->access_token !== $before) {
                $refreshed++;
            }
        }

        $this->info("Checked {$due->count()} account(s): {$refreshed} refreshed, {$flagged} now need reconnect, {$failed} errored.");

        return self::SUCCESS;
    }
}
