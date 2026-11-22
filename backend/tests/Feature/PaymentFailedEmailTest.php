<?php

namespace Tests\Feature;

use App\Mail\PaymentFailedMail;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * invoice.payment_failed locks the account (past_due), so the customer is told
 * once per invoice: what failed, that access is paused, and where to fix the
 * card. Stripe re-sends the event for every retry of the same invoice; those
 * must not mail again.
 */
class PaymentFailedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config([
            'services.stripe.signature_check' => false,
            'app.frontend_url' => 'https://app.example.test',
        ]);
    }

    private function subscriber(string $subId = 'sub_1', string $status = 'trialing'): User
    {
        $plan = Plan::create([
            'name' => 'pro',
            'display_name' => 'Pro',
            'price' => 99.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create(['name' => 'Dana']);
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => $subId,
            'stripe_price_id' => 'price_pro',
            'status' => $status,
            'starts_at' => now()->subDays(7),
            'expires_at' => now(),
        ]);

        return $user;
    }

    private function invoice(array $extra = []): array
    {
        return array_merge([
            'id' => 'in_1',
            'subscription' => 'sub_1',
            'amount_due' => 9900,
            'currency' => 'usd',
            'attempt_count' => 1,
            'hosted_invoice_url' => 'https://invoice.stripe.test/i/in_1',
            'next_payment_attempt' => null,
        ], $extra);
    }

    private function paymentFails(array $invoice): void
    {
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice.payment_failed',
            'data' => ['object' => $invoice],
        ])->assertOk();
    }

    private function pivotStatus(User $user): string
    {
        return DB::table('user_plans')->where('user_id', $user->id)->value('status');
    }

    public function test_a_failed_payment_locks_the_account_and_emails_the_customer(): void
    {
        $user = $this->subscriber();

        $this->paymentFails($this->invoice());

        $this->assertSame('past_due', $this->pivotStatus($user));
        Mail::assertSent(PaymentFailedMail::class, 1);
        $mail = Mail::sent(PaymentFailedMail::class)->first();
        $this->assertTrue($mail->hasTo($user->email));
        $this->assertSame('Dana', $mail->name);
        $this->assertSame('Pro', $mail->planName);
        $this->assertSame('$99.00 USD', $mail->amount);
        $this->assertSame('https://app.example.test/dashboard/billing', $mail->billingUrl);
        $this->assertSame('https://invoice.stripe.test/i/in_1', $mail->invoiceUrl);
        $this->assertSame('', $mail->nextAttempt);

        $html = $mail->render();
        $this->assertStringContainsString('$99.00 USD', $html);
        $this->assertStringContainsString('https://app.example.test/dashboard/billing', $html);
        $this->assertStringContainsString('https://invoice.stripe.test/i/in_1', $html);
        $this->assertStringNotContainsString('try the card again', $html);
    }

    public function test_the_email_says_when_stripe_will_retry(): void
    {
        $this->subscriber();
        $retryAt = now()->addDays(3)->setTime(15, 0);

        $this->paymentFails($this->invoice(['next_payment_attempt' => $retryAt->timestamp]));

        $mail = Mail::sent(PaymentFailedMail::class)->first();
        $this->assertStringContainsString($retryAt->format('F j, Y'), $mail->nextAttempt);
        $this->assertStringContainsString('try the card again on', $mail->render());
    }

    public function test_retries_of_the_same_invoice_do_not_email_again(): void
    {
        $this->subscriber();

        $this->paymentFails($this->invoice());
        $this->paymentFails($this->invoice(['attempt_count' => 2]));
        $this->paymentFails($this->invoice(['attempt_count' => 3]));

        Mail::assertSent(PaymentFailedMail::class, 1);
    }

    public function test_a_later_invoice_failing_emails_again(): void
    {
        $this->subscriber();

        $this->paymentFails($this->invoice());
        $this->paymentFails($this->invoice(['id' => 'in_2']));

        Mail::assertSent(PaymentFailedMail::class, 2);
    }

    public function test_no_email_when_nothing_is_owed(): void
    {
        $user = $this->subscriber();

        $this->paymentFails($this->invoice(['amount_due' => 0]));

        Mail::assertNotSent(PaymentFailedMail::class);
        $this->assertSame('past_due', $this->pivotStatus($user));
    }

    public function test_a_mail_failure_does_not_fail_the_webhook(): void
    {
        $user = $this->subscriber();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->paymentFails($this->invoice());

        $this->assertSame('past_due', $this->pivotStatus($user));
    }

    public function test_an_invoice_for_an_unknown_subscription_emails_nobody(): void
    {
        $this->subscriber();

        $this->paymentFails($this->invoice(['subscription' => 'sub_other']));

        Mail::assertNotSent(PaymentFailedMail::class);
    }
}
