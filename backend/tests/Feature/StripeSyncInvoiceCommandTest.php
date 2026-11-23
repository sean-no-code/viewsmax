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
 * stripe:sync-invoice applies a paid Stripe invoice by hand, for a payment
 * whose webhook never arrived. It runs the same logic as the invoice.paid
 * webhook, so it is safe to run twice.
 */
class StripeSyncInvoiceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function trialingUser(): User
    {
        $plan = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro', 'price' => 19.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create();
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_1', 'stripe_price_id' => 'price_pro',
            'status' => 'trialing', 'starts_at' => now()->subDays(7), 'expires_at' => now(),
        ]);

        return $user;
    }

    private function stripeHas(array $invoice): void
    {
        $periodEnd = now()->addMonth()->timestamp;
        $this->mock(StripeService::class, function ($mock) use ($invoice, $periodEnd) {
            $mock->shouldReceive('getInvoice')->with('in_1')->andReturn($invoice);
            $mock->shouldReceive('getSubscription')->with('sub_1')->andReturn([
                'status' => 'active', 'customer' => 'cus_1', 'metadata' => [],
                'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_end' => $periodEnd]]],
            ]);
        });
    }

    private function paidInvoice(array $extra = []): array
    {
        return array_merge([
            'id' => 'in_1', 'object' => 'invoice', 'status' => 'paid', 'customer' => 'cus_1',
            'parent' => ['subscription_details' => ['subscription' => 'sub_1']],
            'amount_paid' => 1900, 'currency' => 'usd', 'billing_reason' => 'subscription_cycle',
        ], $extra);
    }

    public function test_it_applies_a_paid_invoice_like_the_webhook_would(): void
    {
        $user = $this->trialingUser();
        $this->stripeHas($this->paidInvoice());

        $this->artisan('stripe:sync-invoice', ['invoice' => 'in_1'])
            ->expectsOutputToContain('credits allocated, plan active')
            ->assertSuccessful();

        $pivot = $user->fresh()->plans()->first()->pivot;
        $this->assertSame('active', $pivot->status);
        $this->assertSame((int) config('credits.subscription_credits.default'), $user->fresh()->balanceInt);
        Mail::assertSent(InvoicePaidMail::class, 1);
    }

    public function test_running_it_again_changes_nothing_more(): void
    {
        $user = $this->trialingUser();
        $this->stripeHas($this->paidInvoice());

        $this->artisan('stripe:sync-invoice', ['invoice' => 'in_1'])->assertSuccessful();
        $this->artisan('stripe:sync-invoice', ['invoice' => 'in_1'])
            ->expectsOutputToContain('invoice already processed')
            ->assertSuccessful();

        $this->assertSame((int) config('credits.subscription_credits.default'), $user->fresh()->balanceInt);
        Mail::assertSent(InvoicePaidMail::class, 1);
    }

    public function test_an_invoice_that_is_not_paid_is_left_alone(): void
    {
        $user = $this->trialingUser();
        $this->stripeHas($this->paidInvoice(['status' => 'open']));

        $this->artisan('stripe:sync-invoice', ['invoice' => 'in_1'])
            ->expectsOutputToContain('is open in Stripe, not paid')
            ->assertFailed();

        $this->assertSame('trialing', $user->fresh()->plans()->first()->pivot->status);
    }

    public function test_it_asks_for_the_invoice_id_when_given_another_kind_of_id(): void
    {
        $this->mock(StripeService::class)->shouldNotReceive('getInvoice');

        $this->artisan('stripe:sync-invoice', ['invoice' => 'inpay_1'])
            ->expectsOutputToContain('Pass the invoice id')
            ->assertFailed();
    }

    public function test_a_failure_is_reported_not_thrown(): void
    {
        $this->mock(StripeService::class)->shouldReceive('getInvoice')->andThrow(new \Exception('No such invoice'));

        $this->artisan('stripe:sync-invoice', ['invoice' => 'in_1'])
            ->expectsOutputToContain('No such invoice')
            ->assertFailed();
    }
}
