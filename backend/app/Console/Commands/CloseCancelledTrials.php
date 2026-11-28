<?php

namespace App\Console\Commands;

use App\Models\UserPlan;
use App\Services\Billing\BillingLog;
use App\Services\Billing\UnpaidTrial;
use App\Services\StripeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Close trials that were cancelled but never ended. A trial cancelled before
 * anything was charged ends at once (UnpaidTrial), but rows cancelled before
 * that rule shipped (2026-11-27), or by any path that misses it, are still
 * `trialing` with cancelled_at set, and `trialing` counts as a live plan:
 * the user keeps access until the trial date.
 *
 * For each such subscription it asks Stripe first:
 *  - still trialing (nothing was charged) or already ended: access closes
 *    here (UnpaidTrial::close);
 *  - anything else (active: the trial is over and the first invoice is paid
 *    or about to be): left alone, the paid-cancel rules apply.
 *
 * It only reads Stripe, never changes it: a cancelled trial is already set
 * to end at its trial date there, without a charge. Hourly from the
 * scheduler. --dry-run lists what it would do.
 */
class CloseCancelledTrials extends Command
{
    protected $signature = 'subscriptions:close-cancelled-trials {--dry-run : List what would be done and change nothing}';

    protected $description = 'Close access for trials that were cancelled before anything was charged (reads Stripe, never changes it)';

    public function handle(StripeService $stripe): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = UserPlan::query()
            ->where('status', 'trialing')
            ->whereNotNull('cancelled_at')
            ->whereNotNull('stripe_subscription_id')
            ->with('user')
            ->orderBy('cancelled_at')
            ->get()
            ->filter(fn (UserPlan $row) => $row->user !== null)
            ->unique(fn (UserPlan $row) => $row->user_id.'|'.$row->stripe_subscription_id)
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No cancelled trials are still open.');

            return self::SUCCESS;
        }

        $errors = 0;

        foreach ($rows as $row) {
            $user = $row->user;
            $subscriptionId = $row->stripe_subscription_id;
            $who = "#{$user->id} {$user->email} · {$subscriptionId}";

            try {
                $stripeStatus = $stripe->getSubscription($subscriptionId)['status'] ?? null;
                $unpaid = in_array($stripeStatus, ['trialing', 'canceled', 'incomplete_expired'], true);

                if (! $unpaid) {
                    $this->line("{$who} · Stripe: {$stripeStatus} · left alone (past the trial)");

                    continue;
                }

                if ($dryRun) {
                    $this->line("{$who} · Stripe: {$stripeStatus} · would close access");

                    continue;
                }

                UnpaidTrial::close($user, $subscriptionId);

                $this->line("{$who} · Stripe: {$stripeStatus} · access closed");
            } catch (Throwable $e) {
                $errors++;
                BillingLog::error('Closing a cancelled trial failed', BillingLog::context($user, $subscriptionId) + ['error' => $e->getMessage()]);
                $this->error("{$who} · failed: {$e->getMessage()}");
            }
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
