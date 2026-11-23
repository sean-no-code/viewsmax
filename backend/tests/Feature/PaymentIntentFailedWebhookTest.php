<?php

namespace Tests\Feature;

use App\Mail\PaymentFailedMail;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingLog;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * payment_intent.payment_failed and charge.failed are the events Stripe sends
 * for every failed charge. The webhook used to ignore them, so a failure that
 * reached the app only as one of these left the customer with access. They
 * must do what invoice.payment_failed does: neither object names its invoice,
 * so the webhook asks Stripe for it (StripeService::invoiceOfPaymentIntent,
 * mocked here).
 */
class PaymentIntentFailedWebhookTest extends TestCase
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
            // The billing channel keeps its records in memory for the assertions.
            'logging.channels.billing' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
        ]);
    }

    private function subscriber(string $status = 'active'): User
    {
        $plan = Plan::create([
            'name' => 'creator', 'display_name' => 'Creator', 'price' => 79.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_creator',
        ]);
        $user = User::factory()->create();
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_1', 'stripe_price_id' => 'price_creator',
            'status' => $status, 'starts_at' => now()->subMonth(), 'expires_at' => now()->addMonth(),
        ]);

        return $user;
    }

    /** The invoice as Stripe returns it today: the subscription sits under `parent`. */
    private function invoice(array $extra = []): array
    {
        return array_merge([
            'id' => 'in_1',
            'object' => 'invoice',
            'status' => 'open',
            'customer' => 'cus_1',
            'parent' => ['subscription_details' => ['subscription' => 'sub_1']],
            'amount_due' => 7900,
            'currency' => 'usd',
            'attempt_count' => 1,
            'hosted_invoice_url' => 'https://invoice.stripe.test/i/in_1',
            'next_payment_attempt' => null,
        ], $extra);
    }

    /** What Stripe says the payment intent was paying: an invoice, or nothing. */
    private function stripeSaysIntentPays(?array $invoice): void
    {
        $this->mock(StripeService::class, fn ($mock) => $mock
            ->shouldReceive('invoiceOfPaymentIntent')->with('pi_1')->andReturn($invoice));
    }

    private function intentFails(array $extra = []): void
    {
        $this->postJson('/api/webhooks/stripe', [
            'id' => 'evt_pi',
            'object' => 'event',
            'type' => 'payment_intent.payment_failed',
            'livemode' => false,
            'api_version' => '2026-06-24.dahlia',
            'data' => ['object' => array_merge([
                'id' => 'pi_1',
                'object' => 'payment_intent',
                'customer' => 'cus_1',
                'amount' => 7900,
                'status' => 'requires_payment_method',
                'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'],
            ], $extra)],
        ])->assertOk();
    }

    private function pivotStatus(User $user): string
    {
        return DB::table('user_plans')->where('user_id', $user->id)->value('status');
    }

    /** The context of the billing log line with this message, or null. */
    private function logged(string $message): ?array
    {
        $records = Log::channel(BillingLog::CHANNEL)->getLogger()->getHandlers()[0]->getRecords();
        foreach ($records as $record) {
            if ($record->message === $message) {
                return $record->context;
            }
        }

        return null;
    }

    public function test_a_failed_charge_of_a_subscription_invoice_locks_the_account_and_emails_the_customer(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays($this->invoice());

        $this->intentFails();

        $this->assertSame('past_due', $this->pivotStatus($user));
        Mail::assertSent(PaymentFailedMail::class, 1);
        $mail = Mail::sent(PaymentFailedMail::class)->first();
        $this->assertTrue($mail->hasTo($user->email));
        $this->assertSame('Creator', $mail->planName);
        $this->assertSame('https://invoice.stripe.test/i/in_1', $mail->invoiceUrl);
    }

    public function test_the_billing_log_names_the_invoice_the_subscription_and_why_the_card_failed(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays($this->invoice());

        $this->intentFails();

        $result = $this->logged('Stripe webhook handled');
        $this->assertSame('payment_intent.payment_failed', $result['event_type']);
        $this->assertSame('subscription marked past_due', $result['outcome']);
        $this->assertSame('pi_1', $result['object_id']);
        $this->assertSame('in_1', $result['stripe_invoice_id']);
        $this->assertSame('sub_1', $result['stripe_subscription_id']);
        $this->assertSame('insufficient_funds', $result['payment_error']);
        $this->assertSame($user->id, $result['user_id']);
    }

    public function test_both_failure_events_for_one_invoice_email_once(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays($this->invoice());

        $this->intentFails();
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice.payment_failed',
            'data' => ['object' => $this->invoice()],
        ])->assertOk();

        $this->assertSame('past_due', $this->pivotStatus($user));
        Mail::assertSent(PaymentFailedMail::class, 1);
    }

    public function test_a_charge_that_was_not_for_an_invoice_changes_nothing(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays(null);

        $this->intentFails();

        $this->assertSame('active', $this->pivotStatus($user));
        Mail::assertNotSent(PaymentFailedMail::class);
        $result = $this->logged('Stripe webhook changed nothing');
        $this->assertSame('payment was not for an invoice', $result['outcome']);
        $this->assertSame('insufficient_funds', $result['payment_error']);
    }

    /** Stripe redelivers for days: by then a retry or another card may have paid the invoice. */
    public function test_a_late_delivery_for_an_invoice_paid_since_does_not_lock_the_account(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays($this->invoice(['status' => 'paid']));

        $this->intentFails();

        $this->assertSame('active', $this->pivotStatus($user));
        Mail::assertNotSent(PaymentFailedMail::class);
        $this->assertSame('invoice is paid in Stripe now', $this->logged('Stripe webhook changed nothing')['outcome']);
    }

    public function test_a_failed_charge_for_a_subscription_nobody_holds_changes_nothing(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays($this->invoice(['parent' => ['subscription_details' => ['subscription' => 'sub_other']]]));

        $this->intentFails();

        $this->assertSame('active', $this->pivotStatus($user));
        Mail::assertNotSent(PaymentFailedMail::class);
        $this->assertSame('no user has this subscription', $this->logged('Stripe webhook changed nothing')['outcome']);
    }

    // --- charge.failed: the same failure, reported on the Charge ---------------

    private function chargeFails(array $extra = []): void
    {
        $this->postJson('/api/webhooks/stripe', [
            'id' => 'evt_ch',
            'object' => 'event',
            'type' => 'charge.failed',
            'livemode' => false,
            'api_version' => '2026-06-24.dahlia',
            'data' => ['object' => array_merge([
                'id' => 'ch_1',
                'object' => 'charge',
                'customer' => 'cus_1',
                'payment_intent' => 'pi_1',
                'status' => 'failed',
                'failure_code' => 'card_declined',
                'outcome' => ['type' => 'issuer_declined', 'reason' => 'insufficient_funds'],
            ], $extra)],
        ])->assertOk();
    }

    public function test_a_failed_charge_event_locks_the_account_and_emails_the_customer(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays($this->invoice());

        $this->chargeFails();

        $this->assertSame('past_due', $this->pivotStatus($user));
        Mail::assertSent(PaymentFailedMail::class, 1);

        $result = $this->logged('Stripe webhook handled');
        $this->assertSame('charge.failed', $result['event_type']);
        $this->assertSame('in_1', $result['stripe_invoice_id']);
        $this->assertSame('sub_1', $result['stripe_subscription_id']);
        $this->assertSame('insufficient_funds', $result['payment_error']);
    }

    public function test_all_three_failure_events_for_one_invoice_email_once(): void
    {
        $user = $this->subscriber();
        $this->stripeSaysIntentPays($this->invoice());

        $this->chargeFails();
        $this->intentFails();
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice.payment_failed',
            'data' => ['object' => $this->invoice()],
        ])->assertOk();

        $this->assertSame('past_due', $this->pivotStatus($user));
        Mail::assertSent(PaymentFailedMail::class, 1);
    }

    public function test_a_failed_charge_with_no_payment_intent_changes_nothing(): void
    {
        $user = $this->subscriber();
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldNotReceive('invoiceOfPaymentIntent'));

        $this->chargeFails(['payment_intent' => null]);

        $this->assertSame('active', $this->pivotStatus($user));
        $this->assertSame('charge has no payment intent', $this->logged('Stripe webhook changed nothing')['outcome']);
    }

    public function test_a_stripe_outage_asks_stripe_to_redeliver(): void
    {
        $user = $this->subscriber();
        $this->mock(StripeService::class, fn ($mock) => $mock
            ->shouldReceive('invoiceOfPaymentIntent')->andThrow(new \Exception('Stripe is down')));

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_1', 'object' => 'payment_intent']],
        ])->assertStatus(500);

        $this->assertSame('active', $this->pivotStatus($user));
    }
}
