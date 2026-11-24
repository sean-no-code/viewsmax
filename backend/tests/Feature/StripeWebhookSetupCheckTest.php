<?php

namespace Tests\Feature;

use App\Http\Controllers\StripeWebhookController;
use App\Services\Billing\BillingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeStripeClient;
use Tests\TestCase;

/**
 * stripe:check-webhooks asks Stripe which events the webhook endpoint is
 * subscribed to and fails when one the app depends on is missing. Production
 * once ran without invoice.payment_failed and customer.subscription.updated:
 * a failed first charge left the customer `trialing` with full access.
 *
 * Stripe's HTTP transport is FakeStripeClient here, so nothing leaves the box.
 */
class StripeWebhookSetupCheckTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.signature_check' => false,
            'app.url' => 'https://api.example.test',
        ]);
        $this->stripe = FakeStripeClient::install();
    }

    protected function tearDown(): void
    {
        FakeStripeClient::uninstall();
        parent::tearDown();
    }

    /** Every event the app needs, taking the first alternative of each group. */
    private function allRequired(): array
    {
        return array_map(fn (array $alternatives) => $alternatives[0], StripeWebhookController::REQUIRED_EVENTS);
    }

    private function endpoint(array $events, array $extra = []): array
    {
        return array_merge([
            'id' => 'we_1',
            'object' => 'webhook_endpoint',
            'url' => 'https://api.example.test/api/webhooks/stripe',
            'status' => 'enabled',
            'enabled_events' => $events,
        ], $extra);
    }

    /** What the older Webhook Endpoints API lists; the newer Event Destinations API lists nothing. */
    private function stripeReturns(array ...$endpoints): void
    {
        $this->stripe->on('GET', '/v1/webhook_endpoints', [
            'object' => 'list',
            'url' => '/v1/webhook_endpoints',
            'has_more' => false,
            'data' => $endpoints,
        ]);
        $this->destinationsAre();
    }

    /** What the Event Destinations API (v2) lists: where the current dashboard creates webhooks. */
    private function destinationsAre(array ...$destinations): void
    {
        $this->stripe->on('GET', '/v2/core/event_destinations', [
            'data' => $destinations,
            'next_page_url' => null,
            'previous_page_url' => null,
        ]);
    }

    private function destination(array $events, array $extra = []): array
    {
        return array_merge([
            'id' => 'ed_1',
            'object' => 'v2.core.event_destination',
            'type' => 'webhook_endpoint',
            'status' => 'enabled',
            'event_payload' => 'snapshot',
            'enabled_events' => $events,
            'webhook_endpoint' => ['url' => 'https://example.test/api/webhooks/stripe', 'signing_secret' => null],
        ], $extra);
    }

    private function noOlderEndpoints(): void
    {
        $this->stripe->on('GET', '/v1/webhook_endpoints', [
            'object' => 'list', 'url' => '/v1/webhook_endpoints', 'has_more' => false, 'data' => [],
        ]);
    }

    private function expectBillingError(): void
    {
        $channel = \Mockery::mock();
        $channel->shouldReceive('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'Stripe webhook setup check failed')
        );
        $channel->shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('channel')->with(BillingLog::CHANNEL)->andReturn($channel);
    }

    public function test_passes_when_the_endpoint_listens_for_every_required_event(): void
    {
        $this->stripeReturns($this->endpoint($this->allRequired()));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('listens for every event the app needs')
            ->assertSuccessful();
    }

    public function test_fails_and_names_the_missing_events(): void
    {
        $this->expectBillingError();
        $events = array_values(array_diff($this->allRequired(), ['invoice.payment_failed', 'customer.subscription.updated']));
        $this->stripeReturns($this->endpoint($events));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('is not subscribed to: invoice.payment_failed or payment_intent.payment_failed or charge.failed, customer.subscription.updated.')
            ->assertFailed();
    }

    public function test_the_payment_intent_or_charge_failure_event_stands_in_for_the_invoice_one(): void
    {
        $events = array_values(array_diff($this->allRequired(), ['invoice.payment_failed']));

        foreach (['payment_intent.payment_failed', 'charge.failed'] as $standIn) {
            $this->stripeReturns($this->endpoint([...$events, $standIn]));

            $this->artisan('stripe:check-webhooks')->assertSuccessful();
        }
    }

    public function test_an_alternative_event_satisfies_its_group(): void
    {
        // invoice_payment.paid is what newer Stripe accounts send instead of invoice.paid.
        $events = array_values(array_diff($this->allRequired(), ['invoice.paid']));
        $this->stripeReturns($this->endpoint([...$events, 'invoice_payment.paid']));

        $this->artisan('stripe:check-webhooks')->assertSuccessful();
    }

    public function test_an_endpoint_listening_for_all_events_passes(): void
    {
        $this->stripeReturns($this->endpoint(['*']));

        $this->artisan('stripe:check-webhooks')->assertSuccessful();
    }

    public function test_fails_when_the_endpoint_is_disabled(): void
    {
        $this->expectBillingError();
        $this->stripeReturns($this->endpoint($this->allRequired(), ['status' => 'disabled']));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('disabled')
            ->assertFailed();
    }

    public function test_a_failure_says_which_stripe_mode_was_checked(): void
    {
        $this->expectBillingError();
        config(['services.stripe.secret' => 'sk_live_fake']);
        $this->stripeReturns($this->endpoint($this->allRequired(), ['status' => 'disabled']));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('a live-mode key')
            ->assertFailed();
    }

    public function test_a_disabled_failure_lists_what_else_stripe_has(): void
    {
        $this->expectBillingError();
        $this->stripeReturns(
            $this->endpoint($this->allRequired(), ['status' => 'disabled']),
            $this->endpoint(['*'], ['id' => 'we_api', 'url' => 'https://hooks.example.test/api/webhooks/stripe']),
        );

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('https://hooks.example.test/api/webhooks/stripe (we_api, enabled)')
            ->assertFailed();
    }

    public function test_a_disabled_endpoint_is_ignored_when_an_enabled_one_does_the_job(): void
    {
        // The old endpoint was switched off and replaced by a new one on the same URL.
        $this->stripeReturns(
            $this->endpoint(['invoice.paid'], ['id' => 'we_old', 'status' => 'disabled']),
            $this->endpoint($this->allRequired(), ['id' => 'we_new']),
        );

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('listens for every event the app needs')
            ->assertSuccessful();
    }

    public function test_fails_when_the_only_enabled_endpoint_is_missing_events_even_if_a_disabled_one_has_them(): void
    {
        $this->expectBillingError();
        $this->stripeReturns(
            $this->endpoint(['*'], ['id' => 'we_old', 'status' => 'disabled']),
            $this->endpoint(['invoice.paid'], ['id' => 'we_new']),
        );

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('(we_new) is not subscribed to')
            ->assertFailed();
    }

    public function test_two_enabled_endpoints_on_the_same_url_pass_with_a_warning(): void
    {
        $this->stripeReturns(
            $this->endpoint($this->allRequired(), ['id' => 'we_a']),
            $this->endpoint($this->allRequired(), ['id' => 'we_b']),
        );

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('2 enabled endpoints point at this app')
            ->assertSuccessful();
    }

    public function test_fails_when_no_endpoint_points_at_this_app(): void
    {
        $this->expectBillingError();
        $this->stripeReturns($this->endpoint(['*'], ['url' => 'https://other.example.test/hooks']));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('No Stripe webhook delivers to')
            ->assertFailed();
    }

    public function test_only_the_endpoint_on_this_host_is_checked(): void
    {
        // Staging shares the Stripe account and is missing events: not our problem here.
        $this->stripeReturns(
            $this->endpoint(['invoice.paid'], ['id' => 'we_staging', 'url' => 'https://staging.example.test/api/webhooks/stripe']),
            $this->endpoint($this->allRequired()),
        );

        $this->artisan('stripe:check-webhooks')->assertSuccessful();
    }

    public function test_a_webhook_that_only_the_event_destinations_api_lists_is_found(): void
    {
        // Stripe's endpoint is on the public site's host, which proxies /api to
        // the app (APP_URL is the api host); it is matched by its path.
        $this->noOlderEndpoints();
        $this->destinationsAre($this->destination($this->allRequired()));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('https://example.test/api/webhooks/stripe listens for every event the app needs')
            ->assertSuccessful();

        $this->assertSame(['webhook_endpoint.url'], $this->stripe->requestsTo('GET', '/v2/core/event_destinations')[0]['params']['include']);
    }

    public function test_an_event_destination_missing_events_fails(): void
    {
        $this->expectBillingError();
        $this->noOlderEndpoints();
        $this->destinationsAre($this->destination(['invoice.paid']));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('(ed_1) is not subscribed to')
            ->assertFailed();
    }

    public function test_an_event_destination_that_sends_thin_events_fails(): void
    {
        $this->expectBillingError();
        $this->noOlderEndpoints();
        $this->destinationsAre($this->destination(['*'], ['event_payload' => 'thin']));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('sends thin events')
            ->assertFailed();
    }

    public function test_the_same_webhook_listed_by_both_apis_counts_once(): void
    {
        $this->stripe->on('GET', '/v1/webhook_endpoints', [
            'object' => 'list', 'url' => '/v1/webhook_endpoints', 'has_more' => false,
            'data' => [$this->endpoint($this->allRequired(), ['url' => 'https://example.test/api/webhooks/stripe'])],
        ]);
        $this->destinationsAre($this->destination($this->allRequired()));

        $this->artisan('stripe:check-webhooks')
            ->doesntExpectOutputToContain('enabled endpoints point at this app')
            ->assertSuccessful();
    }

    public function test_other_kinds_of_destination_are_ignored(): void
    {
        $this->expectBillingError();
        $this->noOlderEndpoints();
        $this->destinationsAre($this->destination(['*'], ['type' => 'amazon_eventbridge', 'webhook_endpoint' => null]));

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('No Stripe webhook')
            ->assertFailed();
    }

    public function test_the_older_list_still_decides_when_event_destinations_cannot_be_read(): void
    {
        $this->stripe->on('GET', '/v1/webhook_endpoints', [
            'object' => 'list', 'url' => '/v1/webhook_endpoints', 'has_more' => false,
            'data' => [$this->endpoint($this->allRequired())],
        ]);
        $this->stripe->failOn('GET', '/v2/core/event_destinations', 403, 'Not allowed');

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('Could not read Stripe event destinations')
            ->assertSuccessful();
    }

    public function test_nothing_found_and_event_destinations_unreadable_says_so(): void
    {
        $this->expectBillingError();
        $this->noOlderEndpoints();
        $this->stripe->failOn('GET', '/v2/core/event_destinations', 403, 'Not allowed');

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('Could not read Stripe event destinations: Not allowed')
            ->assertFailed();
    }

    public function test_fails_when_stripe_refuses_the_lookup(): void
    {
        $this->expectBillingError();
        $this->stripe->failOn('GET', '/v1/webhook_endpoints', 403, 'The provided key does not have the required permissions.');

        $this->artisan('stripe:check-webhooks')
            ->expectsOutputToContain('required permissions')
            ->assertFailed();
    }

    public function test_every_required_event_is_one_the_webhook_handles(): void
    {
        foreach (StripeWebhookController::REQUIRED_EVENTS as $alternatives) {
            foreach ($alternatives as $event) {
                $this->assertContains($event, StripeWebhookController::HANDLED_EVENTS);
            }
        }
    }

    public function test_the_webhook_has_a_handler_for_every_event_it_claims(): void
    {
        foreach (StripeWebhookController::HANDLED_EVENTS as $event) {
            Log::spy();

            // An empty object makes each handler return early; what matters is
            // that the event is routed to one at all.
            $this->postJson('/api/webhooks/stripe', ['type' => $event, 'data' => ['object' => []]])->assertOk();

            Log::shouldNotHaveReceived('info', fn (string $message) => str_contains($message, 'Unhandled event type'));
        }
    }

    public function test_an_event_outside_the_list_is_reported_as_unhandled(): void
    {
        Log::spy();

        $this->postJson('/api/webhooks/stripe', ['type' => 'charge.refunded', 'data' => ['object' => []]])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'Unhandled event type'))->once();
    }
}
