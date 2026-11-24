<?php

namespace Tests\Feature;

use App\Mail\PaymentFailedMail;
use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A payment must stay applied. Stripe redelivers events for days and a person
 * can re-send one from the dashboard, so an event can describe a state that is
 * no longer true: a failure of an invoice paid since, a `past_due` snapshot of
 * a subscription that is active again. And a customer can hold two rows of the
 * same plan (an ended subscription beside the one that replaced it): an event
 * for one subscription must not write to the other's row.
 */
class StripeWebhookStaleEventsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['services.stripe.signature_check' => false]);

        $this->plan = Plan::create([
            'name' => 'creator', 'display_name' => 'Creator', 'price' => 79.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_creator',
        ]);
    }

    private function row(User $user, string $subscriptionId, string $status, array $extra = []): void
    {
        $user->plans()->attach($this->plan->id, array_merge([
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => 'price_creator',
            'status' => $status,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ], $extra));
    }

    private function rowOf(string $subscriptionId): object
    {
        return DB::table('user_plans')->where('stripe_subscription_id', $subscriptionId)->sole();
    }

    private function send(string $type, array $object, ?int $created = null): void
    {
        $this->postJson('/api/webhooks/stripe', [
            'id' => 'evt_1',
            'object' => 'event',
            'type' => $type,
            'created' => $created ?? now()->timestamp,
            'data' => ['object' => $object],
        ])->assertOk();
    }

    /** What Stripe answers each time it is asked for the subscription: one status per call, the last one repeating. */
    private function stripeHasSubscription(string $id, string|array $status, ?int $periodEnd = null): void
    {
        $answers = array_map(fn (string $status) => [
            'id' => $id, 'status' => $status, 'customer' => 'cus_1', 'metadata' => [], 'cancel_at_period_end' => false,
            'items' => ['data' => [['price' => ['id' => 'price_creator'], 'current_period_end' => $periodEnd ?? now()->addMonth()->timestamp]]],
        ], (array) $status);

        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('getSubscription')->with($id)->andReturn(...$answers));
    }

    private function paidInvoice(string $id = 'in_1', string $subscriptionId = 'sub_1'): array
    {
        return [
            'id' => $id, 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => $subscriptionId,
            'amount_paid' => 7900, 'currency' => 'usd', 'billing_reason' => 'subscription_cycle',
        ];
    }

    // --- One subscription's event writes to that subscription's row only ------

    public function test_a_failed_payment_of_an_old_subscription_leaves_the_new_one_active(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_old', 'expired');
        $this->row($user, 'sub_new', 'active');

        $this->send('invoice.payment_failed', ['id' => 'in_old', 'subscription' => 'sub_old', 'amount_due' => 7900, 'currency' => 'usd']);

        $this->assertSame('active', $this->rowOf('sub_new')->status);
        $this->assertSame('past_due', $this->rowOf('sub_old')->status);
    }

    public function test_an_update_of_an_old_subscription_leaves_the_new_one_alone(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_old', 'past_due');
        $newExpiry = now()->addMonth()->startOfSecond();
        $this->row($user, 'sub_new', 'active', ['expires_at' => $newExpiry]);

        $this->send('customer.subscription.updated', [
            'id' => 'sub_old', 'status' => 'canceled',
            'items' => ['data' => [['price' => ['id' => 'price_creator'], 'current_period_end' => now()->subDay()->timestamp]]],
        ]);

        $new = $this->rowOf('sub_new');
        $this->assertSame('active', $new->status);
        $this->assertTrue($newExpiry->equalTo($new->expires_at));
        $this->assertSame('cancelled', $this->rowOf('sub_old')->status);
    }

    public function test_the_end_of_an_old_subscription_leaves_the_new_one_alone(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_old', 'past_due');
        $this->row($user, 'sub_new', 'active');
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('voidOpenInvoices')->with('sub_old')->andReturn(0));

        $this->send('customer.subscription.deleted', ['id' => 'sub_old', 'status' => 'canceled']);

        $new = $this->rowOf('sub_new');
        $this->assertSame('active', $new->status);
        $this->assertNull($new->cancelled_at);
        $this->assertSame('expired', $this->rowOf('sub_old')->status);
    }

    // --- An event that is no longer true does not undo a payment --------------

    public function test_a_failure_event_for_an_invoice_paid_since_does_not_lock_the_account(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_1', 'past_due');
        $this->stripeHasSubscription('sub_1', 'active');
        $this->send('invoice.paid', $this->paidInvoice());
        $this->assertSame('active', $this->rowOf('sub_1')->status);

        // The failure that came before the payment, redelivered or re-sent.
        $this->send('invoice.payment_failed', ['id' => 'in_1', 'subscription' => 'sub_1', 'amount_due' => 7900, 'currency' => 'usd']);

        $this->assertSame('active', $this->rowOf('sub_1')->status);
        Mail::assertNotSent(PaymentFailedMail::class);
    }

    public function test_a_late_subscription_update_is_read_from_stripe_not_from_its_old_snapshot(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_1', 'active');
        $this->stripeHasSubscription('sub_1', 'active');

        // Two days old: it says past_due, Stripe says the customer has paid since.
        $this->send('customer.subscription.updated', [
            'id' => 'sub_1', 'status' => 'past_due',
            'items' => ['data' => [['price' => ['id' => 'price_creator'], 'current_period_end' => now()->addMonth()->timestamp]]],
        ], now()->subDays(2)->timestamp);

        $this->assertSame('active', $this->rowOf('sub_1')->status);
    }

    public function test_a_prompt_subscription_update_is_applied_as_sent(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_1', 'active');
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldNotReceive('getSubscription'));

        $this->send('customer.subscription.updated', [
            'id' => 'sub_1', 'status' => 'past_due',
            'items' => ['data' => [['price' => ['id' => 'price_creator'], 'current_period_end' => now()->addMonth()->timestamp]]],
        ]);

        $this->assertSame('past_due', $this->rowOf('sub_1')->status);
    }

    // --- A paid invoice applied again repairs the row --------------------------

    public function test_a_paid_invoice_sent_again_puts_a_wrongly_locked_row_back(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_1', 'past_due');
        $periodEnd = now()->addMonth()->startOfSecond();
        $this->stripeHasSubscription('sub_1', 'active', $periodEnd->timestamp);
        $this->send('invoice.paid', $this->paidInvoice());
        $balance = $user->refresh()->balanceInt;

        // Something set the row back (a stale event, a manual edit).
        DB::table('user_plans')->where('stripe_subscription_id', 'sub_1')->update(['status' => 'past_due', 'expires_at' => now()->subDay()]);

        $this->send('invoice.paid', $this->paidInvoice());

        $row = $this->rowOf('sub_1');
        $this->assertSame('active', $row->status);
        $this->assertTrue($periodEnd->equalTo($row->expires_at));
        $this->assertSame($balance, $user->refresh()->balanceInt, 'credits are granted once per invoice');
    }

    public function test_a_paid_invoice_sent_again_does_not_revive_a_subscription_stripe_no_longer_bills(): void
    {
        $user = User::factory()->create();
        $this->row($user, 'sub_1', 'active');
        // Active when the invoice was paid, past due (a later invoice failed) when it is sent again.
        $this->stripeHasSubscription('sub_1', ['active', 'past_due']);
        $this->send('invoice.paid', $this->paidInvoice());

        DB::table('user_plans')->where('stripe_subscription_id', 'sub_1')->update(['status' => 'past_due']);

        $this->send('invoice.paid', $this->paidInvoice());

        $this->assertSame('past_due', $this->rowOf('sub_1')->status);
    }
}
