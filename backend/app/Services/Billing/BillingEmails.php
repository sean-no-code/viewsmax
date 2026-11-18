<?php

namespace App\Services\Billing;

use App\Mail\InvoicePaidMail;
use App\Mail\SubscriptionCancelledMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails about a customer's Stripe subscription (config/billing.php). Every
 * send is best effort: a mail failure is reported and logged, never thrown,
 * because the callers are a Stripe webhook and a cancel request whose real
 * work has already happened. Each outcome is one line on the `billing` log
 * channel (BillingLog).
 */
class BillingEmails
{
    /** Dates in emails carry their timezone, e.g. "November 17, 2026 at 3:00pm UTC". */
    public const DATE_TIME_FORMAT = 'F j, Y \a\t g:ia T';

    public const DATE_FORMAT = 'F j, Y';

    /**
     * The invoice for one paid billing cycle. The caller guarantees this runs
     * once per Stripe invoice id (StripeWebhookController sends it only when
     * the invoice's credits were allocated for the first time); $0 invoices
     * (the trial start) are skipped here.
     *
     * @param  array<string, mixed>  $invoice  the Stripe invoice object
     */
    public function invoicePaid(User $user, string $planName, array $invoice): void
    {
        $context = BillingLog::context(
            $user,
            $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null,
            $invoice['id'] ?? null,
        );
        $amountPaid = (int) ($invoice['amount_paid'] ?? 0);

        if ($amountPaid <= 0) {
            return;
        }

        if (! config('billing.emails.invoice')) {
            BillingLog::info('Invoice email skipped: switched off', $context);

            return;
        }

        [$periodStart, $periodEnd] = $this->servicePeriod($invoice);
        $paidAt = $invoice['status_transitions']['paid_at'] ?? null;

        $this->send($user, new InvoicePaidMail(
            name: $user->name ?: 'there',
            planName: $planName,
            amount: self::money($amountPaid, (string) ($invoice['currency'] ?? 'usd')),
            invoiceNumber: (string) ($invoice['number'] ?? ''),
            periodStart: $periodStart ? self::at($periodStart)->format(self::DATE_FORMAT) : '',
            periodEnd: $periodEnd ? self::at($periodEnd)->format(self::DATE_FORMAT) : '',
            paidAt: ($paidAt ? self::at((int) $paidAt) : now())->format(self::DATE_TIME_FORMAT),
            invoiceUrl: (string) ($invoice['hosted_invoice_url'] ?? ''),
            pdfUrl: (string) ($invoice['invoice_pdf'] ?? ''),
            billingUrl: self::billingUrl(),
        ), 'Invoice email', $context + ['amount_paid' => $amountPaid, 'currency' => $invoice['currency'] ?? null]);
    }

    /**
     * Confirmation of an in-app cancel. The access end date is Stripe's, from
     * the subscription it returned for the cancel: when an immediate cancel
     * ended it, or the end of the paid period for a cancel at period end.
     * $fallbackEnd (our expires_at) is used only if Stripe gave neither.
     *
     * @param  array<string, mixed>  $subscription  the Stripe subscription after the cancel
     */
    public function subscriptionCancelled(User $user, string $planName, string $subscriptionId, array $subscription, ?Carbon $fallbackEnd): void
    {
        $context = BillingLog::context($user, $subscriptionId);

        if (! config('billing.emails.cancellation')) {
            BillingLog::info('Cancellation email skipped: switched off', $context);

            return;
        }

        $endsNow = in_array($subscription['status'] ?? null, ['canceled', 'incomplete_expired'], true);
        $endTs = $endsNow
            ? ($subscription['ended_at'] ?? $subscription['canceled_at'] ?? null)
            : ($subscription['cancel_at']
                ?? $subscription['items']['data'][0]['current_period_end']
                ?? $subscription['current_period_end']
                ?? null);
        $end = $endTs ? self::at((int) $endTs) : ($endsNow ? now() : $fallbackEnd?->copy()->setTimezone(config('app.timezone')));

        if (! $end) {
            // Nothing trustworthy to promise; better no email than a wrong date.
            BillingLog::warning('Cancellation email skipped: no access end date', $context);

            return;
        }

        $this->send($user, new SubscriptionCancelledMail(
            name: $user->name ?: 'there',
            planName: $planName,
            accessUntil: $end->format(self::DATE_TIME_FORMAT),
            endsNow: $endsNow,
            billingUrl: self::billingUrl(),
        ), 'Cancellation email', $context + ['access_until' => $end->toIso8601String(), 'ends_now' => $endsNow]);
    }

    /** "$59.00 USD". Plans are priced in USD; other currencies get their code only. */
    public static function money(int $minorUnits, string $currency): string
    {
        $code = strtoupper($currency);
        $symbol = $code === 'USD' ? '$' : '';

        return $symbol.number_format($minorUnits / 100, 2).' '.$code;
    }

    /** A Stripe timestamp in the app timezone (so `T` prints "UTC", not "GMT+0000"). */
    public static function at(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp, config('app.timezone'));
    }

    public static function billingUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/dashboard/billing';
    }

    /**
     * The service period the customer paid for: the plan line's period, not a
     * proration line's (an earlier up/downgrade adds those, for past dates).
     * Falls back to the first line, then to the invoice's own period.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function servicePeriod(array $invoice): array
    {
        $lines = $invoice['lines']['data'] ?? [];
        $isProration = fn (array $line) => (bool) ($line['proration']
            ?? $line['parent']['subscription_item_details']['proration']
            ?? false);

        $line = collect($lines)->first(fn ($l) => is_array($l) && ! $isProration($l)) ?? ($lines[0] ?? null);
        $start = $line['period']['start'] ?? $invoice['period_start'] ?? null;
        $end = $line['period']['end'] ?? $invoice['period_end'] ?? null;

        return [$start ? (int) $start : null, $end ? (int) $end : null];
    }

    /** @param  array<string, mixed>  $context */
    private function send(User $user, Mailable $mail, string $what, array $context): void
    {
        try {
            Mail::to($user->email)->send($mail);
            BillingLog::info("{$what} sent", $context);
        } catch (Throwable $e) {
            // report() is what reaches Sentry; the log line (warning, so it
            // is not a second Sentry event) is for storage/logs/billing.log.
            Context::add($context);
            report($e);
            BillingLog::warning("{$what} failed to send", $context + ['error' => $e->getMessage()]);
        }
    }
}
