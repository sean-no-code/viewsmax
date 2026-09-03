<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListAbandonedCartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_never_subscribed_users_only(): void
    {
        // Abandoned: signed up 2h ago, no subscription.
        $abandoned = User::factory()->create(['email' => 'abandoned@example.com', 'name' => 'Lead']);
        $abandoned->forceFill(['created_at' => now()->subHours(2)])->save();

        // Subscribed: excluded from the list.
        $paid = User::factory()->create(['email' => 'paid@example.com']);
        $paid->forceFill(['created_at' => now()->subHours(2)])->save();
        $plan = Plan::create([
            'name' => 'starter-test', 'display_name' => 'Starter', 'description' => 'test',
            'price' => 29, 'currency' => 'usd', 'billing_cycle' => 'monthly', 'features' => [],
            'is_active' => true, 'max_channels' => 1, 'max_offers' => 1, 'max_posts_per_month' => 10,
            'stripe_price_id' => 'price_123',
        ]);
        $paid->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_123', 'status' => 'trialing',
            'starts_at' => now(), 'expires_at' => now()->addDays(3),
        ]);

        $this->artisan('kit:list-abandoned-carts')
            ->expectsOutputToContain('abandoned@example.com')
            ->assertSuccessful();
    }
}
