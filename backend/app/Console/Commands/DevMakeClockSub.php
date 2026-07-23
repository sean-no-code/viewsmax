<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Stripe\StripeClient;

/**
 * DEV ONLY. Spins up a Stripe test-clock-backed subscription and attaches it to a
 * local user, so the time-based lifecycle scenarios (trial->active, renewal) in
 * PRICING_LIFECYCLE_TEST_PLAN.md can be driven by advancing the clock. Pass
 * --trial-days=0 to start it 'active' immediately (no trial) for cancel/active tests.
 *
 *   php artisan dev:make-clock-sub user@email pro
 *   stripe test_helpers test_clocks advance clk_xxx --frozen-time <ts>
 */
class DevMakeClockSub extends Command
{
    protected $signature = 'dev:make-clock-sub {email : Local user email (created if missing)} {plan=pro : Plan name} {--trial-days=7}';

    protected $description = 'DEV: create a Stripe test-clock subscription and attach it to a local user';

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('Refusing to run in production.');
            return self::FAILURE;
        }

        $email = $this->argument('email');
        $user = User::where('email', $email)->first();
        if (! $user) {
            // Dev convenience: create the account if it doesn't exist (password
            // 'password', email pre-verified).
            $user = User::factory()->create(['email' => $email]);
            $this->warn("Created user {$email} (password: 'password').");
        }

        // Mark onboarding complete so you can log straight into the dashboard
        // (no Connect step blocking) — applies whether the user is new or existing.
        if (! $user->onboarding_completed_at) {
            $user->update(['onboarding_completed_at' => now()]);
            $this->warn("Marked onboarding complete for {$email}.");
        }

        $plan = Plan::where('name', $this->argument('plan'))->first();
        if (! $plan || ! $plan->stripe_price_id) {
            $this->error("Plan '{$this->argument('plan')}' not found or has no stripe_price_id (seed plans first).");
            return self::FAILURE;
        }

        $secret = config('services.stripe.secret');
        if (! $secret) {
            $this->error('STRIPE_SECRET is not configured.');
            return self::FAILURE;
        }

        $stripe = new StripeClient($secret);
        $trialDays = (int) $this->option('trial-days');

        $this->info('Creating test clock…');
        $clock = $stripe->testHelpers->testClocks->create(['frozen_time' => now()->timestamp, 'name' => 'lifecycle-test']);

        $this->info('Creating clock-bound customer + card…');
        $customer = $stripe->customers->create(['email' => $user->email, 'test_clock' => $clock->id]);
        // Attaching the shared test token clones it into a real PM id — use that.
        $pm = $stripe->paymentMethods->attach('pm_card_visa', ['customer' => $customer->id]);
        $stripe->customers->update($customer->id, [
            'invoice_settings' => ['default_payment_method' => $pm->id],
        ]);

        $this->info($trialDays > 0
            ? "Creating {$plan->name} subscription with a {$trialDays}-day trial…"
            : "Creating {$plan->name} subscription (no trial → active immediately)…");

        $subParams = [
            'customer' => $customer->id,
            'items' => [['price' => $plan->stripe_price_id]],
            'metadata' => ['user_id' => (string) $user->id, 'dev_clock' => $clock->id],
            'expand' => ['items.data.price'],
        ];
        if ($trialDays > 0) {
            $subParams['trial_period_days'] = $trialDays;
        }
        $subscription = $stripe->subscriptions->create($subParams);

        $periodEnd = $subscription->current_period_end
            ?? ($subscription->items->data[0]->current_period_end ?? null);
        $expiresAt = $periodEnd ? Carbon::createFromTimestamp($periodEnd) : now()->addDays(max($trialDays, 30));

        // Link it to the local user the way the app would.
        $user->update(['stripe_customer_id' => $customer->id]);
        $user->plans()->syncWithoutDetaching([
            $plan->id => [
                'stripe_subscription_id' => $subscription->id,
                'stripe_price_id' => $plan->stripe_price_id,
                'status' => $subscription->status, // 'trialing' or 'active'
                'starts_at' => now(),
                'expires_at' => $expiresAt,
            ],
        ]);

        $this->newLine();
        $this->info('Done. IDs:');
        $this->line("  clock:        {$clock->id}");
        $this->line("  customer:     {$customer->id}");
        $this->line("  subscription: {$subscription->id}  (status: {$subscription->status})");
        $this->newLine();
        $this->line('Advance time to convert the trial / trigger a renewal, e.g.:');
        $this->line("  stripe test_helpers test_clocks advance {$clock->id} --frozen-time \$(date -v+8d +%s) --api-key sk_test_...");
        $this->line('Make sure `stripe listen --forward-to localhost:8000/api/webhooks/stripe` is running.');

        return self::SUCCESS;
    }
}
