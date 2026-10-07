<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Self-signup needs no card and has no time limit: registering grants the
 * free credits (config credits.registration_bonus) and records
 * `free_credits_at`. While the user has credits left they count as
 * subscribed, onboarding skips the card step and the app is unlocked. Once
 * the credits are used up (and they have no plan) they are locked to the
 * billing endpoints. Subscribing charges straight away: the free credits
 * were the trial.
 */
class FreeCreditsSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-02 10:00:00');
        Mail::fake();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
        config(['credits.registration_bonus' => 100]);
        // Each test runs in a transaction that never commits, so the wallet
        // keeps balances in its cache instead of the wallets table. Keep that
        // cache alive across the time jumps below (default TTL is 24h).
        config(['wallet.cache.ttl' => 10 * 365 * 24 * 3600]);
    }

    private function register(): User
    {
        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'starter', 'display_name' => 'Starter', 'price' => 29, 'currency' => 'USD',
            'is_active' => true, 'stripe_price_id' => 'price_starter',
        ]);
    }

    private function useUpCredits(User $user): void
    {
        $user = $user->fresh();
        $user->forceWithdraw($user->balanceInt, ['description' => 'test: spend all']);
    }

    /** Mock Stripe and capture the trial terms the subscription is created with. */
    private function expectSubscription(?array &$trial, string $status): void
    {
        $this->mock(StripeService::class, function ($mock) use (&$trial, $status) {
            $mock->shouldReceive('attachPaymentMethod')->once();
            $mock->shouldReceive('createSubscription')->once()
                ->andReturnUsing(function ($user, $priceId, $terms) use (&$trial, $status) {
                    $trial = $terms;

                    return ['id' => 'sub_new', 'status' => $status, 'current_period_end' => now()->addMonth()->getTimestamp()];
                });
        });
    }

    public function test_registering_grants_free_credits_with_no_time_limit(): void
    {
        $user = $this->register();

        $this->assertSame(100, $user->fresh()->balanceInt);
        $this->assertNull($user->promo_expires_at);
        $this->assertSame('2026-10-02 10:00:00', $user->free_credits_at->toDateTimeString());
        $this->assertSame(['customer'], $user->roles()->pluck('name')->all());

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true)
            ->assertJsonPath('data.user.has_active_plan', false)
            ->assertJsonPath('data.user.on_free_credits', true)
            ->assertJsonPath('data.user.access_expired', false);
    }

    public function test_onboarding_completes_without_a_card_while_credits_remain(): void
    {
        $user = $this->register();

        $this->withHeaders($this->authHeaders($user))->postJson('/api/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_the_app_stays_open_long_after_signup_while_credits_remain(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);

        Carbon::setTestNow('2027-10-02 10:00:00'); // a year later, credits untouched

        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.access_expired', false);
    }

    public function test_using_up_the_credits_sends_the_user_to_billing(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();

        $this->useUpCredits($user);

        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', false)
            ->assertJsonPath('data.user.access_expired', true);
        $this->withHeaders($headers)->getJson('/api/posts')
            ->assertForbidden()
            ->assertJsonPath('code', 'access_expired');
        $this->withHeaders($headers)->getJson('/api/plans')->assertOk();
    }

    public function test_free_credit_users_are_not_capped_by_the_free_plan_offer_limit(): void
    {
        Plan::create([
            'name' => 'free', 'display_name' => 'Free', 'price' => 0, 'currency' => 'USD',
            'is_active' => true, 'max_offers' => 0,
        ]);
        $user = $this->register();

        $this->withHeaders($this->authHeaders($user))->postJson('/api/tracking-events', [
            'name' => 'First offer',
            'offer_url' => 'https://example.com/first',
        ])->assertCreated();
    }

    public function test_subscribing_charges_immediately_because_the_credits_were_the_trial(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->plan();
        $this->expectSubscription($trial, 'active');

        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame([], $trial);
    }

    public function test_a_subscriber_is_not_locked_when_their_balance_hits_zero(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->plan();
        $this->expectSubscription($trial, 'active');
        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk();

        $this->useUpCredits($user);

        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertJsonPath('data.user.has_active_plan', true)
            ->assertJsonPath('data.user.on_free_credits', false)
            ->assertJsonPath('data.user.access_expired', false);
        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
    }

    public function test_an_older_account_without_free_credits_is_not_locked_at_zero(): void
    {
        // Accounts created before free credits existed: no window, no plan, no balance.
        $user = User::factory()->create(['promo_expires_at' => null, 'free_credits_at' => null]);

        $this->withHeaders($this->authHeaders($user))->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.access_expired', false);
    }

    public function test_a_user_with_no_window_still_gets_the_card_trial(): void
    {
        // Accounts created before free credits existed (still at the card step).
        $user = User::factory()->create(['promo_expires_at' => null, 'free_credits_at' => null]);
        $headers = $this->authHeaders($user);
        $this->plan();
        config(['services.stripe.trial_period_days' => 7]);
        $this->expectSubscription($trial, 'trialing');

        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk();

        $this->assertSame(['trial_period_days' => 7], $trial);
    }
}
