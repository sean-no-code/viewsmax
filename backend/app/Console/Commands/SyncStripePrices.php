<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\StripeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe is the source of truth for what a plan costs. Each plan linked to a
 * Stripe price (plans.stripe_price_id, set from STRIPE_PRICE_* by PlanSeeder)
 * gets that price's amount and currency copied onto the row that /api/plans,
 * Billing and the trial checkout display. Runs on deploy and daily, so a
 * price changed in the Stripe dashboard shows up without a code change.
 */
class SyncStripePrices extends Command
{
    protected $signature = 'plans:sync-stripe-prices {--dry-run : Show what would change without writing}';

    protected $description = 'Copy each plan\'s current amount and currency from its linked Stripe price';

    public function handle(StripeService $stripe): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $failed = 0;
        $rows = [];

        foreach (Plan::whereNotNull('stripe_price_id')->orderBy('price')->get() as $plan) {
            try {
                $price = $stripe->retrievePrice($plan->stripe_price_id);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$plan->name}: {$e->getMessage()}");
                Log::error('Stripe price sync failed', ['plan' => $plan->name, 'price_id' => $plan->stripe_price_id, 'error' => $e->getMessage()]);

                continue;
            }

            $changed = (float) $plan->price !== $price['amount'] || $plan->currency !== $price['currency'];
            $rows[] = [$plan->name, $plan->stripe_price_id, number_format((float) $plan->price, 2).' '.$plan->currency, number_format($price['amount'], 2).' '.$price['currency'], $changed ? ($dryRun ? 'would update' : 'updated') : 'unchanged'];

            if ($changed && ! $dryRun) {
                $plan->forceFill(['price' => $price['amount'], 'currency' => $price['currency']])->save();
            }
        }

        $this->table(['plan', 'stripe price', 'was', 'stripe', 'result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
