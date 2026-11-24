<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingLog;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * "Stripe took the payment but nothing changed here" has to be answerable
 * from one file. Every webhook writes two lines to the billing log: what
 * arrived, and what the app did with it. Deliveries the app cannot use at all
 * (a wrong signing secret, a thin-event destination) are raised as errors.
 */
class StripeWebhookLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config([
            'services.stripe.signature_check' => false,
            'services.stripe.webhook_secret' => 'whsec_test',
            // The billing channel keeps its records in memory for the assertions.
            'logging.channels.billing' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
        ]);
    }

    /** @return list<\Monolog\LogRecord> */
    private function billingRecords(?string $level = null): array
    {
        $records = Log::channel(BillingLog::CHANNEL)->getLogger()->getHandlers()[0]->getRecords();

        return array_values(array_filter($records, fn ($r) => $level === null || strtolower($r->level->getName()) === $level));
    }

    private function event(string $type, array $object, array $extra = []): array
    {
        return array_merge([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'api_version' => '2026-06-24.dahlia',
            'data' => ['object' => $object],
        ], $extra);
    }

    /** The context of the billing log line with this level and message, or null. */
    private function logged(string $level, string $message): ?array
    {
        foreach ($this->billingRecords($level) as $record) {
            if ($record->message === $message) {
                return $record->context;
            }
        }

        return null;
    }

    private function subscriber(): User
    {
        $plan = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro', 'price' => 99.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create();
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_1', 'stripe_price_id' => 'price_pro',
            'status' => 'trialing', 'starts_at' => now()->subDays(7), 'expires_at' => now(),
        ]);

        return $user;
    }

    public function test_a_paid_invoice_logs_what_arrived_and_what_was_done(): void
    {
        $user = $this->subscriber();
        $periodEnd = now()->addMonth()->timestamp;
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('getSubscription')->with('sub_1')->andReturn([
            'status' => 'active', 'customer' => 'cus_1', 'metadata' => [],
            'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_end' => $periodEnd]]],
        ]));

        $this->postJson('/api/webhooks/stripe', $this->event('invoice.paid', [
            'id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => 'sub_1',
            'amount_paid' => 9900, 'currency' => 'usd', 'billing_reason' => 'subscription_cycle',
        ]))->assertOk();

        $received = $this->logged('info', 'Stripe webhook received');
        $this->assertSame([
            'event_id' => 'evt_1',
            'event_type' => 'invoice.paid',
            'object_id' => 'in_1',
            'livemode' => false,
            'api_version' => '2026-06-24.dahlia',
            'stripe_customer_id' => 'cus_1',
            'stripe_subscription_id' => 'sub_1',
            'stripe_invoice_id' => 'in_1',
        ], $received);

        $result = $this->logged('info', 'Stripe webhook handled');
        $this->assertSame('evt_1', $result['event_id']);
        $this->assertSame('invoice.paid', $result['event_type']);
        $this->assertSame('credits allocated, plan active', $result['outcome']);
        $this->assertSame($user->id, $result['user_id']);
        $this->assertIsInt($result['ms']);
    }

    public function test_an_event_that_changed_nothing_says_why(): void
    {
        $this->postJson('/api/webhooks/stripe', $this->event('invoice.payment_failed', [
            'id' => 'in_9', 'object' => 'invoice', 'subscription' => 'sub_unknown', 'amount_due' => 9900,
        ]))->assertOk();

        $result = $this->logged('warning', 'Stripe webhook changed nothing');
        $this->assertSame('no user has this subscription', $result['outcome']);
        $this->assertSame('sub_unknown', $result['stripe_subscription_id']);
    }

    public function test_an_event_type_the_app_does_not_handle_is_logged_as_ignored(): void
    {
        $this->postJson('/api/webhooks/stripe', $this->event('charge.refunded', ['id' => 'ch_1', 'object' => 'charge']))->assertOk();

        $result = $this->logged('info', 'Stripe webhook ignored');
        $this->assertSame('charge.refunded', $result['event_type']);
        $this->assertSame('event type not handled', $result['outcome']);
    }

    public function test_a_handler_failure_is_logged_with_its_error(): void
    {
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('getSubscription')->andThrow(new \Exception('Stripe is down')));

        $this->postJson('/api/webhooks/stripe', $this->event('invoice.paid', [
            'id' => 'in_1', 'object' => 'invoice', 'subscription' => 'sub_1',
        ]))->assertStatus(500);

        $result = $this->logged('warning', 'Stripe webhook failed');
        $this->assertSame('Stripe is down', $result['error']);
        $this->assertSame('in_1', $result['stripe_invoice_id']);
    }

    public function test_a_thin_event_is_refused_loudly_once_an_hour(): void
    {
        $thin = [
            'id' => 'evt_thin', 'object' => 'v2.core.event', 'type' => 'v1.invoice.paid', 'livemode' => true,
            'related_object' => ['id' => 'in_1', 'type' => 'invoice', 'url' => '/v1/invoices/in_1'],
        ];

        $this->postJson('/api/webhooks/stripe', $thin)->assertOk()->assertJson(['status' => 'ignored']);
        $this->postJson('/api/webhooks/stripe', $thin)->assertOk();

        $error = $this->logged('error', 'Stripe webhook unusable: thin event');
        $this->assertSame('v1.invoice.paid', $error['event_type']);
        $this->assertCount(1, $this->billingRecords('error'));
        $this->assertCount(1, $this->billingRecords('warning')); // the repeat within the hour
    }

    // --- with the signature check on, as in production -----------------------

    private function signed(array $event, string $secret = 'whsec_test')
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    public function test_a_signed_event_is_read_and_logged(): void
    {
        config(['services.stripe.signature_check' => true]);

        $this->signed($this->event('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'customer' => 'cus_1']))->assertOk();

        $received = $this->logged('info', 'Stripe webhook received');
        $this->assertSame('evt_1', $received['event_id']);
        $this->assertSame('ch_1', $received['object_id']);
        $this->assertSame('cus_1', $received['stripe_customer_id']);
    }

    public function test_a_signed_thin_event_is_refused_not_crashed_on(): void
    {
        config(['services.stripe.signature_check' => true]);

        $this->signed(['id' => 'evt_thin', 'object' => 'v2.core.event', 'type' => 'v1.invoice.payment_failed', 'livemode' => true])
            ->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertNotNull($this->logged('error', 'Stripe webhook unusable: thin event'));
    }

    public function test_a_wrong_signing_secret_is_raised_as_an_error(): void
    {
        config(['services.stripe.signature_check' => true]);

        $this->signed($this->event('invoice.paid', ['id' => 'in_1']), 'whsec_some_other_destination')->assertStatus(400);

        $this->assertNotNull($this->logged('error', 'Stripe webhook rejected: signature does not match STRIPE_WEBHOOK_SECRET'));
    }

    public function test_a_request_with_no_signature_is_only_a_warning(): void
    {
        config(['services.stripe.signature_check' => true]);

        $this->postJson('/api/webhooks/stripe', $this->event('invoice.paid', ['id' => 'in_1']))->assertStatus(400);

        $this->assertNotNull($this->logged('warning', 'Stripe webhook rejected: no Stripe-Signature header'));
        $this->assertSame([], $this->billingRecords('error'));
    }
}
