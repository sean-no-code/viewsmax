<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\KitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TagAbandonedCartsTest extends TestCase
{
    use RefreshDatabase;

    protected $kit;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'services.kit.api_key' => 'test-kit-key',
            'services.kit.api_secret' => 'test-kit-secret',
            'services.kit.enabled' => true,
            'services.kit.abandoned_cart_tag' => 'viewsmax: abandoned cart',
            'services.kit.abandoned_cart_window_hours' => 3,
            'services.kit.abandoned_cart_min_age_hours' => 1,
        ]);

        // The command method-injects KitService; swap in a mock so nothing hits Kit.
        $this->kit = \Mockery::mock(KitService::class);
        $this->app->instance(KitService::class, $this->kit);
    }

    public function test_tags_eligible_user_created_within_window(): void
    {
        $this->makeUser('abandoned@example.com', hoursAgo: 2);   // in the 1–4h slice, no subscription

        $this->kit->shouldReceive('subscribe')->once()
            ->with('abandoned@example.com', 'Lead', 'viewsmax: abandoned cart')->andReturn(true);

        $this->artisan('kit:tag-abandoned-carts')->assertSuccessful();
    }

    public function test_non_consenter_is_still_tagged(): void
    {
        $this->makeUser('nocon@example.com', hoursAgo: 2, consented: false);

        $this->kit->shouldReceive('subscribe')->once()
            ->with('nocon@example.com', 'Lead', 'viewsmax: abandoned cart')->andReturn(true);

        $this->artisan('kit:tag-abandoned-carts')->assertSuccessful();
    }

    public function test_skips_user_with_a_subscription(): void
    {
        $user = $this->makeUser('paid@example.com', hoursAgo: 2);
        $this->giveSubscription($user);

        $this->kit->shouldReceive('subscribe')->never();

        $this->artisan('kit:tag-abandoned-carts')->assertSuccessful();
    }

    public function test_skips_user_younger_than_min_age(): void
    {
        $this->makeUser('fresh@example.com', hoursAgo: 0);   // <1h old → still registering

        $this->kit->shouldReceive('subscribe')->never();

        $this->artisan('kit:tag-abandoned-carts')->assertSuccessful();
    }

    public function test_skips_user_older_than_window(): void
    {
        $this->makeUser('stale@example.com', hoursAgo: 5);   // outside the 1–4h slice

        $this->kit->shouldReceive('subscribe')->never();

        $this->artisan('kit:tag-abandoned-carts')->assertSuccessful();
    }

    private function makeUser(string $email, int $hoursAgo, bool $consented = true): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Lead',
            'marketing_consented_at' => $consented ? now() : null,
        ]);
        // created_at is managed by Eloquent on insert; force it to backdate the signup.
        $user->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();

        return $user->refresh();
    }

    private function giveSubscription(User $user): void
    {
        $plan = Plan::create([
            'name' => 'starter-test',
            'display_name' => 'Starter',
            'description' => 'test plan',
            'price' => 29,
            'currency' => 'usd',
            'billing_cycle' => 'monthly',
            'features' => [],
            'is_active' => true,
            'max_channels' => 1,
            'max_offers' => 1,
            'max_posts_per_month' => 10,
            'stripe_price_id' => 'price_123',
        ]);

        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_123',
            'stripe_price_id' => 'price_123',
            'status' => 'trialing',
            'starts_at' => now(),
            'expires_at' => now()->addDays(3),
        ]);
    }
}
