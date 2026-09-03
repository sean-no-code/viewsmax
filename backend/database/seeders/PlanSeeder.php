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
        // Per-tier limits and credits are read from env (with sensible defaults),
        // same as stripe_price_id — so a tier is tuned by setting env vars and
        // re-running this seeder. NULL means "unlimited" for a limit. Returns
        // null when an env var is unset *and* the default is null (= unlimited).
        $num = fn (string $key, ?int $default = null): ?int => ($v = env($key, $default)) === null ? null : (int) $v;

        // The four paid tiers from the Pricing + Features ticket.
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
                'price' => 29.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features' => [
                    '5 channels',
                    '1 offer',
                    '400 posts / month',
                ],
                'max_channels' => $num('LIMIT_STARTER_CHANNELS', 5),
                'max_offers' => $num('LIMIT_STARTER_OFFERS', 1),
                'max_posts_per_month' => $num('LIMIT_STARTER_POSTS', 400),
                'stripe_price_id' => env('STRIPE_PRICE_STARTER'),
                'is_active' => true,
            ],
            [
                'name' => 'creator',
                'display_name' => 'Creator',
                'description' => 'For growing creators running multiple offers',
                'price' => 59.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features' => [
                    '30 channels',
                    '5 offers',
                    'Unlimited posts',
                ],
                'max_channels' => $num('LIMIT_CREATOR_CHANNELS', 30),
                'max_offers' => $num('LIMIT_CREATOR_OFFERS', 5),
                'max_posts_per_month' => $num('LIMIT_CREATOR_POSTS'), // null = unlimited
                'stripe_price_id' => env('STRIPE_PRICE_CREATOR'),
                'is_active' => true,
            ],
            [
                'name' => 'pro',
                'display_name' => 'Pro',
                'description' => 'For professionals scaling their reach',
                'price' => 99.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features' => [
                    'Unlimited channels',
                    '10 offers',
                    'Unlimited posts',
                ],
                'max_channels' => $num('LIMIT_PRO_CHANNELS'), // null = unlimited
                'max_offers' => $num('LIMIT_PRO_OFFERS', 10),
                'max_posts_per_month' => $num('LIMIT_PRO_POSTS'), // null = unlimited
                'stripe_price_id' => env('STRIPE_PRICE_PRO'),
                'is_active' => true,
            ],
            [
                'name' => 'agency',
                'display_name' => 'Agency',
                'description' => 'Unlimited everything for agencies',
                'price' => 149.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features' => [
                    'Unlimited channels',
                    'Unlimited offers',
                    'Unlimited posts',
                ],
                'max_channels' => $num('LIMIT_AGENCY_CHANNELS'), // null = unlimited
                'max_offers' => $num('LIMIT_AGENCY_OFFERS'),   // null = unlimited
                'max_posts_per_month' => $num('LIMIT_AGENCY_POSTS'), // null = unlimited
                'stripe_price_id' => env('STRIPE_PRICE_AGENCY'),
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['name' => $plan['name']],
                $plan
            );
        }

        // Retire superseded legacy plans: keep the rows (existing subscribers and
        // their user_plans pivots are untouched) but hide them from the pricing
        // page by marking inactive. No-op if a name isn't present.
        Plan::whereIn('name', ['creator_pro', 'creator_elite', 'creator_agency'])
            ->update(['is_active' => false]);
    }
}
