<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Covers subscription lifecycle transitions that aren't user-initiated form posts:
 * graceful cancel (access kept until expiry) and the expiry job
 * (subscriptions:process-expired), including its Stripe re-check that protects a
 * still-active subscriber from being wrongly expired.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function plan(string $name): Plan
    {
        return Plan::create([
            'name' => $name,
            'display_name' => ucfirst($name),
            'price' => 99.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'stripe_price_id' => 'price_' . $name,
        ]);
    }

    /** Attach a live subscription pivot to the user. */
    private function subscribe(User $user, Plan $plan, string $status, $expiresAt, string $subId = 'sub_test'): void
    {
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => $subId,
            'stripe_price_id' => $plan->stripe_price_id,
            'status' => $status,
            'starts_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function pivot(User $user, Plan $plan)
    {
        return $user->plans()->where('plans.id', $plan->id)->first()->pivot;
    }

    public function test_expiry_job_survives_malformed_grace_period_config(): void
    {
        // A set-but-empty env var reaches the command as '' — env('X', 24)
        // only falls back when the var is ABSENT. That empty string used to
        // hit Carbon math ('' * 3600) and TypeError-crash the scheduled job,
        // so expiries silently stopped. Must run AND fall back to 24h.
        config(['services.stripe.subscription_grace_period_hours' => '']);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->andReturn(['status' => 'canceled']);
        });

        $plan = $this->plan('pro');
        $past = User::factory()->create();
        $this->subscribe($past, $plan, 'cancelled', now()->subHours(30), 'sub_past');
        $recent = User::factory()->create();
        $this->subscribe($recent, $plan, 'cancelled', now()->subHours(1), 'sub_recent');

        Artisan::call('subscriptions:process-expired');

        $this->assertSame('expired', $this->pivot($past, $plan)->status);
        // Still inside the default 24h grace — untouched (proves the fallback
        // was 24, not a coerced 0).
        $this->assertSame('cancelled', $this->pivot($recent, $plan)->status);
    }

    // --- Graceful cancel ---------------------------------------------------

    public function test_graceful_cancel_marks_cancelled_but_keeps_access(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $expiry = now()->addDays(20);
        $this->subscribe($user, $plan, 'active', $expiry, 'sub_pro');

        // Stripe call is mocked — cancel-at-period-end, no real API hit.
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->once()->andReturn(['status' => 'active']);
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_pro/cancel', ['reason' => 'testing'])
            ->assertOk();

        $pivot = $this->pivot($user, $plan);
        $this->assertNotNull($pivot->cancelled_at);       // marked cancelled
        $this->assertSame('active', $pivot->status);       // ...but still active
        $this->assertEquals(                               // access window unchanged
            $expiry->toDateString(),
            \Illuminate\Support\Carbon::parse($pivot->expires_at)->toDateString()
        );
    }

    // --- Expiry job --------------------------------------------------------

    public function test_expiry_job_expires_subscription_and_zeroes_credits(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        // Past the 24h grace window.
        $this->subscribe($user, $plan, 'active', now()->subDays(2), 'sub_dead');
        $user->deposit(500); // some credits to be cleared

        // Stripe agrees the subscription is gone → expire + reset.
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->andReturn(['status' => 'canceled']);
        });

        Artisan::call('subscriptions:process-expired');

        $this->assertSame('expired', $this->pivot($user, $plan)->status);
        $this->assertSame(0, $user->fresh()->balanceInt);
    }

    public function test_expiry_job_corrects_back_to_active_when_stripe_still_reports_active(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->subDays(2), 'sub_live');
        $user->deposit(500);

        // Stripe says it's still live (our expires_at was just stale) → heal it.
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->andReturn([
                'status' => 'active',
                'current_period_end' => now()->addMonth()->timestamp,
            ]);
        });

        Artisan::call('subscriptions:process-expired');

        $pivot = $this->pivot($user, $plan);
        $this->assertSame('active', $pivot->status);                 // not expired
        $this->assertTrue(\Illuminate\Support\Carbon::parse($pivot->expires_at)->isFuture());
        $this->assertSame(500, $user->fresh()->balanceInt);          // credits untouched
    }

    public function test_expiry_job_expires_a_lapsed_trial(): void
    {
        // A trial that was cancelled and then lapsed stays status='trialing' (not
        // 'active'/'cancelled'), so the candidate query must include 'trialing' —
        // otherwise the user would keep access forever.
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'trialing', now()->subDays(2), 'sub_trial_dead');
        $user->plans()->updateExistingPivot($plan->id, ['cancelled_at' => now()->subDays(2)]);
        $user->deposit(250);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->andReturn(['status' => 'canceled']);
        });

        Artisan::call('subscriptions:process-expired');

        $this->assertSame('expired', $this->pivot($user, $plan)->status);
        $this->assertSame(0, $user->fresh()->balanceInt);
    }

    public function test_expiry_job_leaves_subscriptions_within_the_grace_window_alone(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        // Only 1h past expiry — inside the 24h grace, so not a candidate.
        $this->subscribe($user, $plan, 'active', now()->subHour(), 'sub_grace');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('getSubscription');
        });

        Artisan::call('subscriptions:process-expired');

        $this->assertSame('active', $this->pivot($user, $plan)->status);
    }
}
