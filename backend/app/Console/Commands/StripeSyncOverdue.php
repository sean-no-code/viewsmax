<?php

namespace App\Console\Commands;

use App\Http\Controllers\StripeWebhookController;
use App\Models\User;
use App\Models\UserPlan;
use App\Services\Billing\BillingEmails;
use App\Services\Billing\BillingLog;
use App\Services\CreditService;
use App\Services\StripeService;
use App\Support\AnalyticsAccess;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bring overdue subscription rows in line with Stripe, for when a webhook
 * never arrived. Overdue = a trial or renewal whose date (expires_at) has
 * passed while the row still says trialing/active, or a row that is past_due.
 * For each one it asks Stripe for the subscription and its latest invoice:
 *
 *  - paid and never applied here: the invoice.paid logic (plan active,
 *    credits for the period, invoice email), as `stripe:sync-invoice` does;
 *  - paid and already applied: only the row's status and date are put right;
 *  - a trial that has ended but is not charged yet (Stripe charges about an
 *    hour after the trial ends): left a trial until it is;
 *  - payment failing: the invoice.payment_failed logic (past_due, the
 *    payment-failed email once per invoice);
 *  - ended in Stripe: the row is expired;
 *  - paid after the subscription ended: left for a person, because applying
 *    it starts a new Stripe subscription (`stripe:sync-invoice` does that).
 *
 * It never changes anything in Stripe. --dry-run lists what it would do.
 */
class StripeSyncOverdue extends Command
{
    private const APPLY_PAID = 'apply_paid_invoice';

    private const SET_ROW = 'set_row';

    private const MARK_PAST_DUE = 'mark_past_due';

    private const EXPIRE = 'expire';

    private const NEEDS_PERSON = 'needs_person';

    private const NONE = 'none';

    protected $signature = 'stripe:sync-overdue {--dry-run : List what would be done and change nothing}';

    protected $description = 'Check trials and renewals that are past their date against Stripe and bring them in line';

    public function handle(StripeService $stripe, StripeWebhookController $webhook, CreditService $credits): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = UserPlan::query()
            ->whereNotNull('stripe_subscription_id')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)->where('expires_at', '<', now()))
                ->orWhere('status', 'past_due'))
            ->with(['user', 'plan'])
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (UserPlan $row) => $row->user !== null)
            ->values();

        if ($rows->isEmpty()) {
            $this->info('Nothing is overdue.');

            return self::SUCCESS;
        }

        $counts = ['change' => 0, 'ok' => 0, 'person' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $who = "#{$row->user->id} {$row->user->email} · ".($row->plan?->display_name ?: $row->plan?->name ?: 'plan '.$row->plan_id)
                ." · {$row->status}, due ".($row->expires_at?->format('Y-m-d') ?? 'no date')
                ." · {$row->stripe_subscription_id}";

            try {
                $subscription = $stripe->getSubscription($row->stripe_subscription_id);
                $plan = $this->decide($row, $subscription, $credits);
            } catch (Throwable $e) {
                $counts['error']++;
                $this->say('error', $who, "could not read from Stripe: {$e->getMessage()}");

                continue;
            }

            $who .= ' · Stripe: '.($subscription['status'] ?? 'unknown');

            if ($plan['action'] === self::NONE) {
                $counts['ok']++;
                $this->say('line', $who, $plan['summary']);

                continue;
            }

            if ($plan['action'] === self::NEEDS_PERSON) {
                $counts['person']++;
                $this->say('warn', $who, $plan['summary']);

                continue;
            }

            $counts['change']++;

            if ($dryRun) {
                $this->say('info', $who, 'would '.$plan['summary']);

                continue;
            }

            try {
                $result = $this->apply($plan, $row, $webhook, $credits);
                $this->say('info', $who, $plan['summary'], "done: {$result}");
                BillingLog::info('Overdue subscription synced', BillingLog::context($row->user, $row->stripe_subscription_id, $plan['invoice']['id'] ?? null) + [
                    'action' => $plan['action'],
                    'outcome' => $result,
                ]);
            } catch (Throwable $e) {
                report($e);
                $counts['change']--;
                $counts['error']++;
                $this->say('error', $who, $plan['summary'], "failed: {$e->getMessage()}");
                BillingLog::warning('Overdue subscription sync failed', BillingLog::context($row->user, $row->stripe_subscription_id, $plan['invoice']['id'] ?? null) + [
                    'action' => $plan['action'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->line("{$rows->count()} overdue: {$counts['change']} ".($dryRun ? 'to change' : 'changed')
            .", {$counts['ok']} already right, {$counts['person']} for a person, {$counts['error']} failed.");

        if ($dryRun) {
            $this->comment('Dry run: nothing was changed. Run it again without --dry-run to apply.');
        }

        return $counts['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** One row of the report: who it is, then what happens to them, indented. */
    private function say(string $style, string $who, string ...$details): void
    {
        $this->{$style}($who);
        foreach ($details as $detail) {
            $this->{$style}('    '.$detail);
        }
    }

    /**
     * What to do with one row, given the subscription as Stripe has it now.
     *
     * @param  array<string, mixed>  $subscription
     * @return array{action: string, summary: string, invoice: ?array, status?: string, expires_at?: ?Carbon}
     */
    private function decide(UserPlan $row, array $subscription, CreditService $credits): array
    {
        $status = (string) ($subscription['status'] ?? 'unknown');
        $invoice = is_array($subscription['latest_invoice'] ?? null) ? $subscription['latest_invoice'] : null;
        $invoiceId = $invoice['id'] ?? null;
        $invoiceStatus = $invoice['status'] ?? null;

        // A $0 trial invoice is "paid" too; there is no payment in it to apply.
        $paid = $invoiceId && $invoiceStatus === 'paid' && (int) ($invoice['amount_paid'] ?? 0) > 0;
        $unapplied = $paid && ! $credits->isTransactionIdProcessed($invoiceId);
        $amount = $paid ? BillingEmails::money((int) $invoice['amount_paid'], (string) ($invoice['currency'] ?? 'usd')) : null;

        $periodEndTs = $subscription['current_period_end']
            ?? $subscription['items']['data'][0]['current_period_end']
            ?? $subscription['trial_end']
            ?? null;
        $periodEnd = $periodEndTs ? Carbon::createFromTimestamp($periodEndTs) : null;
        $until = $periodEnd ? ' until '.$periodEnd->format('Y-m-d') : '';

        $plan = fn (string $action, string $summary, array $extra = []) => ['action' => $action, 'summary' => $summary, 'invoice' => $invoice] + $extra;

        if (in_array($status, User::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
            if ($unapplied) {
                return $plan(self::APPLY_PAID, "apply paid invoice {$invoiceId} ({$amount}): plan {$status}{$until}, credits for the period, invoice email");
            }

            // The hour after a trial ends: Stripe says active, the first
            // invoice is not charged yet. The row stays a trial until it is
            // (as the webhook leaves it): here `active` means paid.
            if ($row->status === 'trialing' && $status === 'active' && in_array($invoiceStatus, ['draft', 'open'], true)) {
                return $plan(self::NONE, "nothing to do: the trial has ended and Stripe has not charged invoice {$invoiceId} yet");
            }

            $inStep = $row->status === $status
                && (! $periodEnd || ($row->expires_at && $row->expires_at->getTimestamp() === $periodEnd->getTimestamp()));
            if ($inStep) {
                return $plan(self::NONE, 'nothing to do: the row already matches Stripe');
            }

            return $plan(
                self::SET_ROW,
                "set to {$status}{$until}".($paid ? " (invoice {$invoiceId} was already applied)" : ''),
                ['status' => $status, 'expires_at' => $periodEnd],
            );
        }

        if (in_array($status, ['past_due', 'unpaid'], true)) {
            if ($row->status === 'past_due') {
                $next = $invoice['next_payment_attempt'] ?? null;

                return $plan(self::NONE, 'nothing to do: already past due'.($invoiceId ? ", invoice {$invoiceId} is unpaid" : '')
                    .($next ? ', Stripe retries on '.Carbon::createFromTimestamp($next)->format('Y-m-d') : ''));
            }

            return $plan(self::MARK_PAST_DUE, 'set to past_due and send the payment-failed email'
                .($invoiceId ? " (invoice {$invoiceId} is {$invoiceStatus} in Stripe)" : ''));
        }

        if (in_array($status, ['canceled', 'incomplete_expired'], true)) {
            $endedAt = (int) ($subscription['ended_at'] ?? $subscription['canceled_at'] ?? 0);
            $paidAt = (int) ($invoice['status_transitions']['paid_at'] ?? 0);

            if ($unapplied && $endedAt && $paidAt > $endedAt) {
                return $plan(self::NEEDS_PERSON, "invoice {$invoiceId} ({$amount}) was paid after the subscription ended. "
                    ."Run `php artisan stripe:sync-invoice {$invoiceId}` to start the replacement subscription, or refund it");
            }

            return $plan(self::EXPIRE, 'expire: Stripe ended the subscription'
                .($endedAt ? ' on '.Carbon::createFromTimestamp($endedAt)->format('Y-m-d') : ''));
        }

        return $plan(self::NONE, "nothing to do: the subscription is {$status} in Stripe");
    }

    /** Carry out a decision; returns what was done, for the output and the billing log. */
    private function apply(array $plan, UserPlan $row, StripeWebhookController $webhook, CreditService $credits): string
    {
        $user = $row->user;

        switch ($plan['action']) {
            case self::APPLY_PAID:
                return $webhook->syncPaidInvoice($plan['invoice']['id'])['outcome'];

            case self::SET_ROW:
                DB::table('user_plans')->where('id', $row->id)->update(array_filter([
                    'status' => $plan['status'],
                    'expires_at' => $plan['expires_at'],
                    'updated_at' => now(),
                ]));
                // The pivot fires no model events (see AnalyticsAccess::backfill).
                AnalyticsAccess::backfill($user);

                return "row set to {$plan['status']}";

            case self::MARK_PAST_DUE:
                if ($plan['invoice']) {
                    return $webhook->syncFailedInvoice($plan['invoice'])['outcome'];
                }

                DB::table('user_plans')->where('id', $row->id)->update(['status' => 'past_due', 'updated_at' => now()]);

                return 'row set to past_due';

            case self::EXPIRE:
                DB::table('user_plans')->where('id', $row->id)->update([
                    'status' => 'expired',
                    'cancelled_at' => $row->cancelled_at ?? now(),
                    'updated_at' => now(),
                ]);

                // As the daily expiry run does: an ended plan keeps no credits.
                // Not when another plan of theirs is live.
                if (! $user->hasActivePlan()) {
                    $credits->resetBalance($user, 0);
                }

                return 'row expired';
        }

        throw new \LogicException("Unknown action {$plan['action']}");
    }
}
