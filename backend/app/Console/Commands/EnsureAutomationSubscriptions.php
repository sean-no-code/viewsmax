<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Automations\AutomationLog;
use App\Services\Automations\AutomationSubscriptionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-subscribe Instagram accounts that have live automations but a missing
 * or stale webhook subscription record. Daily; a no-op for fresh accounts.
 */
class EnsureAutomationSubscriptions extends Command
{
    protected $signature = 'automations:ensure-subscriptions';

    protected $description = 'Re-subscribe Instagram accounts with live automations to comment/message webhooks when stale';

    public function handle(AutomationSubscriptionService $subscriptions): int
    {
        $accounts = SocialAccount::query()
            ->where('platform', 'instagram')
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->whereHas('automations', fn ($q) => $q->live())
            ->where(fn ($q) => $q
                ->whereNull('webhook_subscribed_at')
                ->orWhere('webhook_subscribed_at', '<', now()->subDays(AutomationSubscriptionService::STALE_DAYS)))
            ->get();

        $ok = $failed = 0;
        foreach ($accounts as $account) {
            try {
                $subscriptions->ensureSubscribed($account, force: true);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                AutomationLog::warning('ensure-subscriptions failed for account', AutomationLog::context(account: $account) + ['error' => $e->getMessage()]);
            }
        }

        AutomationLog::info('ensure-subscriptions finished', ['checked' => $accounts->count(), 'ok' => $ok, 'failed' => $failed]);
        $this->info("Checked {$accounts->count()} account(s): {$ok} re-subscribed, {$failed} failed.");

        return self::SUCCESS;
    }
}
