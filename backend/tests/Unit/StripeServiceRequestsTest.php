<?php

namespace Tests\Unit;

use App\Services\StripeService;
use Tests\Support\FakeStripeClient;
use Tests\TestCase;

/**
 * What StripeService sends to Stripe, and how it reacts to Stripe errors,
 * with Stripe's HTTP transport replaced by FakeStripeClient (no network).
 */
class StripeServiceRequestsTest extends TestCase
{
    private FakeStripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->stripe = FakeStripeClient::install();
    }

    protected function tearDown(): void
    {
        FakeStripeClient::uninstall();
        parent::tearDown();
    }

    private function service(): StripeService
    {
        return new StripeService;
    }

    private function subscription(string $id, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'object' => 'subscription',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item']]],
        ], $extra);
    }

    // --- Item 2: a plan change keeps a pending cancellation ------------------

    public function test_price_change_does_not_touch_cancel_at_period_end(): void
    {
        $this->stripe
            ->on('GET', '/v1/subscriptions/sub_1', $this->subscription('sub_1', ['cancel_at_period_end' => true]))
            ->on('POST', '/v1/subscriptions/sub_1', $this->subscription('sub_1', ['cancel_at_period_end' => true]));

        $result = $this->service()->changeSubscriptionPrice('sub_1', 'price_new');

        $params = $this->stripe->requestsTo('POST', '/v1/subscriptions/sub_1')[0]['params'];
        $this->assertArrayNotHasKey('cancel_at_period_end', $params);
        $this->assertSame([['id' => 'si_1', 'price' => 'price_new']], $params['items']);
        $this->assertTrue($result['cancel_at_period_end']);
    }

    // --- Item 3: voiding a dead subscription's open invoices ----------------

    private function invoiceList(array $ids): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/invoices',
            'has_more' => false,
            'data' => array_map(fn ($id) => ['id' => $id, 'object' => 'invoice', 'status' => 'open'], $ids),
        ];
    }

    public function test_void_open_invoices_voids_each_open_invoice_of_the_subscription(): void
    {
        $this->stripe
            ->on('GET', '/v1/invoices', $this->invoiceList(['in_1', 'in_2']))
            ->on('POST', '/v1/invoices/in_1/void', ['id' => 'in_1', 'object' => 'invoice', 'status' => 'void'])
            ->on('POST', '/v1/invoices/in_2/void', ['id' => 'in_2', 'object' => 'invoice', 'status' => 'void']);

        $this->assertSame(2, $this->service()->voidOpenInvoices('sub_1'));

        $list = $this->stripe->requestsTo('GET', '/v1/invoices')[0]['params'];
        $this->assertSame('sub_1', $list['subscription']);
        $this->assertSame('open', $list['status']);
    }

    public function test_void_open_invoices_reports_a_failure_and_carries_on(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $this->stripe
            ->on('GET', '/v1/invoices', $this->invoiceList(['in_1', 'in_2']))
            ->failOn('POST', '/v1/invoices/in_1/void', 400, 'This invoice can no longer be voided.')
            ->on('POST', '/v1/invoices/in_2/void', ['id' => 'in_2', 'object' => 'invoice', 'status' => 'void']);

        $this->assertSame(1, $this->service()->voidOpenInvoices('sub_1'));

        \Illuminate\Support\Facades\Exceptions::assertReported(
            fn (\Stripe\Exception\InvalidRequestException $e) => str_contains($e->getMessage(), 'can no longer be voided')
        );
    }

    public function test_void_open_invoices_never_throws_when_the_listing_fails(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $this->stripe->failOn('GET', '/v1/invoices', 500, 'Stripe is down');

        $this->assertSame(0, $this->service()->voidOpenInvoices('sub_1'));

        \Illuminate\Support\Facades\Exceptions::assertReported(
            fn (\Stripe\Exception\UnknownApiErrorException $e) => str_contains($e->getMessage(), 'Stripe is down')
        );
    }

    // --- Item 12: re-subscribe after a late payment --------------------------

    private function cancelledSubscription(array $extra = []): array
    {
        return array_merge([
            'id' => 'sub_old',
            'customer' => 'cus_1',
            'status' => 'canceled',
            'metadata' => ['user_id' => '7'],
            'items' => ['data' => [[
                'quantity' => 1,
                'price' => ['id' => 'price_pro', 'recurring' => ['interval' => 'month', 'interval_count' => 1]],
            ]]],
        ], $extra);
    }

    private function invoiceWithPayment(string $intentStatus, ?string $paymentMethod, array $intentExtra = []): array
    {
        return [
            'id' => 'in_late',
            'object' => 'invoice',
            'payments' => ['object' => 'list', 'data' => [[
                'payment' => ['payment_intent' => array_merge([
                    'id' => 'pi_1',
                    'object' => 'payment_intent',
                    'status' => $intentStatus,
                    'payment_method' => $paymentMethod,
                ], $intentExtra)],
            ]]],
        ];
    }

    public function test_resubscribe_starts_the_same_price_one_period_after_the_payment_on_the_paying_card(): void
    {
        $paidAt = \Carbon\Carbon::parse('2026-01-31 10:00:00', 'UTC')->getTimestamp();
        $this->stripe
            ->on('GET', '/v1/invoices/in_late', $this->invoiceWithPayment('succeeded', 'pm_1'))
            ->on('GET', '/v1/payment_methods/pm_1', ['id' => 'pm_1', 'object' => 'payment_method', 'customer' => 'cus_1'])
            ->on('POST', '/v1/subscriptions', $this->subscription('sub_new'));

        $new = $this->service()->resubscribeAfterLatePayment($this->cancelledSubscription(), 'in_late', $paidAt);

        $this->assertSame('sub_new', $new['id']);
        $request = $this->stripe->requestsTo('POST', '/v1/subscriptions')[0];
        $params = $request['params'];
        $this->assertSame('cus_1', $params['customer']);
        $this->assertSame([['price' => 'price_pro', 'quantity' => 1]], $params['items']);
        $this->assertSame('none', $params['proration_behavior']);
        $this->assertSame('pm_1', $params['default_payment_method']);
        // Jan 31 + 1 month clamps to Feb 28: Stripe refuses an anchor past one period.
        $this->assertSame(\Carbon\Carbon::parse('2026-02-28 10:00:00', 'UTC')->getTimestamp(), $params['billing_cycle_anchor']);
        $this->assertSame(['user_id' => '7', 'late_payment_invoice' => 'in_late', 'resubscribed_from' => 'sub_old'], $params['metadata']);
        $this->assertArrayNotHasKey('trial_end', $params);
        $this->assertContains('Idempotency-Key: resubscribe_in_late', $request['headers']);
        $this->assertSame(['payments.data.payment.payment_intent'], $this->stripe->requestsTo('GET', '/v1/invoices/in_late')[0]['params']['expand']);
    }

    public function test_resubscribe_leaves_the_card_to_the_customer_default_when_the_paying_card_was_not_kept(): void
    {
        $this->stripe
            ->on('GET', '/v1/invoices/in_late', $this->invoiceWithPayment('succeeded', 'pm_1'))
            ->on('GET', '/v1/payment_methods/pm_1', ['id' => 'pm_1', 'object' => 'payment_method', 'customer' => null])
            ->on('POST', '/v1/subscriptions', $this->subscription('sub_new'));

        $this->service()->resubscribeAfterLatePayment($this->cancelledSubscription(), 'in_late', 1_794_000_000);

        $this->assertArrayNotHasKey('default_payment_method', $this->stripe->requestsTo('POST', '/v1/subscriptions')[0]['params']);
    }

    public function test_resubscribe_still_happens_when_the_card_lookup_fails(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $this->stripe
            ->failOn('GET', '/v1/invoices/in_late', 500, 'Stripe is down')
            ->on('POST', '/v1/subscriptions', $this->subscription('sub_new'));

        $new = $this->service()->resubscribeAfterLatePayment($this->cancelledSubscription(), 'in_late', 1_794_000_000);

        $this->assertSame('sub_new', $new['id']);
    }

    public function test_resubscribe_throws_when_stripe_refuses_the_subscription(): void
    {
        $this->stripe
            ->on('GET', '/v1/invoices/in_late', $this->invoiceWithPayment('succeeded', null))
            ->failOn('POST', '/v1/subscriptions', 400, 'No such price');

        $this->expectException(\Stripe\Exception\InvalidRequestException::class);

        $this->service()->resubscribeAfterLatePayment($this->cancelledSubscription(), 'in_late', 1_794_000_000);
    }

    public function test_one_period_after_follows_the_price_interval(): void
    {
        $from = \Carbon\Carbon::parse('2024-02-29 08:00:00', 'UTC');

        $this->assertSame($from->copy()->addDays(3)->getTimestamp(), StripeService::onePeriodAfter($from->getTimestamp(), 'day', 3));
        $this->assertSame($from->copy()->addWeeks(2)->getTimestamp(), StripeService::onePeriodAfter($from->getTimestamp(), 'week', 2));
        $this->assertSame(\Carbon\Carbon::parse('2024-03-29 08:00:00', 'UTC')->getTimestamp(), StripeService::onePeriodAfter($from->getTimestamp(), 'month', 1));
        $this->assertSame(\Carbon\Carbon::parse('2025-02-28 08:00:00', 'UTC')->getTimestamp(), StripeService::onePeriodAfter($from->getTimestamp(), 'year', 1));
    }

    public function test_list_subscriptions_asks_for_every_status(): void
    {
        $this->stripe->on('GET', '/v1/subscriptions', [
            'object' => 'list', 'url' => '/v1/subscriptions', 'has_more' => false,
            'data' => [$this->subscription('sub_a'), $this->subscription('sub_b', ['status' => 'canceled'])],
        ]);

        $subscriptions = $this->service()->listSubscriptions('cus_1');

        $this->assertSame(['sub_a', 'sub_b'], array_column($subscriptions, 'id'));
        $params = $this->stripe->requestsTo('GET', '/v1/subscriptions')[0]['params'];
        $this->assertSame('cus_1', $params['customer']);
        $this->assertSame('all', $params['status']);
    }

    public function test_keep_card_flags_the_unpaid_invoice_so_the_paying_card_is_saved(): void
    {
        $this->stripe
            ->on('GET', '/v1/invoices', $this->invoiceList(['in_late']))
            ->on('GET', '/v1/invoices/in_late', $this->invoiceWithPayment('requires_payment_method', null))
            ->on('POST', '/v1/payment_intents/pi_1', ['id' => 'pi_1', 'object' => 'payment_intent']);

        $this->service()->keepCardOfUnpaidInvoices('sub_old');

        $this->assertSame('off_session', $this->stripe->requestsTo('POST', '/v1/payment_intents/pi_1')[0]['params']['setup_future_usage']);
        $list = $this->stripe->requestsTo('GET', '/v1/invoices')[0]['params'];
        $this->assertSame('sub_old', $list['subscription']);
        $this->assertSame('open', $list['status']);
    }

    public function test_keep_card_skips_an_intent_that_is_already_flagged_or_past_the_card_step(): void
    {
        $this->stripe
            ->on('GET', '/v1/invoices', $this->invoiceList(['in_a', 'in_b']))
            ->on('GET', '/v1/invoices/in_a', $this->invoiceWithPayment('requires_payment_method', null, ['setup_future_usage' => 'off_session']))
            ->on('GET', '/v1/invoices/in_b', $this->invoiceWithPayment('processing', null));

        $this->service()->keepCardOfUnpaidInvoices('sub_old');

        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/payment_intents/pi_1'));
    }

    public function test_keep_card_never_throws(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $this->stripe->failOn('GET', '/v1/invoices', 500, 'Stripe is down');

        $this->service()->keepCardOfUnpaidInvoices('sub_old');

        \Illuminate\Support\Facades\Exceptions::assertReported(
            fn (\Stripe\Exception\UnknownApiErrorException $e) => str_contains($e->getMessage(), 'Stripe is down')
        );
    }

    // --- Item 7: immediate cancel is safe to repeat --------------------------

    public function test_immediate_cancel_of_an_already_cancelled_subscription_sends_no_cancel(): void
    {
        $this->stripe->on('GET', '/v1/subscriptions/sub_1', $this->subscription('sub_1', ['status' => 'canceled']));

        $result = $this->service()->cancelSubscription('sub_1', 'GDPR erasure', true);

        $this->assertSame('canceled', $result['status']);
        $this->assertSame([], $this->stripe->requestsTo('DELETE', '/v1/subscriptions/sub_1'));
    }

    public function test_immediate_cancel_cancels_a_live_subscription(): void
    {
        $this->stripe
            ->on('GET', '/v1/subscriptions/sub_1', $this->subscription('sub_1'))
            ->on('DELETE', '/v1/subscriptions/sub_1', $this->subscription('sub_1', ['status' => 'canceled']));

        $this->assertSame('canceled', $this->service()->cancelSubscription('sub_1', null, true)['status']);
    }

    public function test_cancel_keeps_the_stripe_error_as_the_previous_exception(): void
    {
        $this->stripe->failOn('GET', '/v1/subscriptions/sub_gone', 404, 'No such subscription', 'resource_missing');

        try {
            $this->service()->cancelSubscription('sub_gone', null, true);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertInstanceOf(\Stripe\Exception\InvalidRequestException::class, $e->getPrevious());
            $this->assertSame(404, $e->getPrevious()->getHttpStatus());
        }
    }
}
