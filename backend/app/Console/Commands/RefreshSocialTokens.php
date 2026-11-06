<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
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
 * - X (2h), Google/YouTube (1h), LinkedIn (60d): the access token is renewed
 *   from the refresh token once expired, which keeps the grant exercised so
 *   an idle account never needs a reconnect. No early window is needed there
 *   — the refresh token outlives the access token — and publish-time
 *   behaviour is untouched.
 * - TikTok: the same, except its provider renews in the token's last hour
 *   (see TikTokProvider::ensureFreshToken), so this run only touches a token
 *   that is expired or about to be.
 *
 * needs_reauth accounts are skipped (the user has to reconnect), unless
 * --recheck-flagged asks for one platform's to be tried again: a refresh that
 * succeeds puts the account back to connected. That is for accounts flagged
 * while their grant was in fact alive; it is run by hand, never scheduled,
 * and takes a platform because other providers flag for reasons a refresh
 * would wrongly clear. A transient
 * refresh failure (429/5xx) leaves the account connected and is retried on
 * the next run; only a definitive rejection flags it. An exception escaping
 * a provider is reported (Sentry) and makes the command exit non-zero: this
 * command is the only thing standing between an idle account and a token
 * that can no longer be refreshed, so its own failures must not be silent.
 */
class RefreshSocialTokens extends Command
{
    protected $signature = 'social:refresh-tokens
        {--days=14 : Refresh tokens expiring within this many days}
        {--platform= : Only this platform}
        {--recheck-flagged : Also retry accounts flagged needs_reauth (requires --platform)}';

    protected $description = 'Proactively refresh soon-expiring social account tokens so connections never lapse.';

    public function handle(SocialProviderManager $manager): int
    {
        $platform = $this->option('platform');
        $recheck = (bool) $this->option('recheck-flagged');
        if ($recheck && ! $platform) {
            $this->error('--recheck-flagged needs --platform: other providers flag for reasons a refresh would wrongly clear.');

            return self::FAILURE;
        }

        // Only users who may use ViewsMax right now. A lapsed user's Instagram /
        // Threads token may therefore die for good; they reconnect when they're back.
        $due = SocialAccount::whereIn('status', $recheck
            ? [SocialAccount::STATUS_CONNECTED, SocialAccount::STATUS_NEEDS_REAUTH]
            : [SocialAccount::STATUS_CONNECTED])
            ->when($platform, fn ($q) => $q->where('platform', $platform))
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now()->addDays((int) $this->option('days')))
            ->whereHas('user', fn ($u) => $u->withAccess())
            ->get();

        $refreshed = $flagged = $failed = $healed = 0;
        foreach ($due as $account) {
            if (! $manager->supports($account->platform)) {
                continue;
            }

            $before = $account->access_token;
            $wasFlagged = $account->status === SocialAccount::STATUS_NEEDS_REAUTH;

            try {
                $fresh = $manager->for($account->platform)->ensureFreshToken($account);
            } catch (\Throwable $e) {
                $failed++;
                Context::add(['social_account_id' => $account->id, 'platform' => $account->platform]);
                report($e);

                continue;
            }

            if ($wasFlagged) {
                // Already flagged: only a recovery is news.
                if ($fresh->status === SocialAccount::STATUS_CONNECTED) {
                    $healed++;
                    Log::info('Proactive token refresh: flagged account is connected again', [
                        'social_account_id' => $account->id,
                        'platform' => $account->platform,
                    ]);
                }
            } elseif ($fresh->status === SocialAccount::STATUS_NEEDS_REAUTH) {
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

        $this->info("Checked {$due->count()} account(s): {$refreshed} refreshed, {$flagged} now need reconnect, {$failed} errored"
            .($recheck ? ", {$healed} healed." : '.'));

        // A non-zero exit makes the scheduler's onFailure hook fire, so a
        // provider-wide refresh outage is visible without reading the log.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
