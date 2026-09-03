<?php

namespace Tests\Feature;

use App\Mail\TrialEndingMail;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendTrialEndingRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.trial_reminder_hours_before' => 48]);
    }

    public function test_sends_reminder_for_trial_ending_within_window(): void
    {
        Mail::fake();
        $plan = $this->makeTrialPlan('trial@example.com', now()->addHours(47));

        $this->artisan('subscriptions:send-trial-reminders')->assertSuccessful();

        Mail::assertSent(TrialEndingMail::class, fn ($m) => $m->hasTo('trial@example.com') && $m->amount === '$29.00');
        $this->assertNotNull($plan->refresh()->trial_reminder_sent_at);
    }

    public function test_does_not_send_when_already_reminded(): void
    {
        Mail::fake();
        $this->makeTrialPlan('done@example.com', now()->addHours(47), reminded: now());

        $this->artisan('subscriptions:send-trial-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_send_for_active_subscription(): void
    {
        Mail::fake();
        $this->makeTrialPlan('active@example.com', now()->addHours(47), status: 'active');

        $this->artisan('subscriptions:send-trial-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_trial_ends_beyond_window(): void
    {
        Mail::fake();
        $this->makeTrialPlan('early@example.com', now()->addHours(72));   // 72h > 48h window

        $this->artisan('subscriptions:send-trial-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_trial_already_ended(): void
    {
        Mail::fake();
        $this->makeTrialPlan('past@example.com', now()->subHour());       // already ended / charged

        $this->artisan('subscriptions:send-trial-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_mail_renders_with_charge_details(): void
    {
        $html = (new TrialEndingMail('Sam', 'August 14, 2026', '$29.00', 'https://app.example/settings'))->render();

        $this->assertStringContainsString('August 14, 2026', $html);
        $this->assertStringContainsString('$29.00', $html);
        $this->assertStringContainsString('Manage your subscription', $html);
        $this->assertStringContainsString('https://app.example/settings', $html);
    }

    private function makeTrialPlan(string $email, $expiresAt, string $status = 'trialing', $reminded = null): UserPlan
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Trial User']);

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
            'stripe_subscription_id' => 'sub_'.md5($email),
            'stripe_price_id' => 'price_123',
            'status' => $status,
            'starts_at' => now()->subDays(5),
            'expires_at' => $expiresAt,
            'trial_reminder_sent_at' => $reminded,
        ]);

        return UserPlan::where('user_id', $user->id)->firstOrFail();
    }
}
