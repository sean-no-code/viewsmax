<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Per-tier limits and credits are read from env (with sensible defaults)
        // and stripe_price_id from config services.stripe.prices — so a tier is
        // tuned by setting env vars and re-running this seeder. `price` is only a default for a brand-new
        // row: once a plan is linked to a Stripe price, plans:sync-stripe-prices
        // owns price + currency and re-seeding leaves them alone. NULL means "unlimited" for a limit. Returns
        // null when an env var is unset *and* the default is null (= unlimited).
        $num = fn (string $key, ?int $default = null): ?int => ($v = env($key, $default)) === null ? null : (int) $v;

        // The three paid tiers on sale (Pro was retired 2026-11-23, see below). `features` lists
        // the tier's limits for display; offers are deliberately absent — they are
        // monetization, which is the Agency tier's feature, and the SPA builds the
        // card bullets from the numeric limits anyway (lib/plan-helpers.ts).
        $plans = [
            [
                'name' => 'free',
                'display_name' => 'Free Plan',
                'description' => 'Basic features with limited access',
                'price' => 0.00,
                'currency' => 'USD',
                'billing_cycle' => null,
                'features' => [
                    'Review And Optimise Content',
                    'Topic Finder',
                    'Monetization',
                ],
                'max_channels' => $num('LIMIT_FREE_CHANNELS', 0),
                'max_offers' => $num('LIMIT_FREE_OFFERS', 0),
                'max_posts_per_month' => $num('LIMIT_FREE_POSTS', 0),
                'stripe_price_id' => null,
                'is_active' => true,
            ],
            [
                'name' => 'starter',
                'display_name' => 'Starter',
                'description' => 'For creators just getting started',
                'price' => 19.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features' => [
                    '5 channels',
                    '400 posts/mo',
                ],
                'max_channels' => $num('LIMIT_STARTER_CHANNELS', 5),
                'max_offers' => $num('LIMIT_STARTER_OFFERS', 1),
                'max_posts_per_month' => $num('LIMIT_STARTER_POSTS', 400),
                'stripe_price_id' => config('services.stripe.prices.starter'),
                'is_active' => true,
            ],
            [
                'name' => 'creator',
                'display_name' => 'Creator',
                'description' => 'For growing creators running multiple offers',
                'price' => 39.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features' => [
                    '30 channels',
                    'Unlimited posts/mo',
                ],
                'max_channels' => $num('LIMIT_CREATOR_CHANNELS', 30),
                'max_offers' => $num('LIMIT_CREATOR_OFFERS', 5),
                'max_posts_per_month' => $num('LIMIT_CREATOR_POSTS'), // null = unlimited
                'stripe_price_id' => config('services.stripe.prices.creator'),
                'is_active' => true,
            ],
            [
                'name' => 'agency',
                'display_name' => 'Agency',
                'description' => 'Unlimited everything for agencies',
                'price' => 79.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features' => [
                    'Unlimited channels',
                    'Unlimited posts/mo',
                ],
                'max_channels' => $num('LIMIT_AGENCY_CHANNELS'), // null = unlimited
                'max_offers' => $num('LIMIT_AGENCY_OFFERS'),   // null = unlimited
                'max_posts_per_month' => $num('LIMIT_AGENCY_POSTS'), // null = unlimited
                'stripe_price_id' => config('services.stripe.prices.agency'),
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            $existing = Plan::where('name', $plan['name'])->first();
            if ($existing?->stripe_price_id) {
                unset($plan['price'], $plan['currency']);
            }

            Plan::updateOrCreate(
                ['name' => $plan['name']],
                $plan
            );
        }

        // Retire plans that are no longer sold: keep the rows (existing subscribers,
        // their user_plans pivots and the Stripe price id their renewals resolve to
        // are untouched) but hide them from the pricing page by marking inactive.
        // No-op if a name isn't present. Pro went on 2026-11-23, when analytics
        // moved down to Creator; a fresh database never gets the row.
        Plan::whereIn('name', ['pro', 'creator_pro', 'creator_elite', 'creator_agency'])
            ->update(['is_active' => false]);
    }
}
