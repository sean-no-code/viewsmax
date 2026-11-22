<?php

namespace App\Services\Billing;

use App\Mail\InvoicePaidMail;
use App\Mail\PaymentFailedMail;
use App\Mail\SubscriptionCancelledMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            pdf: $this->invoicePdf((string) ($invoice['invoice_pdf'] ?? ''), $context),
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

    /**
     * "Your payment didn't go through", once per Stripe invoice. Stripe sends
     * invoice.payment_failed again for every retry of the same invoice (and
     * can redeliver an event), so the invoice id is claimed on the user_plans
     * row first, in one UPDATE: only the delivery that wins the claim mails.
     * If Stripe's own failed-payment emails are on (Dashboard -> Billing ->
     * Revenue recovery -> Emails), customers get both: turn Stripe's off.
     *
     * @param  array<string, mixed>  $invoice  the Stripe invoice object
     */
    public function paymentFailed(User $user, string $planName, string $subscriptionId, array $invoice): void
    {
        $invoiceId = $invoice['id'] ?? null;
        $context = BillingLog::context($user, $subscriptionId, $invoiceId);
        $amountDue = (int) ($invoice['amount_due'] ?? 0);

        if (! $invoiceId || $amountDue <= 0) {
            return;
        }

        $claimed = DB::table('user_plans')
            ->where('user_id', $user->id)
            ->where('stripe_subscription_id', $subscriptionId)
            ->where(fn ($q) => $q->whereNull('payment_failed_notified_invoice_id')
                ->orWhere('payment_failed_notified_invoice_id', '!=', $invoiceId))
            ->update(['payment_failed_notified_invoice_id' => $invoiceId]);

        if (! $claimed) {
            BillingLog::info('Payment-failed email skipped: already sent for this invoice', $context);

            return;
        }

        $nextAttempt = $invoice['next_payment_attempt'] ?? null;

        $this->send($user, new PaymentFailedMail(
            name: $user->name ?: 'there',
            planName: $planName,
            amount: self::money($amountDue, (string) ($invoice['currency'] ?? 'usd')),
            nextAttempt: $nextAttempt ? self::at((int) $nextAttempt)->format(self::DATE_TIME_FORMAT) : '',
            billingUrl: self::billingUrl(),
            invoiceUrl: (string) ($invoice['hosted_invoice_url'] ?? ''),
        ), 'Payment-failed email', $context + ['amount_due' => $amountDue, 'currency' => $invoice['currency'] ?? null]);
    }

    /** Largest invoice PDF worth attaching; a Stripe invoice is tens of KB. */
    private const MAX_PDF_BYTES = 5 * 1024 * 1024;

    /**
     * The bytes of Stripe's invoice PDF, to attach to the invoice email, or
     * null when it cannot be had (the email then goes out with the link
     * only). Only an https URL on stripe.com is fetched, and only a body that
     * is a PDF is used. Never throws.
     *
     * @param  array<string, mixed>  $context
     */
    private function invoicePdf(string $url, array $context): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'stripe.com' || str_ends_with($host, '.stripe.com'))) {
            return null;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
            $body = $response->successful() ? $response->body() : '';

            if (! str_starts_with($body, '%PDF') || strlen($body) > self::MAX_PDF_BYTES) {
                BillingLog::warning('Invoice PDF not attached: unusable response', $context + [
                    'status' => $response->status(),
                    'bytes' => strlen($body),
                ]);

                return null;
            }

            return $body;
        } catch (Throwable $e) {
            BillingLog::warning('Invoice PDF not attached: download failed', $context + ['error' => $e->getMessage()]);

            return null;
        }
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
