<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;

/**
 * Keeps connected accounts alive WITHOUT the user publishing: refreshes every
 * connected account whose token expires within the window. Meta-family tokens
 * (Instagram/Threads, 60 days) can only be refreshed while still valid — an
 * account left idle past expiry is unrecoverable and forces a reconnect,
 * which is exactly what this prevents. Runs daily.
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

        $refreshed = 0;
        $failed = 0;
        foreach ($due as $account) {
            if (! $manager->supports($account->platform)) {
                continue;
            }

            try {
                $manager->for($account->platform)->ensureFreshToken($account);
                $refreshed++;
            } catch (\Throwable $e) {
                // A refresh that keeps failing is terminal: once the token
                // expires it cannot be refreshed at all and the user must
                // reconnect. Report it rather than warn — this command is the
                // only thing standing between an idle account and that state.
                $failed++;
                Context::add(['social_account_id' => $account->id, 'platform' => $account->platform]);
                report($e);
            }
        }

        $this->info("Checked {$due->count()} account(s), refreshed {$refreshed}, failed {$failed}.");

        // A non-zero exit makes the scheduler's onFailure hook fire, so a
        // provider-wide refresh outage is visible without reading the log.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
