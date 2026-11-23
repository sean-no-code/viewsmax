<?php

namespace App\Console\Commands;

use App\Http\Controllers\StripeWebhookController;
use App\Services\Billing\BillingLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Apply one paid Stripe invoice by hand: for a payment Stripe took whose
 * webhook never reached the app (endpoint misconfigured, server down for
 * longer than Stripe retries). Fetches the invoice from Stripe and runs the
 * same logic as the invoice.paid webhook: plan active, credits for the
 * period, invoice email. Idempotent by invoice id, like the webhook.
 */
class StripeSyncInvoice extends Command
{
    protected $signature = 'stripe:sync-invoice {invoice : The Stripe invoice id (in_...)}';

    protected $description = 'Apply a paid Stripe invoice whose webhook never arrived';

    public function handle(StripeWebhookController $webhook): int
    {
        $invoiceId = (string) $this->argument('invoice');

        if (! str_starts_with($invoiceId, 'in_')) {
            $this->error("{$invoiceId} is not an invoice id. Pass the invoice id (in_...): on an invoice_payment event it is the \"invoice\" field.");

            return self::FAILURE;
        }

        try {
            $result = $webhook->syncPaidInvoice($invoiceId);
        } catch (Throwable $e) {
            report($e);
            $this->error("Could not apply {$invoiceId}: {$e->getMessage()}");
            BillingLog::warning('Stripe invoice sync by hand failed', ['stripe_invoice_id' => $invoiceId, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }

        BillingLog::info('Stripe invoice synced by hand', ['stripe_invoice_id' => $invoiceId] + $result);

        $line = "{$invoiceId}: {$result['outcome']}".(isset($result['user_id']) ? " (user {$result['user_id']})" : '');
        if (! $result['applied']) {
            $this->error($line);

            return self::FAILURE;
        }

        $this->info($line);

        return self::SUCCESS;
    }
}
