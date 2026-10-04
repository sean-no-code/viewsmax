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
 * Self-signup no longer needs a card: registering opens a 7-day free window
 * (the same `promo_expires_at` window promotional customers use, without the
 * role). While it is open the user counts as subscribed and onboarding skips
 * the card step. Once it closes they are locked to the billing endpoints, and
 * subscribing then charges straight away — the 7 days were the trial.
 */
class CardFreeSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-02 10:00:00');
        Mail::fake();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
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

    public function test_registering_opens_a_seven_day_window_with_no_card(): void
    {
        $user = $this->register();

        $this->assertSame('2026-10-09 10:00:00', $user->promo_expires_at->toDateTimeString());
        $this->assertSame(['customer'], $user->roles()->pluck('name')->all());

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true)
            ->assertJsonPath('data.user.has_active_plan', false)
            ->assertJsonPath('data.user.access_expired', false);
    }

    public function test_onboarding_completes_without_a_card_during_the_window(): void
    {
        $user = $this->register();

        $this->withHeaders($this->authHeaders($user))->postJson('/api/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_after_seven_days_the_user_is_sent_to_billing(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);

        Carbon::setTestNow('2026-10-09 09:59:00');
        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();

        Carbon::setTestNow('2026-10-09 10:01:00');
        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', false)
            ->assertJsonPath('data.user.access_expired', true);
        $this->withHeaders($headers)->getJson('/api/posts')
            ->assertForbidden()
            ->assertJsonPath('code', 'access_expired');
        $this->withHeaders($headers)->getJson('/api/plans')->assertOk();
    }

    public function test_subscribing_after_the_window_charges_immediately(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->plan();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->expectSubscription($trial, 'active');

        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame([], $trial);
        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertJsonPath('data.user.has_active_plan', true)
            ->assertJsonPath('data.user.access_expired', false);
    }

    public function test_subscribing_during_the_window_defers_the_charge_to_its_end(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->plan();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->expectSubscription($trial, 'trialing');

        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk()->assertJsonPath('data.status', 'trialing');

        $this->assertSame(['trial_end' => Carbon::parse('2026-10-09 10:00:00')->getTimestamp()], $trial);
    }

    public function test_a_user_with_no_window_still_gets_the_card_trial(): void
    {
        // Accounts created before the card-free window (still at the card step).
        $user = User::factory()->create(['promo_expires_at' => null]);
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
