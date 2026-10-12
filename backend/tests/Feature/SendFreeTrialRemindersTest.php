<?php

namespace Tests\Feature;

use App\Mail\FreeTrialEndingMail;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendFreeTrialRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['free_trial.reminder.enabled' => true, 'free_trial.reminder.hours_before' => 24, 'app.frontend_url' => 'https://app.example.com/']);
    }

    public function test_sends_once_to_a_user_whose_window_closes_within_a_day(): void
    {
        $user = $this->freeUser('soon@example.com', now()->addHours(23));

        $this->artisan('subscriptions:send-free-trial-reminders')->expectsOutputToContain('sent 1/1')->assertSuccessful();

        $mail = Mail::sent(FreeTrialEndingMail::class, fn ($m) => $m->hasTo('soon@example.com'))->sole();
        $this->assertSame('https://app.example.com/dashboard/billing', $mail->billingUrl);
        $html = $mail->render();
        $this->assertStringContainsString('href="https://app.example.com/dashboard/billing"', $html);
        $this->assertSame(2, substr_count($html, 'https://app.example.com/dashboard/billing'));
        $this->assertStringContainsString($user->promo_expires_at->format('F j, Y'), $html);
        $this->assertNotNull($user->refresh()->free_trial_reminder_sent_at);

        // Second run: already stamped.
        $this->artisan('subscriptions:send-free-trial-reminders')->assertSuccessful();
        Mail::assertSent(FreeTrialEndingMail::class, 1);
    }

    public function test_skips_users_outside_the_window_or_already_covered(): void
    {
        $this->freeUser('early@example.com', now()->addHours(30));
        $this->freeUser('ended@example.com', now()->subHour());
        $this->freeUser('legacy@example.com', null);
        $this->freeUser('reminded@example.com', now()->addHours(23), reminded: now()->subHour());
        $this->freeUser('unverified@example.com', now()->addHours(23), verified: false);

        $paid = $this->freeUser('paid@example.com', now()->addHours(23));
        $paid->plans()->attach($this->plan()->id, ['status' => 'active', 'starts_at' => now()]);
        $carded = $this->freeUser('carded@example.com', now()->addHours(23));
        $carded->plans()->attach($this->plan('trial-plan')->id, ['status' => 'trialing', 'stripe_subscription_id' => 'sub_1', 'starts_at' => now(), 'expires_at' => now()->addHours(23)]);
        $lapsed = $this->freeUser('lapsed@example.com', now()->addHours(23));
        $lapsed->plans()->attach($this->plan('old-plan')->id, ['status' => 'cancelled', 'starts_at' => now()->subMonth()]);

        $this->artisan('subscriptions:send-free-trial-reminders')->expectsOutputToContain('sent 1/1')->assertSuccessful();

        Mail::assertSent(FreeTrialEndingMail::class, fn ($m) => $m->hasTo('lapsed@example.com'));
        Mail::assertSent(FreeTrialEndingMail::class, 1);
    }

    public function test_disabled_by_config(): void
    {
        config(['free_trial.reminder.enabled' => false]);
        $this->freeUser('soon@example.com', now()->addHours(23));

        $this->artisan('subscriptions:send-free-trial-reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    private function freeUser(string $email, $expiresAt, $reminded = null, bool $verified = true): User
    {
        return User::factory()->create([
            'email' => $email,
            'name' => 'Free User',
            'email_verified_at' => $verified ? now() : null,
            'promo_expires_at' => $expiresAt,
            'free_trial_reminder_sent_at' => $reminded,
        ]);
    }

    private function plan(string $name = 'pro'): Plan
    {
        return Plan::create([
            'name' => $name, 'display_name' => ucfirst($name), 'price' => 29, 'currency' => 'usd',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_'.$name,
        ]);
    }
}
