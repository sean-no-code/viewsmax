<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Pricing + Features ticket: numeric plan limits, the offers cap
 * (with soft-delete), and downgrade-over-cap blocking. Channels/posts limits
 * are stored in plan data but intentionally left dormant — CheckPlan gates by
 * plan name rather than by these numeric limits.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    /** Log in and return auth headers, mirroring OfferFlowTest. */
    private function authHeaders(User $user): array
    {
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $login->assertOk();

        return [
            'Authorization' => 'Bearer ' . $login->json('data.token'),
            'Accept' => 'application/json',
        ];
    }

    private function plan(string $name, array $limits = [], ?string $priceId = null): Plan
    {
        return Plan::create(array_merge([
            'name' => $name,
            'display_name' => ucfirst($name),
            'price' => 29.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'stripe_price_id' => $priceId,
        ], $limits));
    }

    private function subscribe(User $user, Plan $plan): void
    {
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_' . $plan->name,
            'stripe_price_id' => $plan->stripe_price_id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);
    }

    private function createOffer(array $headers, string $name): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers)->postJson('/api/tracking-events', [
            'name' => $name,
            'offer_url' => 'https://example.com/' . str()->slug($name),
        ]);
    }

    // --- Plan data ---------------------------------------------------------

    public function test_seeded_plans_expose_numeric_limits(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->getJson('/api/plans');
        $response->assertOk();

        $plans = collect($response->json('data'))->keyBy('name');

        $this->assertSame(5, $plans['starter']['max_channels']);
        $this->assertSame(1, $plans['starter']['max_offers']);
        $this->assertSame(400, $plans['starter']['max_posts_per_month']);

        $this->assertSame(30, $plans['creator']['max_channels']);
        $this->assertSame(5, $plans['creator']['max_offers']);
        $this->assertNull($plans['creator']['max_posts_per_month']); // unlimited

        $this->assertNull($plans['pro']['max_channels']); // unlimited
        $this->assertSame(10, $plans['pro']['max_offers']);

        $this->assertNull($plans['agency']['max_offers']); // unlimited
    }

    public function test_seeding_retires_legacy_plans_without_deleting_them(): void
    {
        // Simulate pre-existing legacy plans (as on staging/prod).
        $legacy = Plan::create([
            'name' => 'creator_pro',
            'display_name' => 'Creator Pro',
            'price' => 29.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $this->seed(\Database\Seeders\PlanSeeder::class);

        // Row still exists (no data loss / subscribers untouched) but is inactive.
        $this->assertDatabaseHas('plans', [
            'id' => $legacy->id,
            'name' => 'creator_pro',
            'is_active' => false,
        ]);

        // ...and it's gone from the public pricing page, which now shows the tiers.
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);
        $names = collect($this->withHeaders($headers)->getJson('/api/plans')->json('data'))
            ->pluck('name');

        $this->assertFalse($names->contains('creator_pro'));
        $this->assertTrue($names->contains('starter'));
        $this->assertTrue($names->contains('agency'));
    }

    // --- Offers cap --------------------------------------------------------

    public function test_offer_creation_is_blocked_at_the_plan_limit(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan('starter', ['max_offers' => 1]));
        $headers = $this->authHeaders($user);

        $this->createOffer($headers, 'First')->assertCreated();

        $blocked = $this->createOffer($headers, 'Second');
        $blocked->assertStatus(422);
        $this->assertStringContainsString('offer', strtolower($blocked->json('message') ?? ''));
    }

    public function test_soft_deleted_offer_does_not_count_toward_the_limit(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan('starter', ['max_offers' => 1]));
        $headers = $this->authHeaders($user);

        $first = $this->createOffer($headers, 'First');
        $first->assertCreated();
        $offerId = $first->json('id');

        // Soft-delete it, then a new offer should be allowed again.
        $this->withHeaders($headers)->deleteJson("/api/tracking-events/{$offerId}")
            ->assertNoContent();

        $this->createOffer($headers, 'Second')->assertCreated();

        // The deleted one is hidden from listings.
        $this->withHeaders($headers)->getJson('/api/tracking-events')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_without_an_active_plan_is_capped_at_the_free_limit(): void
    {
        // A past_due / expired / never-subscribed user has no *active* plan. They
        // must fall back to the Free tier's cap (0 offers) — NOT get unlimited.
        $this->plan('free', ['max_offers' => 0]);

        $user = User::factory()->create();
        // Simulate a lapsed subscription: pivot exists but is past_due (inactive).
        $past = $this->plan('pro', ['max_offers' => 10], 'price_pro');
        $user->plans()->attach($past->id, [
            'stripe_subscription_id' => 'sub_pastdue',
            'stripe_price_id' => 'price_pro',
            'status' => 'past_due',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);

        $headers = $this->authHeaders($user);

        $blocked = $this->createOffer($headers, 'Should be blocked');
        $blocked->assertStatus(422);
        $this->assertStringContainsString('offer', strtolower($blocked->json('message') ?? ''));
    }

    public function test_unlimited_plan_allows_many_offers(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan('agency', ['max_offers' => null]));
        $headers = $this->authHeaders($user);

        $this->createOffer($headers, 'One')->assertCreated();
        $this->createOffer($headers, 'Two')->assertCreated();
        $this->createOffer($headers, 'Three')->assertCreated();
    }

    // --- Downgrade over cap ------------------------------------------------

    public function test_downgrade_is_blocked_when_over_the_target_offer_cap(): void
    {
        $user = User::factory()->create();
        $pro = $this->plan('pro', ['max_offers' => 10], 'price_pro');
        $this->plan('starter', ['max_offers' => 1], 'price_starter');
        $this->subscribe($user, $pro);
        $headers = $this->authHeaders($user);

        // Three active offers — over the Starter cap of 1.
        $this->createOffer($headers, 'One')->assertCreated();
        $this->createOffer($headers, 'Two')->assertCreated();
        $this->createOffer($headers, 'Three')->assertCreated();

        // Stripe must not be touched when the downgrade is blocked.
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('changeSubscriptionPrice');
        });

        $response = $this->withHeaders($headers)->postJson('/api/user-plans/change-plan', [
            'price_id' => 'price_starter',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('delete', strtolower($response->json('message') ?? ''));
    }

    public function test_upgrade_is_never_blocked_by_the_offer_cap(): void
    {
        $user = User::factory()->create();
        $starter = $this->plan('starter', ['max_offers' => 1], 'price_starter');
        $this->plan('pro', ['max_offers' => 10], 'price_pro');
        $this->subscribe($user, $starter);
        $headers = $this->authHeaders($user);

        // At the Starter cap (1 offer); upgrading to Pro must still go through.
        $this->createOffer($headers, 'Only one')->assertCreated();

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('changeSubscriptionPrice')
                ->once()
                ->with('sub_starter', 'price_pro')
                ->andReturn(['status' => 'active']);
        });

        $response = $this->withHeaders($headers)->postJson('/api/user-plans/change-plan', [
            'price_id' => 'price_pro',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_upgrade_to_unlimited_tier_is_allowed_over_any_count(): void
    {
        $user = User::factory()->create();
        $creator = $this->plan('creator', ['max_offers' => 5], 'price_creator');
        $this->plan('agency', ['max_offers' => null], 'price_agency'); // unlimited
        $this->subscribe($user, $creator);
        $headers = $this->authHeaders($user);

        foreach (['a', 'b', 'c', 'd', 'e'] as $n) {
            $this->createOffer($headers, "Offer {$n}")->assertCreated();
        }

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('changeSubscriptionPrice')->once()->andReturn(['status' => 'active']);
        });

        $this->withHeaders($headers)->postJson('/api/user-plans/change-plan', [
            'price_id' => 'price_agency',
        ])->assertOk();
    }

    public function test_downgrade_proceeds_when_within_the_target_cap(): void
    {
        $user = User::factory()->create();
        $pro = $this->plan('pro', ['max_offers' => 10], 'price_pro');
        $this->plan('starter', ['max_offers' => 1], 'price_starter');
        $this->subscribe($user, $pro);
        $headers = $this->authHeaders($user);

        $this->createOffer($headers, 'Only one')->assertCreated();

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('changeSubscriptionPrice')
                ->once()
                ->andReturn(['status' => 'active']);
        });

        $response = $this->withHeaders($headers)->postJson('/api/user-plans/change-plan', [
            'price_id' => 'price_starter',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_upgrade_updates_local_plan_so_the_new_cap_applies_immediately(): void
    {
        // After upgrading Starter -> Pro the local subscription must point at Pro
        // immediately (not wait on the webhook), so the higher offer cap applies.
        $user = User::factory()->create();
        $starter = $this->plan('starter', ['max_offers' => 1], 'price_starter');
        $pro = $this->plan('pro', ['max_offers' => 10], 'price_pro');
        $this->subscribe($user, $starter);
        $headers = $this->authHeaders($user);

        $this->createOffer($headers, 'First')->assertCreated();

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('changeSubscriptionPrice')->once()->andReturn(['status' => 'active']);
        });

        $this->withHeaders($headers)->postJson('/api/user-plans/change-plan', [
            'price_id' => 'price_pro',
        ])->assertOk();

        // The active subscription row now references Pro + the Pro price.
        $this->assertDatabaseHas('user_plans', [
            'user_id' => $user->id,
            'plan_id' => $pro->id,
            'stripe_price_id' => 'price_pro',
            'status' => 'active',
        ]);

        // ...and a second offer is now allowed under the Pro cap.
        $this->createOffer($headers, 'Second')->assertCreated();
    }

    public function test_change_plan_works_while_subscription_is_trialing(): void
    {
        // During the free trial the pivot status is 'trialing', not 'active'.
        // Upgrading/downgrading must still find the subscription.
        $user = User::factory()->create();
        $creator = $this->plan('creator', ['max_offers' => 5], 'price_creator');
        $pro = $this->plan('pro', ['max_offers' => 10], 'price_pro');

        $user->plans()->attach($creator->id, [
            'stripe_subscription_id' => 'sub_trialing',
            'stripe_price_id' => 'price_creator',
            'status' => 'trialing',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);
        $headers = $this->authHeaders($user);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('changeSubscriptionPrice')
                ->once()
                ->with('sub_trialing', 'price_pro')
                ->andReturn(['status' => 'trialing']);
        });

        $this->withHeaders($headers)->postJson('/api/user-plans/change-plan', [
            'price_id' => 'price_pro',
        ])->assertOk();

        $this->assertDatabaseHas('user_plans', [
            'user_id' => $user->id,
            'plan_id' => $pro->id,
            'stripe_price_id' => 'price_pro',
            'status' => 'trialing',
        ]);
    }

    public function test_paid_subscriptions_allocate_the_uniform_credit_amount(): void
    {
        // Credits aren't per-tier yet — every paid plan gets the same amount,
        // and the trial gets its own flat amount.
        $credits = app(\App\Services\CreditService::class);
        $plan = $this->plan('pro', [], 'price_pro');

        $this->assertSame(1000, $credits->getSubscriptionCredits($plan));      // uniform paid
        $this->assertSame(250, $credits->getSubscriptionCredits($plan, true)); // trial
    }

    // --- New subscription honors the chosen tier --------------------------

    public function test_subscribe_uses_the_selected_tier_price_not_the_trial_price(): void
    {
        // The user clicked "Agency" — the subscription must be created on the
        // Agency price and recorded against the Agency plan, NOT the fallback
        // trial price / default plan.
        config(['services.stripe.trial_price_id' => 'price_trial_fallback']);

        $user = User::factory()->create();
        $this->plan('starter', ['max_offers' => 1], 'price_starter');
        $agency = $this->plan('agency', ['max_offers' => null], 'price_agency');
        $headers = $this->authHeaders($user);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('attachPaymentMethod')->once();
            $mock->shouldReceive('createTrialSubscription')
                ->once()
                ->with(\Mockery::any(), 'price_agency', \Mockery::any())
                ->andReturn([
                    'id' => 'sub_agency_test',
                    'status' => 'trialing',
                    'current_period_end' => now()->addMonth()->timestamp,
                ]);
        });

        $response = $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_test',
            'price_id' => 'price_agency',
        ]);

        $response->assertOk()->assertJsonPath('data.plan_id', 'price_agency');

        $this->assertDatabaseHas('user_plans', [
            'user_id' => $user->id,
            'plan_id' => $agency->id,
            'stripe_price_id' => 'price_agency',
        ]);
    }

    // --- Offer URL alias (landing_page_url <-> offer_url) ------------------

    public function test_offer_creation_accepts_legacy_landing_page_url_alias(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, $this->plan('agency', ['max_offers' => null]));
        $headers = $this->authHeaders($user);

        // The SPA still posts `landing_page_url`; the column is now `offer_url`.
        $response = $this->withHeaders($headers)->postJson('/api/tracking-events', [
            'name' => 'Legacy field offer',
            'landing_page_url' => 'https://example.com/legacy',
        ]);

        $response->assertCreated();
        // Response carries both names so the SPA can keep reading the old one.
        $response->assertJsonPath('offer_url', 'https://example.com/legacy');
        $response->assertJsonPath('landing_page_url', 'https://example.com/legacy');

        $this->assertDatabaseHas('tracking_events', [
            'offer_url' => 'https://example.com/legacy',
        ]);
    }
}
