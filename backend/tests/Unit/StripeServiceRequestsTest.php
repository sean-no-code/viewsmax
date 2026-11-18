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
