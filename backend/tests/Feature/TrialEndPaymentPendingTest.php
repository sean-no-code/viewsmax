<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\StripeService;
use App\Support\PostAllowance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The hour between the end of a trial and its first charge. Stripe calls a
 * subscription `active` from the moment its trial ends (the free window,
 * promo_expires_at) and only charges the first invoice about an hour later.
 * Here `active` means paid: the trial post cap lifts on it and the Billing
 * page says so. So the row stays a trial, due at the end of the trial, until
 * that invoice settles: paid makes it active, a failed charge past_due.
 * Access carries on meanwhile, as it does for any trial.
 */
class TrialEndPaymentPendingTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Carbon $trialEnd;

    private Carbon $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['services.stripe.signature_check' => false, 'publishing.trial_posts' => 2]);

        $this->trialEnd = now()->subMinute()->startOfSecond();
        $this->periodEnd = $this->trialEnd->copy()->addMonth();
        $this->plan = Plan::create([
            'name' => 'creator', 'display_name' => 'Creator', 'price' => 79.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_creator',
        ]);
    }

    /** A signup whose free window has just closed, with a card on file: `$status` row, due at the end of the trial. */
    private function subscriber(string $status = 'trialing'): User
    {
        $user = User::factory()->create(['promo_expires_at' => $this->trialEnd]);
        $user->plans()->attach($this->plan->id, [
            'stripe_subscription_id' => 'sub_1',
            'stripe_price_id' => 'price_creator',
            'status' => $status,
            'starts_at' => $this->trialEnd->copy()->subDays(User::CARD_FREE_DAYS),
            'expires_at' => $this->trialEnd,
        ]);

        return $user;
    }

    private function row(): object
    {
        return DB::table('user_plans')->where('stripe_subscription_id', 'sub_1')->sole();
    }

    private function send(string $type, array $object): void
    {
        $this->postJson('/api/webhooks/stripe', [
            'id' => 'evt_1',
            'object' => 'event',
            'type' => $type,
            'created' => now()->timestamp,
            'data' => ['object' => $object],
        ])->assertOk();
    }

    /** The subscription as Stripe has it from the end of the trial: active, first period running, nothing charged. */
    private function activeSubscription(): array
    {
        return [
            'id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1', 'metadata' => [], 'cancel_at_period_end' => false,
            'items' => ['data' => [['price' => ['id' => 'price_creator'], 'current_period_end' => $this->periodEnd->timestamp]]],
        ];
    }

    private function stripeHasTheSubscription(): void
    {
        $this->mock(StripeService::class, fn ($mock) => $mock->shouldReceive('getSubscription')->with('sub_1')->andReturn($this->activeSubscription()));
    }

    public function test_the_end_of_the_trial_alone_does_not_make_the_plan_active(): void
    {
        $user = $this->subscriber();

        $this->send('customer.subscription.updated', $this->activeSubscription());

        $row = $this->row();
        $this->assertSame('trialing', $row->status, 'nothing has been charged yet');
        $this->assertTrue($this->trialEnd->equalTo($row->expires_at), 'still due at the end of the trial, so an overdue check finds it');
        $this->assertFalse($user->fresh()->accessExpired(), 'access carries on while Stripe charges');
        $this->assertSame(2, PostAllowance::cap($user->fresh()), 'the trial post cap stays until the payment');
    }

    public function test_the_first_payment_makes_the_plan_active(): void
    {
        $user = $this->subscriber();
        $this->stripeHasTheSubscription();
        $this->send('customer.subscription.updated', $this->activeSubscription());

        $this->send('invoice.paid', [
            'id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'subscription' => 'sub_1',
            'amount_paid' => 7900, 'currency' => 'usd', 'billing_reason' => 'subscription_cycle',
        ]);

        $row = $this->row();
        $this->assertSame('active', $row->status);
        $this->assertTrue($this->periodEnd->equalTo($row->expires_at));
        $this->assertNull(PostAllowance::cap($user->fresh()), 'paid: the trial post cap lifts');

        // The trial-end event arriving after the payment (Stripe does not
        // promise an order) changes nothing.
        $this->send('customer.subscription.updated', $this->activeSubscription());

        $row = $this->row();
        $this->assertSame('active', $row->status);
        $this->assertTrue($this->periodEnd->equalTo($row->expires_at));
    }

    public function test_a_failed_first_charge_locks_the_account(): void
    {
        $user = $this->subscriber();
        $this->send('customer.subscription.updated', $this->activeSubscription());

        $this->send('invoice.payment_failed', ['id' => 'in_1', 'subscription' => 'sub_1', 'amount_due' => 7900, 'currency' => 'usd']);

        $this->assertSame('past_due', $this->row()->status);
        $this->assertTrue($user->fresh()->accessExpired());
    }

    public function test_a_plan_change_in_that_hour_still_lands(): void
    {
        $agency = Plan::create([
            'name' => 'agency', 'display_name' => 'Agency', 'price' => 199.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_agency',
        ]);
        $this->subscriber();

        $subscription = $this->activeSubscription();
        $subscription['items']['data'][0]['price']['id'] = 'price_agency';
        $this->send('customer.subscription.updated', $subscription);

        $row = $this->row();
        $this->assertSame('price_agency', $row->stripe_price_id);
        $this->assertSame($agency->id, $row->plan_id);
        $this->assertSame('trialing', $row->status);
    }

    public function test_a_paid_plan_still_follows_stripe(): void
    {
        // A renewal: the row is already active, Stripe moves it to the next period.
        $this->subscriber('active');

        $this->send('customer.subscription.updated', $this->activeSubscription());

        $row = $this->row();
        $this->assertSame('active', $row->status);
        $this->assertTrue($this->periodEnd->equalTo($row->expires_at));
    }
}
