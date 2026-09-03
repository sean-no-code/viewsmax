<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

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
}
