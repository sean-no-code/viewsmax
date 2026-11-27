<?php

namespace Tests\Feature;

use App\Mail\InvoicePaidMail;
use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Webhook delivery covers two shapes for "an invoice got paid":
 *   - legacy:   invoice.paid / invoice.payment_succeeded   (object = invoice)
 *   - current:  invoice_payment.paid                        (object = invoice_payment)
 *
 * Stripe doesn't reliably send the legacy event anymore — a trial→active
 * conversion (and renewals) can arrive ONLY as invoice_payment.paid. If we
 * ignore it, the credit reset never runs and the paid user is stuck on the
 * trial balance. These tests pin both paths to the same allocation, and the
 * idempotency guard so both firing together can't double-allocate.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.signature_check' => false]); // skip sig verify in tests

        // The invoice email fetches Stripe's PDF to attach it; nothing else
        // in these tests may reach the network.
        Http::preventStrayRequests();
        Http::fake(['pay.stripe.com/*' => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf'])]);
    }

    private const PDF = "%PDF-1.4\nfake invoice\n%%EOF";

    private function proPlan(): Plan
    {
        return Plan::create([
            'name' => 'pro',
            'display_name' => 'Pro',
            'price' => 99.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'stripe_price_id' => 'price_pro',
        ]);
    }

    private function subscribeTrialing(User $user, Plan $plan, string $subId): void
    {
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => $subId,
            'stripe_price_id' => $plan->stripe_price_id,
            'status' => 'trialing',
            'starts_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    /** Stripe reports the sub as active with a paid invoice on it. */
    private function mockStripeConverted(string $subId, string $invoiceId): void
    {
        $periodEnd = now()->addMonth()->timestamp;

        $this->mock(StripeService::class, function ($mock) use ($subId, $invoiceId, $periodEnd) {
            $mock->shouldReceive('getSubscription')->with($subId)->andReturn([
                'status' => 'active',
                'current_period_end' => $periodEnd,
                'items' => ['data' => [[
                    'price' => ['id' => 'price_pro'],
                    'current_period_end' => $periodEnd,
                ]]],
                'metadata' => [],
            ]);

            // The real, paid renewal/conversion invoice (non-zero, subscription_cycle).
            $mock->shouldReceive('getInvoice')->with($invoiceId)->andReturn([
                'id' => $invoiceId,
                'subscription' => $subId,
                'amount_paid' => 9900,
                'billing_reason' => 'subscription_cycle',
                'status' => 'paid',
            ]);
        });
    }

    public function test_checkout_completed_marks_card_added(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_1']);
        $this->proPlan();
        $this->assertNull($user->card_added_at);

        $periodEnd = now()->addMonth()->timestamp;
        $this->mock(StripeService::class, function ($mock) use ($periodEnd) {
            $mock->shouldReceive('getSubscription')->with('sub_new')->andReturn([
                'status' => 'active',
                'current_period_end' => $periodEnd,
                'items' => ['data' => [[
                    'price' => ['id' => 'price_pro'],
                    'current_period_end' => $periodEnd,
                ]]],
                'metadata' => [],
                'latest_invoice' => ['amount_paid' => 9900],
            ]);
            $mock->shouldReceive('subscriptionHasTrial')->andReturn(false);
        });

        // Completing checkout is the moment the card was actually entered —
        // the Stripe customer already existed before the card screen.
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_1',
                'subscription' => 'sub_new',
                'customer' => 'cus_1',
                'client_reference_id' => (string) $user->id,
            ]],
        ])->assertOk();

        $this->assertNotNull($user->fresh()->card_added_at);
    }

    /**
     * Affiliate signups: client_reference_id carries the Rewardful referral
     * UUID, so the webhook must resolve the user from metadata.user_id and
     * never misread the UUID as a user id.
     */
    public function test_checkout_completed_resolves_user_from_metadata_when_client_reference_is_a_referral_uuid(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_1']);
        $this->proPlan();

        $periodEnd = now()->addMonth()->timestamp;
        $this->mock(StripeService::class, function ($mock) use ($periodEnd) {
            $mock->shouldReceive('getSubscription')->with('sub_ref')->andReturn([
                'status' => 'active',
                'current_period_end' => $periodEnd,
                'items' => ['data' => [[
                    'price' => ['id' => 'price_pro'],
                    'current_period_end' => $periodEnd,
                ]]],
                'metadata' => [],
                'latest_invoice' => ['amount_paid' => 9900],
            ]);
            $mock->shouldReceive('subscriptionHasTrial')->andReturn(false);
        });

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_ref',
                'subscription' => 'sub_ref',
                'customer' => 'cus_1',
                'client_reference_id' => 'c33e60ec-71f8-4f14-9c5c-3e0e0f4c8b1a', // Rewardful UUID
                'metadata' => ['user_id' => (string) $user->id],
            ]],
        ])->assertOk();

        $this->assertNotNull($user->fresh()->card_added_at);
    }

    public function test_invoice_payment_paid_event_allocates_paid_credits_and_activates(): void
    {
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_conv');
        $user->deposit(250); // trial balance

        $this->mockStripeConverted('sub_conv', 'in_conv');

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice_payment.paid',
            'data' => ['object' => [
                'id' => 'inpay_1',
                'object' => 'invoice_payment',
                'invoice' => 'in_conv',
                'status' => 'paid',
            ]],
        ])->assertOk();

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('active', $pivot->status);
        $this->assertSame(1000, $user->fresh()->balanceInt); // reset 250 -> 1000
    }

    public function test_invoice_payment_paid_is_idempotent_with_legacy_invoice_paid(): void
    {
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_conv');
        $user->deposit(250);

        $this->mockStripeConverted('sub_conv', 'in_conv');

        // Legacy event first (object = the invoice itself).
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_conv',
                'subscription' => 'sub_conv',
                'amount_paid' => 9900,
                'billing_reason' => 'subscription_cycle',
            ]],
        ])->assertOk();

        // Then the new event for the SAME invoice — must not double-allocate.
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice_payment.paid',
            'data' => ['object' => [
                'id' => 'inpay_1',
                'invoice' => 'in_conv',
                'status' => 'paid',
            ]],
        ])->assertOk();

        $this->assertSame(1000, $user->fresh()->balanceInt); // still 1000, not 1750
    }

    /**
     * A late payment of an invoice whose subscription Stripe has already
     * cancelled does not bring the subscription back (Stripe never renews it),
     * so it must not mark the row active or grant credits. It is raised at
     * error level so a person can refund it.
     */
    public function test_invoice_paid_for_a_canceled_subscription_does_not_reactivate_or_allocate(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $cancelledAt = now()->subDay()->startOfSecond();
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_dead',
            'stripe_price_id' => 'price_pro',
            'status' => 'past_due',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
            'cancelled_at' => $cancelledAt,
        ]);
        $user->deposit(40);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->with('sub_dead')->andReturn([
                'status' => 'canceled',
                'current_period_end' => now()->subDay()->timestamp,
                'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
                'metadata' => [],
            ]);
        });

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_late',
                'subscription' => 'sub_dead',
                'amount_paid' => 9900,
                'currency' => 'usd',
                'billing_reason' => 'subscription_cycle',
            ]],
        ])->assertOk(); // 200 so Stripe stops re-delivering; a person handles it

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('past_due', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
        $this->assertSame(40, $user->fresh()->balanceInt);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'cancelled subscription')
                && $context['user_id'] === $user->id
                && $context['invoice_id'] === 'in_late'
                && $context['amount_paid'] === 9900
                && $context['currency'] === 'usd')
            ->once();
    }

    private function attachSub(User $user, Plan $plan, string $subId, string $status, array $extra = []): void
    {
        $user->plans()->attach($plan->id, array_merge([
            'stripe_subscription_id' => $subId,
            'stripe_price_id' => $plan->stripe_price_id,
            'status' => $status,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addDays(3),
        ], $extra));
    }

    private function deleteEvent(string $subId): array
    {
        return [
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => $subId, 'status' => 'canceled']],
        ];
    }

    /**
     * Stripe ending a past-due subscription (retries exhausted) must close the
     * row: the daily expiry run never selects past_due, so it would otherwise
     * stay past_due, and show the "settle your invoice" banner, forever.
     */
    public function test_subscription_deleted_closes_a_past_due_row_and_voids_open_invoices(): void
    {
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->attachSub($user, $plan, 'sub_pd', 'past_due');

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('voidOpenInvoices')
            ->once()->with('sub_pd')->andReturn(1));

        $this->postJson('/api/webhooks/stripe', $this->deleteEvent('sub_pd'))->assertOk();

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('expired', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
    }

    /** A past-due row the user had already cancelled is still closed. */
    public function test_subscription_deleted_closes_a_past_due_row_that_was_already_marked_cancelled(): void
    {
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->attachSub($user, $plan, 'sub_pd2', 'past_due', ['cancelled_at' => now()->subDays(2)]);

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('voidOpenInvoices')
            ->once()->with('sub_pd2')->andReturn(0));

        $this->postJson('/api/webhooks/stripe', $this->deleteEvent('sub_pd2'))->assertOk();

        $this->assertSame('expired', $user->plans()->where('plans.id', $plan->id)->first()->pivot->status);
    }

    /** An active row keeps its graceful cancel: access until expires_at. */
    public function test_subscription_deleted_keeps_an_active_row_until_it_expires(): void
    {
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->attachSub($user, $plan, 'sub_act', 'active');

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('voidOpenInvoices')
            ->once()->with('sub_act')->andReturn(0));

        $this->postJson('/api/webhooks/stripe', $this->deleteEvent('sub_act'))->assertOk();

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('active', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
    }

    /**
     * An unpaid trial that Stripe ended (cancelled in the portal, by support)
     * closes at once, and the free window it ran on closes with it: there is
     * no paid period to run out.
     */
    public function test_subscription_deleted_closes_an_unpaid_trial_and_its_free_window(): void
    {
        $user = User::factory()->create(['promo_expires_at' => now()->addDays(5)]);
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_tr');

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('voidOpenInvoices')
            ->once()->with('sub_tr')->andReturn(0));

        $this->postJson('/api/webhooks/stripe', $this->deleteEvent('sub_tr'))->assertOk();

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('expired', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
        $this->assertFalse($user->fresh()->freeWindowOpen());
        $this->assertTrue($user->fresh()->accessExpired());
    }

    /**
     * A trial row replaced by a new subscription is `inactive` here; Stripe
     * deleting the old one must not close the window the new trial runs on.
     */
    public function test_subscription_deleted_for_a_replaced_trial_leaves_the_window_open(): void
    {
        $user = User::factory()->create(['promo_expires_at' => now()->addDays(5)]);
        $plan = $this->proPlan();
        $this->attachSub($user, $plan, 'sub_old', 'inactive', ['cancelled_at' => now()]);
        $this->subscribeTrialing($user, $plan, 'sub_new');

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('voidOpenInvoices')
            ->once()->with('sub_old')->andReturn(0));

        $this->postJson('/api/webhooks/stripe', $this->deleteEvent('sub_old'))->assertOk();

        $this->assertTrue($user->fresh()->freeWindowOpen());
        $this->assertTrue($user->fresh()->hasActivePlan());
    }

    /**
     * A trial cancelled in the Stripe portal arrives as "cancel at period
     * end". Nothing was paid, so it is ended on Stripe now and closed here.
     */
    public function test_a_portal_cancel_of_an_unpaid_trial_ends_it_now(): void
    {
        $user = User::factory()->create(['promo_expires_at' => now()->addDays(5)]);
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_portal');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('cancelSubscription')->once()
                ->withArgs(fn ($id, $reason = null, $immediately = false) => $id === 'sub_portal' && $immediately === true)
                ->andReturn(['status' => 'canceled']);
            $mock->shouldReceive('voidOpenInvoices')->once()->with('sub_portal')->andReturn(0);
        });

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_portal',
                'status' => 'trialing',
                'cancel_at_period_end' => true,
                'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
            ]],
        ])->assertOk();

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('expired', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
        $this->assertFalse($user->fresh()->freeWindowOpen());
    }

    /** A paid plan cancelled in the portal keeps running to the end of its period. */
    public function test_a_portal_cancel_of_a_paid_plan_stays_graceful(): void
    {
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->attachSub($user, $plan, 'sub_paid', 'active');

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldNotReceive('cancelSubscription'));

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_paid',
                'status' => 'active',
                'cancel_at_period_end' => true,
                'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
            ]],
        ])->assertOk();

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('active', $pivot->status);
        $this->assertNotNull($pivot->cancelled_at);
        $this->assertTrue($user->fresh()->hasActivePlan());
    }

    /** No local row yet: a paid invoice of a dead subscription attaches nothing. */
    public function test_invoice_paid_for_a_canceled_subscription_attaches_no_plan(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_dead']);
        $this->proPlan();

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->with('sub_gone')->andReturn([
                'status' => 'canceled',
                'customer' => 'cus_dead',
                'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
                'metadata' => [],
            ]);
        });

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_gone',
                'subscription' => 'sub_gone',
                'amount_paid' => 9900,
                'billing_reason' => 'subscription_cycle',
            ]],
        ])->assertOk();

        $this->assertSame(0, $user->plans()->count());
        $this->assertSame(0, $user->fresh()->balanceInt);
    }

    // --- Item 11: invoice email per paid billing cycle ----------------------

    private function paidInvoice(array $extra = []): array
    {
        return array_merge([
            'id' => 'in_cycle',
            'subscription' => 'sub_conv',
            'amount_paid' => 9900,
            'currency' => 'usd',
            'billing_reason' => 'subscription_cycle',
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct/in_cycle',
            'invoice_pdf' => 'https://pay.stripe.com/invoice/acct/in_cycle/pdf',
            'number' => 'VMX-0042',
            'status_transitions' => ['paid_at' => 1_794_000_000],
            'lines' => ['data' => [
                // A proration line from an earlier upgrade comes first; the
                // period shown is the plan line's.
                ['proration' => true, 'period' => ['start' => 1_790_000_000, 'end' => 1_791_000_000]],
                ['proration' => false, 'period' => ['start' => 1_794_000_000, 'end' => 1_796_592_000]],
            ]],
        ], $extra);
    }

    private function postInvoicePaid(array $invoice, string $type = 'invoice.paid')
    {
        return $this->postJson('/api/webhooks/stripe', ['type' => $type, 'data' => ['object' => $invoice]]);
    }

    public function test_paid_invoice_emails_the_customer_once(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'payer@example.com', 'name' => 'Pat']);
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_conv');
        $this->mockStripeConverted('sub_conv', 'in_cycle');

        $this->postInvoicePaid($this->paidInvoice())->assertOk();
        // Stripe re-delivers, and also sends the other paid events for it.
        $this->postInvoicePaid($this->paidInvoice())->assertOk();
        $this->postInvoicePaid($this->paidInvoice(), 'invoice.payment_succeeded')->assertOk();

        Mail::assertSent(InvoicePaidMail::class, 1);
        $mail = Mail::sent(InvoicePaidMail::class)->first();
        $this->assertTrue($mail->hasTo('payer@example.com'));
        $this->assertSame([
            'name' => 'Pat',
            'planName' => 'Pro',
            'amount' => '$99.00 USD',
            'invoiceNumber' => 'VMX-0042',
            'periodStart' => 'November 6, 2026',
            'periodEnd' => 'December 6, 2026',
            'paidAt' => 'November 6, 2026 at 9:20pm UTC',
            'invoiceUrl' => 'https://invoice.stripe.com/i/acct/in_cycle',
            'pdfUrl' => 'https://pay.stripe.com/invoice/acct/in_cycle/pdf',
        ], [
            'name' => $mail->name,
            'planName' => $mail->planName,
            'amount' => $mail->amount,
            'invoiceNumber' => $mail->invoiceNumber,
            'periodStart' => $mail->periodStart,
            'periodEnd' => $mail->periodEnd,
            'paidAt' => $mail->paidAt,
            'invoiceUrl' => $mail->invoiceUrl,
            'pdfUrl' => $mail->pdfUrl,
        ]);
        // The view renders with every detail and both links.
        $html = $mail->render();
        $this->assertStringContainsString('$99.00 USD', $html);
        $this->assertStringContainsString('November 6, 2026 to December 6, 2026', $html);
        $this->assertStringContainsString('https://pay.stripe.com/invoice/acct/in_cycle/pdf', $html);
    }

    private function sendInvoiceEmail(array $invoiceExtra = []): InvoicePaidMail
    {
        Mail::fake();
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_conv');
        $this->mockStripeConverted('sub_conv', 'in_cycle');

        $this->postInvoicePaid($this->paidInvoice($invoiceExtra))->assertOk();

        Mail::assertSent(InvoicePaidMail::class, 1);

        return Mail::sent(InvoicePaidMail::class)->first();
    }

    public function test_the_invoice_email_carries_the_pdf_invoice(): void
    {
        $mail = $this->sendInvoiceEmail();

        $mail->assertHasAttachedData(self::PDF, 'Invoice-VMX-0042.pdf', ['mime' => 'application/pdf']);
        $this->assertStringContainsString('attached', $mail->render());
        Http::assertSentCount(1);
    }

    public function test_the_invoice_email_still_goes_out_when_the_pdf_cannot_be_fetched(): void
    {
        Http::fake(['files.stripe.com/*' => Http::response('gone', 500)]);

        $mail = $this->sendInvoiceEmail(['invoice_pdf' => 'https://files.stripe.com/invoice/in_cycle/pdf']);

        $this->assertSame([], $mail->attachments());
        $html = $mail->render();
        $this->assertStringNotContainsString('attached', $html);
        $this->assertStringContainsString('https://files.stripe.com/invoice/in_cycle/pdf', $html);
    }

    public function test_a_response_that_is_not_a_pdf_is_not_attached(): void
    {
        Http::fake(['files.stripe.com/*' => Http::response('<html>Sign in</html>', 200)]);

        $mail = $this->sendInvoiceEmail(['invoice_pdf' => 'https://files.stripe.com/invoice/in_cycle/pdf']);

        $this->assertSame([], $mail->attachments());
    }

    public function test_a_pdf_link_outside_stripe_is_never_fetched(): void
    {
        $mail = $this->sendInvoiceEmail(['invoice_pdf' => 'https://stripe.com.example.test/in_cycle.pdf']);

        $this->assertSame([], $mail->attachments());
        Http::assertNothingSent();
    }

    public function test_zero_amount_invoice_sends_no_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_conv');
        $this->mockStripeConverted('sub_conv', 'in_cycle');

        $this->postInvoicePaid($this->paidInvoice(['amount_paid' => 0, 'billing_reason' => 'subscription_create']))->assertOk();

        Mail::assertNotSent(InvoicePaidMail::class);
    }

    public function test_invoice_email_can_be_switched_off(): void
    {
        Mail::fake();
        config(['billing.emails.invoice' => false]);
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_conv');
        $user->deposit(250);
        $this->mockStripeConverted('sub_conv', 'in_cycle');

        $this->postInvoicePaid($this->paidInvoice())->assertOk();

        Mail::assertNotSent(InvoicePaidMail::class);
        $this->assertSame(1000, $user->fresh()->balanceInt); // billing itself unaffected
    }

    public function test_a_failed_invoice_email_does_not_fail_the_webhook(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $user = User::factory()->create();
        $plan = $this->proPlan();
        $this->subscribeTrialing($user, $plan, 'sub_conv');
        $user->deposit(250);
        $this->mockStripeConverted('sub_conv', 'in_cycle');

        $this->postInvoicePaid($this->paidInvoice())->assertOk();

        $pivot = $user->plans()->where('plans.id', $plan->id)->first()->pivot;
        $this->assertSame('active', $pivot->status);
        $this->assertSame(1000, $user->fresh()->balanceInt);
        \Illuminate\Support\Facades\Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'SMTP down');
    }

    public function test_invoice_paid_for_a_canceled_subscription_sends_no_email(): void
    {
        Mail::fake();
        $user = User::factory()->create(['stripe_customer_id' => 'cus_dead']);
        $this->proPlan();
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('getSubscription')->andReturn([
            'status' => 'canceled', 'customer' => 'cus_dead', 'metadata' => [],
            'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
        ]));

        $this->postInvoicePaid($this->paidInvoice(['subscription' => 'sub_dead']))->assertOk();

        Mail::assertNotSent(InvoicePaidMail::class);
    }
}
