<?php

namespace Tests\Feature;

use App\Mail\SubscriptionCancelledMail;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
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

    // --- Cancellation reason -----------------------------------------------

    public function test_cancel_reason_reaches_stripe_and_is_recorded(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_pro');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->once()
                ->with('sub_pro', 'Moving to a cheaper tool', false, 'too_expensive')
                ->andReturn(['status' => 'active']);
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_pro/cancel', [
                'feedback' => 'too_expensive',
                'reason' => 'Moving to a cheaper tool',
            ])
            ->assertOk();

        $event = UserEvent::where('user_id', $user->id)
            ->where('event_name', UserEvent::SUBSCRIPTION_CANCELLED)
            ->sole();
        $this->assertSame([
            'feedback' => 'too_expensive',
            'comment' => 'Moving to a cheaper tool',
            'plan' => 'pro',
            'stripe_subscription_id' => 'sub_pro',
        ], $event->metadata);
    }

    public function test_cancel_rejects_unknown_feedback(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_pro');

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldNotReceive('cancelSubscription'));

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_pro/cancel', ['feedback' => 'bored'])
            ->assertStatus(422);

        $this->assertNull($this->pivot($user, $plan)->cancelled_at);
    }

    public function test_cancel_without_feedback_still_cancels(): void
    {
        // An SPA bundle cached from before the reason step sends no feedback.
        // Cancelling must never be blocked by it.
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_pro');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->once()
                ->with('sub_pro', null, false, null)
                ->andReturn(['status' => 'active']);
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_pro/cancel')
            ->assertOk();

        $this->assertNotNull($this->pivot($user, $plan)->cancelled_at);
        $this->assertSame(['feedback' => null, 'comment' => null, 'plan' => 'pro', 'stripe_subscription_id' => 'sub_pro'],
            UserEvent::where('user_id', $user->id)->sole()->metadata);
    }

    // --- Cancellation email -------------------------------------------------

    public function test_cancel_emails_the_access_end_date_from_stripe(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'leaver@example.com', 'name' => 'Lee']);
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_pro');

        // Stripe's period end wins over our (possibly stale) expires_at.
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('cancelSubscription')->once()
            ->andReturn(['status' => 'active', 'cancel_at_period_end' => true, 'cancel_at' => 1_796_592_000]));

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_pro/cancel', ['feedback' => 'unused'])
            ->assertOk();

        Mail::assertSent(SubscriptionCancelledMail::class, 1);
        $mail = Mail::sent(SubscriptionCancelledMail::class)->first();
        $this->assertTrue($mail->hasTo('leaver@example.com'));
        $this->assertSame('Lee', $mail->name);
        $this->assertSame('Pro', $mail->planName);
        $this->assertSame('December 6, 2026 at 9:20pm UTC', $mail->accessUntil);
        $this->assertFalse($mail->endsNow);
        $this->assertStringContainsString('December 6, 2026 at 9:20pm UTC', $mail->render());
    }

    public function test_cancel_email_for_an_immediate_cancel_says_it_ended(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'past_due', now()->subDays(2), 'sub_pd');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->once()
                ->andReturn(['status' => 'canceled', 'ended_at' => 1_794_000_000]);
            $mock->shouldReceive('voidOpenInvoices')->once()->andReturn(0);
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_pd/cancel', ['feedback' => 'other'])
            ->assertOk();

        $mail = Mail::sent(SubscriptionCancelledMail::class)->first();
        $this->assertTrue($mail->endsNow);
        $this->assertSame('November 6, 2026 at 9:20pm UTC', $mail->accessUntil);
    }

    public function test_a_failed_cancel_email_does_not_fail_the_cancel(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_pro');
        $headers = $this->authHeaders($user);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('cancelSubscription')->once()
            ->andReturn(['status' => 'active', 'cancel_at' => 1_796_592_000]));

        $this->withHeaders($headers)->postJson('/api/subscriptions/sub_pro/cancel')->assertOk();

        $this->assertNotNull($this->pivot($user, $plan)->cancelled_at);
        \Illuminate\Support\Facades\Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'SMTP down');
    }

    public function test_cancel_email_can_be_switched_off(): void
    {
        Mail::fake();
        config(['billing.emails.cancellation' => false]);
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_pro');
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('cancelSubscription')->once()
            ->andReturn(['status' => 'active']));

        $this->withHeaders($this->authHeaders($user))->postJson('/api/subscriptions/sub_pro/cancel')->assertOk();

        Mail::assertNotSent(SubscriptionCancelledMail::class);
    }

    // --- Past-due cancel ----------------------------------------------------

    public function test_past_due_subscription_can_be_cancelled_and_ends_now(): void
    {
        // Free window closed too: the access gate must still let them cancel.
        $user = User::factory()->expired()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'past_due', now()->subDays(2), 'sub_pd');

        $this->mock(StripeService::class, function ($mock) {
            // No paid period left, so it ends now, not at period end.
            $mock->shouldReceive('cancelSubscription')->once()
                ->with('sub_pd', null, true, 'too_expensive')
                ->andReturn(['status' => 'canceled', 'ended_at' => now()->timestamp]);
            $mock->shouldReceive('voidOpenInvoices')->once()->with('sub_pd')->andReturn(1);
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_pd/cancel', ['feedback' => 'too_expensive'])
            ->assertOk();

        $pivot = $this->pivot($user, $plan);
        $this->assertSame('expired', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
    }

    // --- Unpaid trial cancel ------------------------------------------------

    /**
     * A trial that is cancelled before anything was charged ends there: no
     * free use of the rest of the window. A paid plan keeps the graceful
     * cancel (test_graceful_cancel_marks_cancelled_but_keeps_access).
     */
    public function test_cancelling_an_unpaid_trial_ends_access_now(): void
    {
        $user = User::factory()->create(['promo_expires_at' => now()->addDays(5)]);
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'trialing', now()->addDays(5), 'sub_trial');

        $this->mock(StripeService::class, function ($mock) {
            // Ended on Stripe now, not at the end of the trial.
            $mock->shouldReceive('cancelSubscription')->once()
                ->with('sub_trial', null, true, 'too_expensive')
                ->andReturn(['status' => 'canceled', 'ended_at' => now()->timestamp]);
            $mock->shouldReceive('voidOpenInvoices')->once()->with('sub_trial')->andReturn(0);
        });

        $headers = $this->authHeaders($user);
        $this->withHeaders($headers)
            ->postJson('/api/subscriptions/sub_trial/cancel', ['feedback' => 'too_expensive'])
            ->assertOk();

        $pivot = $this->pivot($user, $plan);
        $this->assertSame('expired', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
        $this->assertFalse(\Illuminate\Support\Carbon::parse($pivot->expires_at)->isFuture());

        // The free window the trial ran on is closed with it.
        $user->refresh();
        $this->assertFalse($user->freeWindowOpen());
        $this->assertSame(\App\Support\UserAccess::SUBSCRIPTION_INACTIVE, \App\Support\UserAccess::denial($user)?->code);

        // Every query after that is refused with the link to pay.
        $this->withHeaders($headers)->getJson('/api/social/posts')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'subscription_inactive');
    }

    public function test_immediate_cancel_voids_open_invoices(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_now');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->once()
                ->with('sub_now', null, true, null)
                ->andReturn(['status' => 'canceled', 'ended_at' => now()->timestamp]);
            $mock->shouldReceive('voidOpenInvoices')->once()->with('sub_now')->andReturn(0);
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/subscriptions/sub_now/cancel', ['immediately' => true])
            ->assertOk();

        $this->assertNotNull($this->pivot($user, $plan)->cancelled_at);
    }

    /**
     * The old POST /user-plans/cancel marked the row cancelled without telling
     * Stripe, which kept billing (and the next invoice.paid reactivated it).
     * Nothing called it; the Stripe-backed route is the only way to cancel.
     */
    public function test_local_only_cancel_route_is_gone(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->addDays(20), 'sub_pro');
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldNotReceive('cancelSubscription'));

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/user-plans/cancel')
            ->assertNotFound();

        $pivot = $this->pivot($user, $plan);
        $this->assertSame('active', $pivot->status);
        $this->assertNull($pivot->cancelled_at);
    }

    // --- Plan change on a cancelled subscription ---------------------------

    public function test_plan_change_keeps_a_pending_cancellation(): void
    {
        config(['services.stripe.signature_check' => false]);
        $user = User::factory()->create();
        $creator = $this->plan('creator');
        $pro = $this->plan('pro');
        $this->subscribe($user, $creator, 'active', now()->addDays(20), 'sub_c');
        $user->plans()->updateExistingPivot($creator->id, ['cancelled_at' => now()->subDay()]);

        $periodEnd = now()->addDays(20)->timestamp;
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('changeSubscriptionPrice')->once()->with('sub_c', 'price_pro')
                ->andReturn(['status' => 'active', 'cancel_at_period_end' => true]);
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/user-plans/change-plan', ['price_id' => 'price_pro'])
            ->assertOk();

        // Stripe keeps cancel_at_period_end, so its follow-up event says so.
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_c',
                'status' => 'active',
                'cancel_at_period_end' => true,
                'current_period_end' => $periodEnd,
                'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
            ]],
        ])->assertOk();

        $pivot = $this->pivot($user, $pro);
        $this->assertNotNull($pivot->cancelled_at);
        $this->assertSame('price_pro', $pivot->stripe_price_id);
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

    public function test_expiry_job_leaves_the_plan_alone_when_stripe_cannot_be_reached(): void
    {
        // Stripe is the authority on whether the subscription is really dead.
        // A failed lookup is not a "no" — treating it as one expires a paying
        // customer and zeroes their credits because an API call failed.
        \Illuminate\Support\Facades\Exceptions::fake();
        $user = User::factory()->create();
        $plan = $this->plan('pro');
        $this->subscribe($user, $plan, 'active', now()->subDays(2), 'sub_unverifiable');
        $user->deposit(500);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->andThrow(new \Exception('Stripe API is down'));
        });

        Artisan::call('subscriptions:process-expired');

        $this->assertSame('active', $this->pivot($user, $plan)->status);   // untouched
        $this->assertSame(500, $user->fresh()->balanceInt);                // credits kept
        \Illuminate\Support\Facades\Exceptions::assertReported(
            fn (\Exception $e) => $e->getMessage() === 'Stripe API is down'
        );
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
