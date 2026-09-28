<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictFreePlan;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Promotional customers: admin-created users with free access for N days (or
 * forever) and no card on file. While the window is open they count as
 * subscribed (onboarding skips the card step, feature routes work). Once it
 * closes they are locked to the billing endpoints until they subscribe.
 */
class PromotionalAccessTest extends TestCase
{
    use RefreshDatabase;

    private function promoUser(?\DateTimeInterface $expiresAt, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['promo_expires_at' => $expiresAt], $attrs));
        $role = Role::firstOrCreate(['name' => 'promotional_customer'], ['display_name' => 'Promotional customer']);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    // --- Active promo --------------------------------------------------------

    public function test_active_promo_counts_as_subscribed_in_the_login_payload(): void
    {
        $user = $this->promoUser(now()->addDays(7));

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true)
            ->assertJsonPath('data.user.has_active_plan', false)
            ->assertJsonPath('data.user.access_expired', false)
            ->assertJsonPath('data.user.promo_expires_at', $user->promo_expires_at->toIso8601String());
    }

    public function test_unlimited_promo_never_expires(): void
    {
        $user = $this->promoUser(null);

        $this->withHeaders($this->authHeaders($user))->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true)
            ->assertJsonPath('data.user.access_expired', false)
            ->assertJsonPath('data.user.promo_expires_at', null);
    }

    public function test_promo_user_completes_onboarding_without_a_card(): void
    {
        $user = $this->promoUser(now()->addDays(14));

        $this->withHeaders($this->authHeaders($user))->postJson('/api/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true);

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_promo_user_is_not_capped_at_the_free_offer_limit(): void
    {
        Plan::create(['name' => 'free', 'display_name' => 'Free', 'price' => 0, 'currency' => 'USD', 'is_active' => true, 'max_offers' => 0]);
        $user = $this->promoUser(now()->addDays(7));

        $this->withHeaders($this->authHeaders($user))->postJson('/api/tracking-events', [
            'name' => 'Promo offer',
            'offer_url' => 'https://example.com/promo',
        ])->assertCreated();
    }

    public function test_restrict_free_middleware_lets_an_active_promo_user_write(): void
    {
        $user = $this->promoUser(now()->addDays(7));
        $request = Request::create('/api/titles', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = (new RestrictFreePlan)->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_plain_customer_without_a_plan_is_still_unsubscribed(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeaders($user))->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', false)
            ->assertJsonPath('data.user.access_expired', false);
    }

    // --- Expired promo -------------------------------------------------------

    public function test_expired_promo_is_flagged_and_no_longer_subscribed(): void
    {
        $user = $this->promoUser(now()->subMinute());

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', false)
            ->assertJsonPath('data.user.access_expired', true);
    }

    public function test_expired_promo_is_blocked_from_feature_routes(): void
    {
        $user = $this->promoUser(now()->subDay(), ['onboarding_completed_at' => now()->subWeek()]);
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->getJson('/api/posts')
            ->assertForbidden()
            ->assertJsonPath('code', 'access_expired');

        $this->withHeaders($headers)->postJson('/api/tracking-events', [
            'name' => 'Nope',
            'offer_url' => 'https://example.com/nope',
        ])->assertForbidden()->assertJsonPath('code', 'access_expired');

        $this->withHeaders($headers)->postJson('/api/onboarding/complete')
            ->assertForbidden()->assertJsonPath('code', 'access_expired');
    }

    public function test_expired_promo_can_still_reach_billing_and_account_routes(): void
    {
        $user = $this->promoUser(now()->subDay());
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->getJson('/api/profile')->assertOk();
        $this->withHeaders($headers)->getJson('/api/plans')->assertOk();
        $this->withHeaders($headers)->getJson('/api/billing/status')->assertOk();
        // 404 = "no active plan", i.e. the route ran rather than being blocked.
        $this->withHeaders($headers)->getJson('/api/user-plans/current')->assertNotFound();
    }

    public function test_expired_promo_user_who_subscribed_is_unlocked(): void
    {
        $user = $this->promoUser(now()->subDay());
        $plan = Plan::create(['name' => 'starter', 'display_name' => 'Starter', 'price' => 29, 'currency' => 'USD', 'is_active' => true]);
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_real',
            'status' => 'trialing',
            'starts_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true)
            ->assertJsonPath('data.user.has_active_plan', true)
            ->assertJsonPath('data.user.access_expired', false);

        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
    }

    public function test_promo_expiry_is_ignored_for_users_without_the_role(): void
    {
        // A stale promo_expires_at on a plain customer must not lock them out.
        $user = User::factory()->create(['promo_expires_at' => now()->subDay()]);
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.access_expired', false);
        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
    }
}
