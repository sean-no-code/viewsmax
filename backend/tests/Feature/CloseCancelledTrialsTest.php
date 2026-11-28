<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use App\Support\UserAccess;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * subscriptions:close-cancelled-trials: a trial cancelled before anything was
 * charged must end at once (UnpaidTrial). Rows cancelled before that rule
 * shipped — or by any path that missed it — are still `trialing` with
 * cancelled_at set and keep access until the trial date. The sweep closes
 * their access once Stripe confirms nothing was charged. It only reads
 * Stripe: the subscription is already set to end at the trial date there.
 */
class CloseCancelledTrialsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'creator',
            'display_name' => 'Creator',
            'price' => 29.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'stripe_price_id' => 'price_creator',
        ]);
    }

    /** A user on a trial they cancelled the old way: row still trialing, window open. */
    private function cancelledTrial(string $subId, array $pivot = []): User
    {
        $user = User::factory()->create(['promo_expires_at' => now()->addDays(6)]);
        $user->plans()->attach($this->plan->id, array_merge([
            'stripe_subscription_id' => $subId,
            'stripe_price_id' => 'price_creator',
            'status' => 'trialing',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(6),
            'cancelled_at' => now()->subHours(20),
        ], $pivot));

        return $user;
    }

    private function rowStatus(User $user): string
    {
        return $user->plans()->first()->pivot->status;
    }

    /** Access closes here; Stripe is left to end the subscription at the trial date as scheduled. */
    public function test_a_cancelled_trial_stripe_still_holds_loses_access_without_touching_stripe(): void
    {
        $user = $this->cancelledTrial('sub_old');
        $this->assertNull(UserAccess::denial($user), 'precondition: the old cancel left access open');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->with('sub_old')->andReturn(['status' => 'trialing', 'cancel_at_period_end' => true]);
            $mock->shouldNotReceive('cancelSubscription');
            $mock->shouldNotReceive('voidOpenInvoices');
        });

        $this->artisan('subscriptions:close-cancelled-trials')->assertSuccessful();

        $user = $user->fresh();
        $this->assertSame('expired', $this->rowStatus($user));
        $this->assertFalse($user->freeWindowOpen());
        $this->assertSame(UserAccess::SUBSCRIPTION_INACTIVE, UserAccess::denial($user)?->code);
    }

    public function test_a_trial_stripe_already_ended_is_closed(): void
    {
        $user = $this->cancelledTrial('sub_gone');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->with('sub_gone')->andReturn(['status' => 'canceled']);
            $mock->shouldNotReceive('cancelSubscription');
            $mock->shouldNotReceive('voidOpenInvoices');
        });

        $this->artisan('subscriptions:close-cancelled-trials')->assertSuccessful();

        $this->assertSame('expired', $this->rowStatus($user));
    }

    /** Stripe says active: the trial ended and the first invoice is paid or about to be. Not ours to end. */
    public function test_a_subscription_stripe_has_moved_past_the_trial_is_left_alone(): void
    {
        $user = $this->cancelledTrial('sub_charged');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->with('sub_charged')->andReturn(['status' => 'active', 'cancel_at_period_end' => true]);
            $mock->shouldNotReceive('cancelSubscription');
            $mock->shouldNotReceive('voidOpenInvoices');
        });

        $this->artisan('subscriptions:close-cancelled-trials')->assertSuccessful();

        $this->assertSame('trialing', $this->rowStatus($user));
        $this->assertNull(UserAccess::denial($user->fresh()));
    }

    public function test_trials_that_were_not_cancelled_are_not_looked_at(): void
    {
        $user = $this->cancelledTrial('sub_live', ['cancelled_at' => null]);

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldNotReceive('getSubscription'));

        $this->artisan('subscriptions:close-cancelled-trials')->assertSuccessful();

        $this->assertSame('trialing', $this->rowStatus($user));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = $this->cancelledTrial('sub_dry');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->with('sub_dry')->andReturn(['status' => 'trialing', 'cancel_at_period_end' => true]);
            $mock->shouldNotReceive('cancelSubscription');
            $mock->shouldNotReceive('voidOpenInvoices');
        });

        $this->artisan('subscriptions:close-cancelled-trials', ['--dry-run' => true])
            ->expectsOutputToContain($user->email)
            ->assertSuccessful();

        $this->assertSame('trialing', $this->rowStatus($user));
    }

    /** One Stripe failure doesn't stop the rest; the run reports failure. */
    public function test_a_stripe_error_on_one_row_does_not_stop_the_others(): void
    {
        $broken = $this->cancelledTrial('sub_broken');
        $fine = $this->cancelledTrial('sub_fine');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->with('sub_broken')->andThrow(new \RuntimeException('Stripe down'));
            $mock->shouldReceive('getSubscription')->with('sub_fine')->andReturn(['status' => 'canceled']);
        });

        $this->artisan('subscriptions:close-cancelled-trials')->assertFailed();

        $this->assertSame('trialing', $this->rowStatus($broken));
        $this->assertSame('expired', $this->rowStatus($fine));
    }

    public function test_the_sweep_is_scheduled_hourly(): void
    {
        $this->app->make(Kernel::class)->all();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'subscriptions:close-cancelled-trials'));

        $this->assertNotNull($event, 'Expected the sweep to be scheduled.');
        $this->assertSame('0 * * * *', $event->expression);
    }
}
