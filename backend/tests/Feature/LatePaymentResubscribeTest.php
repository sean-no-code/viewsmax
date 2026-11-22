<?php

namespace Tests\Feature;

use App\Mail\InvoicePaidMail;
use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Stripe ends a subscription when its retries run out and leaves the invoice
 * payable. A customer who pays it afterwards gets a new subscription on the
 * same plan (Stripe cannot revive the old one), starting with the period they
 * just paid for. Anything that does not look like exactly that grants nothing
 * and is raised for a person to refund.
 */
class LatePaymentResubscribeTest extends TestCase
{
    use RefreshDatabase;

    private const ENDED_AT = 1_794_000_000;

    private const PAID_AT = 1_794_200_000; // two days after the subscription ended

    private const NEXT_BILLING = 1_796_800_000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['services.stripe.signature_check' => false]);
    }

    private function plan(string $name = 'pro', string $priceId = 'price_pro'): Plan
    {
        return Plan::create([
            'name' => $name,
            'display_name' => ucfirst($name),
            'price' => 99.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'stripe_price_id' => $priceId,
        ]);
    }

    /** A customer whose subscription Stripe ended for non-payment. */
    private function lapsedCustomer(?Plan $plan = null): User
    {
        $plan ??= $this->plan();
        $user = User::factory()->expired()->create(['stripe_customer_id' => 'cus_1', 'card_added_at' => null]);
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_old',
            'stripe_price_id' => $plan->stripe_price_id,
            'status' => 'expired',
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subDays(2),
            'cancelled_at' => now()->subDays(2),
        ]);

        return $user;
    }

    private function oldSubscription(array $extra = []): array
    {
        return array_merge([
            'id' => 'sub_old',
            'customer' => 'cus_1',
            'status' => 'canceled',
            'ended_at' => self::ENDED_AT,
            'canceled_at' => self::ENDED_AT,
            'metadata' => [],
            'items' => ['data' => [[
                'quantity' => 1,
                'price' => ['id' => 'price_pro', 'recurring' => ['interval' => 'month', 'interval_count' => 1]],
            ]]],
        ], $extra);
    }

    private function newSubscription(array $extra = []): array
    {
        return array_merge([
            'id' => 'sub_new',
            'customer' => 'cus_1',
            'status' => 'active',
            'metadata' => [StripeService::LATE_PAYMENT_INVOICE => 'in_late'],
            'items' => ['data' => [[
                'quantity' => 1,
                'current_period_end' => self::NEXT_BILLING,
                'price' => ['id' => 'price_pro'],
            ]]],
        ], $extra);
    }

    private function invoice(array $extra = []): array
    {
        return array_merge([
            'id' => 'in_late',
            'subscription' => 'sub_old',
            'amount_paid' => 9900,
            'amount_remaining' => 0,
            'currency' => 'usd',
            'billing_reason' => 'subscription_cycle',
            'status_transitions' => ['paid_at' => self::PAID_AT],
            'lines' => ['data' => [[
                'quantity' => 1,
                'pricing' => ['price_details' => ['price' => 'price_pro']],
            ]]],
        ], $extra);
    }

    private function paid(array $invoice): void
    {
        $this->postJson('/api/webhooks/stripe', ['type' => 'invoice.paid', 'data' => ['object' => $invoice]])->assertOk();
    }

    /** Stripe as it looks when a late payment is eligible: only the dead subscription exists. */
    private function stripe(?array $old = null, ?array $all = null): MockInterface
    {
        $old ??= $this->oldSubscription();
        $mock = $this->mock(StripeService::class);
        $mock->shouldReceive('getSubscription')->with('sub_old')->andReturn($old);
        $mock->shouldReceive('listSubscriptions')->with('cus_1')->andReturn($all ?? [$old])->byDefault();

        return $mock;
    }

    private function assertRaisedForRefund(User $user): void
    {
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'cancelled subscription')
                && ($context['user_id'] ?? null) === $user->id
                && ($context['invoice_id'] ?? null) === 'in_late')
            ->once();
        $this->assertFalse($user->fresh()->hasActivePlan());
        $this->assertSame(0, $user->fresh()->balanceInt);
        Mail::assertNotSent(InvoicePaidMail::class);
    }

    public function test_a_late_payment_starts_a_new_subscription_on_the_same_plan(): void
    {
        $plan = $this->plan();
        $user = $this->lapsedCustomer($plan);
        $stripe = $this->stripe();
        $stripe->shouldReceive('resubscribeAfterLatePayment')->once()
            ->withArgs(fn (array $old, string $invoiceId, int $paidAt) => $old['id'] === 'sub_old' && $invoiceId === 'in_late' && $paidAt === self::PAID_AT)
            ->andReturn($this->newSubscription());
        $stripe->shouldReceive('voidOpenInvoices')->once()->with('sub_old')->andReturn(0);

        $this->paid($this->invoice());

        $user = $user->fresh();
        $this->assertTrue($user->hasActivePlan());
        $new = $user->plans()->wherePivot('stripe_subscription_id', 'sub_new')->first();
        $this->assertSame($plan->id, $new->id);
        $this->assertSame('active', $new->pivot->status);
        $this->assertSame('price_pro', $new->pivot->stripe_price_id);
        $this->assertNull($new->pivot->cancelled_at);
        $this->assertSame(self::NEXT_BILLING, \Carbon\Carbon::parse($new->pivot->expires_at)->getTimestamp());

        // The dead subscription's row is history, untouched.
        $this->assertSame('expired', $user->plans()->wherePivot('stripe_subscription_id', 'sub_old')->first()->pivot->status);

        $this->assertSame((int) config('credits.subscription_credits.default'), $user->balanceInt);
        $this->assertNotNull($user->card_added_at);
        Mail::assertSent(InvoicePaidMail::class, 1);
    }

    public function test_the_plan_is_the_one_the_price_belongs_to(): void
    {
        $this->plan('starter', 'price_starter'); // also Plan::getDefaultPlan()'s fallback must not win
        $pro = $this->plan();
        $user = $this->lapsedCustomer($pro);
        $stripe = $this->stripe();
        $stripe->shouldReceive('resubscribeAfterLatePayment')->once()->andReturn($this->newSubscription());
        $stripe->shouldReceive('voidOpenInvoices')->andReturn(0);

        $this->paid($this->invoice());

        $this->assertSame($pro->id, $user->fresh()->plans()->wherePivot('stripe_subscription_id', 'sub_new')->first()->id);
    }

    public function test_a_redelivered_event_does_nothing_more(): void
    {
        $user = $this->lapsedCustomer();
        $stripe = $this->stripe();
        $stripe->shouldReceive('resubscribeAfterLatePayment')->once()->andReturn($this->newSubscription());
        $stripe->shouldReceive('voidOpenInvoices')->once()->andReturn(0);

        $this->paid($this->invoice());
        $this->paid($this->invoice());

        $this->assertSame(1, $user->fresh()->plans()->wherePivot('stripe_subscription_id', 'sub_new')->count());
        Mail::assertSent(InvoicePaidMail::class, 1);
        $this->assertSame((int) config('credits.subscription_credits.default'), $user->fresh()->balanceInt);
    }

    public function test_a_subscription_already_created_for_this_invoice_is_adopted_not_duplicated(): void
    {
        // The first delivery created it in Stripe, then failed before our side committed.
        $user = $this->lapsedCustomer();
        $stripe = $this->stripe(all: [$this->newSubscription(), $this->oldSubscription()]);
        $stripe->shouldNotReceive('resubscribeAfterLatePayment');
        $stripe->shouldReceive('voidOpenInvoices')->once()->andReturn(0);

        $this->paid($this->invoice());

        $this->assertSame('active', $user->fresh()->plans()->wherePivot('stripe_subscription_id', 'sub_new')->first()->pivot->status);
        Mail::assertSent(InvoicePaidMail::class, 1);
    }

    public function test_a_customer_with_no_local_row_for_the_old_subscription_is_resubscribed_too(): void
    {
        $this->plan();
        $user = User::factory()->expired()->create(['stripe_customer_id' => 'cus_1']);
        $stripe = $this->stripe();
        $stripe->shouldReceive('resubscribeAfterLatePayment')->once()->andReturn($this->newSubscription());
        $stripe->shouldReceive('voidOpenInvoices')->andReturn(0);

        $this->paid($this->invoice());

        $this->assertTrue($user->fresh()->hasActivePlan());
    }

    public function test_a_payment_made_before_the_subscription_ended_is_raised_not_resubscribed(): void
    {
        Log::spy();
        $user = $this->lapsedCustomer();
        $this->stripe()->shouldNotReceive('resubscribeAfterLatePayment');

        $this->paid($this->invoice(['status_transitions' => ['paid_at' => self::ENDED_AT - 60]]));

        $this->assertRaisedForRefund($user);
    }

    public function test_a_proration_invoice_is_raised_not_resubscribed(): void
    {
        Log::spy();
        $user = $this->lapsedCustomer();
        $this->stripe()->shouldNotReceive('resubscribeAfterLatePayment');

        $this->paid($this->invoice(['billing_reason' => 'subscription_update']));

        $this->assertRaisedForRefund($user);
    }

    public function test_a_partly_paid_invoice_is_raised_not_resubscribed(): void
    {
        Log::spy();
        $user = $this->lapsedCustomer();
        $this->stripe()->shouldNotReceive('resubscribeAfterLatePayment');

        $this->paid($this->invoice(['amount_remaining' => 4000]));

        $this->assertRaisedForRefund($user);
    }

    public function test_an_invoice_for_a_different_price_is_raised_not_resubscribed(): void
    {
        Log::spy();
        $user = $this->lapsedCustomer();
        $this->stripe()->shouldNotReceive('resubscribeAfterLatePayment');

        $this->paid($this->invoice(['lines' => ['data' => [[
            'quantity' => 1,
            'pricing' => ['price_details' => ['price' => 'price_starter']],
        ]]]]));

        $this->assertRaisedForRefund($user);
    }

    public function test_the_older_invoice_line_shape_is_understood(): void
    {
        $user = $this->lapsedCustomer();
        $stripe = $this->stripe();
        $stripe->shouldReceive('resubscribeAfterLatePayment')->once()->andReturn($this->newSubscription());
        $stripe->shouldReceive('voidOpenInvoices')->andReturn(0);

        $this->paid($this->invoice(['lines' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_pro']]]]]));

        $this->assertTrue($user->fresh()->hasActivePlan());
    }

    public function test_a_price_no_plan_uses_is_raised_not_resubscribed(): void
    {
        Log::spy();
        $user = $this->lapsedCustomer();
        $old = $this->oldSubscription();
        $old['items']['data'][0]['price']['id'] = 'price_retired';
        $this->stripe($old)->shouldNotReceive('resubscribeAfterLatePayment');

        $this->paid($this->invoice(['lines' => ['data' => [[
            'quantity' => 1,
            'pricing' => ['price_details' => ['price' => 'price_retired']],
        ]]]]));

        $this->assertRaisedForRefund($user);
    }

    public function test_a_customer_who_already_has_a_live_plan_is_raised_not_resubscribed(): void
    {
        Log::spy();
        $user = $this->lapsedCustomer();
        $user->plans()->attach($this->plan('agency', 'price_agency')->id, [
            'stripe_subscription_id' => 'sub_live',
            'stripe_price_id' => 'price_agency',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);
        $this->stripe()->shouldNotReceive('resubscribeAfterLatePayment');

        $this->paid($this->invoice());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'cancelled subscription') && ($context['reason'] ?? null) === 'has_live_plan')
            ->once();
        $this->assertSame(0, $user->fresh()->plans()->wherePivot('stripe_subscription_id', 'sub_new')->count());
        Mail::assertNotSent(InvoicePaidMail::class);
    }

    public function test_another_live_subscription_in_stripe_is_raised_not_resubscribed(): void
    {
        Log::spy();
        $user = $this->lapsedCustomer();
        $other = $this->newSubscription(['id' => 'sub_other', 'metadata' => []]);
        $this->stripe(all: [$other, $this->oldSubscription()])->shouldNotReceive('resubscribeAfterLatePayment');

        $this->paid($this->invoice());

        $this->assertRaisedForRefund($user);
    }

    public function test_a_stripe_failure_is_raised_and_the_webhook_still_answers(): void
    {
        Log::spy();
        \Illuminate\Support\Facades\Exceptions::fake();
        $user = $this->lapsedCustomer();
        $this->stripe()->shouldReceive('resubscribeAfterLatePayment')->once()->andThrow(new \RuntimeException('No such price'));

        $this->paid($this->invoice());

        $this->assertRaisedForRefund($user);
        \Illuminate\Support\Facades\Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_subscribing_again_in_the_app_voids_the_invoice_the_old_subscription_left_payable(): void
    {
        config(['services.stripe.default_plan' => 'pro']);
        $user = $this->lapsedCustomer();
        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('getSubscription')->with('sub_fresh')->andReturn([
            'id' => 'sub_fresh',
            'customer' => 'cus_1',
            'status' => 'active',
            'metadata' => ['user_id' => (string) $user->id],
            'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_end' => self::NEXT_BILLING]]],
        ]);
        $stripe->shouldReceive('voidOpenInvoices')->once()->with('sub_old')->andReturn(1);

        $this->paid($this->invoice(['id' => 'in_fresh', 'subscription' => 'sub_fresh', 'billing_reason' => 'subscription_create']));

        $this->assertTrue($user->fresh()->hasActivePlan());
    }

    // --- what happens to the unpaid invoice when the subscription ends -------

    private function deleted(?string $reason): void
    {
        $this->postJson('/api/webhooks/stripe', [
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => array_filter([
                'id' => 'sub_old',
                'status' => 'canceled',
                'cancellation_details' => $reason ? ['reason' => $reason] : null,
            ])],
        ])->assertOk();
    }

    private function pastDueCustomer(): User
    {
        $user = $this->lapsedCustomer();
        $user->plans()->updateExistingPivot($user->plans()->first()->id, ['status' => 'past_due', 'cancelled_at' => null]);

        return $user;
    }

    public function test_a_subscription_stripe_ended_for_non_payment_keeps_its_invoice_payable(): void
    {
        $user = $this->pastDueCustomer();
        $stripe = $this->mock(StripeService::class);
        $stripe->shouldNotReceive('voidOpenInvoices');
        $stripe->shouldReceive('keepCardOfUnpaidInvoices')->once()->with('sub_old');

        $this->deleted('payment_failed');

        $this->assertSame('expired', $user->fresh()->plans()->first()->pivot->status);
    }

    public function test_a_subscription_the_customer_cancelled_has_its_unpaid_invoices_voided(): void
    {
        $user = $this->pastDueCustomer();
        $stripe = $this->mock(StripeService::class);
        $stripe->shouldReceive('voidOpenInvoices')->once()->with('sub_old')->andReturn(1);
        $stripe->shouldNotReceive('keepCardOfUnpaidInvoices');

        $this->deleted('cancellation_requested');

        $this->assertSame('expired', $user->fresh()->plans()->first()->pivot->status);
    }
}
