<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\KitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SubmitAbandonedCartsTest extends TestCase
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
        ]);

        $this->kit = \Mockery::mock(KitService::class);
        $this->app->instance(KitService::class, $this->kit);
    }

    public function test_submits_eligible_users_with_the_abandoned_tag(): void
    {
        $this->makeUser('abandoned@example.com', hoursAgo: 2);

        $this->kit->shouldReceive('subscribe')->once()
            ->with('abandoned@example.com', 'Lead', 'viewsmax: abandoned cart')->andReturn(true);

        $this->artisan('kit:submit-abandoned-carts', ['--force' => true])->assertSuccessful();
    }

    public function test_email_option_targets_a_single_user(): void
    {
        $this->makeUser('a@example.com', hoursAgo: 2);
        $this->makeUser('b@example.com', hoursAgo: 2);

        $this->kit->shouldReceive('subscribe')->once()
            ->with('a@example.com', 'Lead', 'viewsmax: abandoned cart')->andReturn(true);

        $this->artisan('kit:submit-abandoned-carts', ['--email' => 'a@example.com', '--force' => true])->assertSuccessful();
    }

    public function test_skips_users_who_ever_subscribed(): void
    {
        $user = $this->makeUser('paid@example.com', hoursAgo: 2);
        $this->giveSubscription($user);

        $this->kit->shouldReceive('subscribe')->never();

        $this->artisan('kit:submit-abandoned-carts', ['--force' => true])->assertSuccessful();
    }

    public function test_does_nothing_when_kit_disabled(): void
    {
        config(['services.kit.enabled' => false]);
        $this->makeUser('a@example.com', hoursAgo: 2);

        $this->kit->shouldReceive('subscribe')->never();

        $this->artisan('kit:submit-abandoned-carts', ['--force' => true])->assertSuccessful();
    }

    private function makeUser(string $email, int $hoursAgo): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Lead']);
        $user->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();

        return $user->refresh();
    }

    private function giveSubscription(User $user): void
    {
        $plan = Plan::create([
            'name' => 'starter-test', 'display_name' => 'Starter', 'description' => 'test',
            'price' => 29, 'currency' => 'usd', 'billing_cycle' => 'monthly', 'features' => [],
            'is_active' => true, 'max_channels' => 1, 'max_offers' => 1, 'max_posts_per_month' => 10,
            'stripe_price_id' => 'price_123',
        ]);
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_123', 'stripe_price_id' => 'price_123',
            'status' => 'trialing', 'starts_at' => now(), 'expires_at' => now()->addDays(3),
        ]);
    }
}
