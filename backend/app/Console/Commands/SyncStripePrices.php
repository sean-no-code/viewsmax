<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\StripeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe is the source of truth for what a plan costs. For each paid tier the
 * configured price id (config services.stripe.prices, from STRIPE_PRICE_*) is
 * written to plans.stripe_price_id, then that price's amount and currency are
 * copied onto the row that /api/plans, Billing and the trial checkout display.
 * Runs on deploy and daily, so pointing STRIPE_PRICE_* at a new price in the
 * dashboard is all a price change takes.
 */
class SyncStripePrices extends Command
{
    protected $signature = 'plans:sync-stripe-prices {--dry-run : Show what would change without writing}';

    protected $description = 'Link each plan to its configured Stripe price and copy the current amount and currency';

    public function handle(StripeService $stripe): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $configured = array_filter((array) config('services.stripe.prices', []));
        $failed = 0;
        $rows = [];

        $plans = Plan::whereNotNull('stripe_price_id')
            ->orWhereIn('name', array_keys($configured))
            ->orderBy('price')
            ->get();

        foreach ($plans as $plan) {
            // The configured id wins over the stored one: .env moved to a new
            // Stripe price but nobody re-ran the seeder.
            $priceId = $configured[$plan->name] ?? $plan->stripe_price_id;
            $relinked = $priceId !== $plan->stripe_price_id;

            try {
                $price = $stripe->retrievePrice($priceId);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$plan->name} ({$priceId}): {$e->getMessage()}");
                Log::error('Stripe price sync failed', ['plan' => $plan->name, 'price_id' => $priceId, 'error' => $e->getMessage()]);

                continue;
            }

            $amountChanged = round((float) $plan->price, 2) !== round($price['amount'], 2)
                || $plan->currency !== $price['currency'];
            $changed = $relinked || $amountChanged;

            $rows[] = [
                $plan->name,
                $relinked ? "{$plan->stripe_price_id} → {$priceId}" : $priceId,
                number_format((float) $plan->price, 2).' '.$plan->currency,
                number_format($price['amount'], 2).' '.$price['currency'],
                $changed ? ($dryRun ? 'would update' : 'updated') : 'unchanged',
            ];

            if ($changed && ! $dryRun) {
                $plan->forceFill([
                    'stripe_price_id' => $priceId,
                    'price' => $price['amount'],
                    'currency' => $price['currency'],
                ])->save();
            }
        }

        $this->table(['plan', 'stripe price', 'was', 'stripe', 'result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
