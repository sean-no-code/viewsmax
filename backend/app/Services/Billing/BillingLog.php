<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Every billing-email log line goes through here so it lands on the dedicated
 * `billing` channel (storage/logs/billing-*.log, see config/logging.php) with
 * the same context keys. BILLING_LOG_ENABLED=false silences the channel.
 */
class BillingLog
{
    public const CHANNEL = 'billing';

    public static function info(string $message, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::channel(self::CHANNEL)->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        Log::channel(self::CHANNEL)->error($message, $context);
    }

    /**
     * Standard context: the user plus whichever Stripe ids are known. Nulls
     * are dropped so lines stay compact.
     *
     * @return array<string, mixed>
     */
    public static function context(?User $user = null, ?string $subscriptionId = null, ?string $invoiceId = null): array
    {
        return array_filter([
            'user_id' => $user?->id,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_invoice_id' => $invoiceId,
        ], fn ($v) => $v !== null);
    }
}
