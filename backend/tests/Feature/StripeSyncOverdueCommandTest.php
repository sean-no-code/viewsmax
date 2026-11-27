<?php

namespace Tests\Feature;

use App\Mail\InvoicePaidMail;
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
 * stripe:sync-overdue looks at every subscription row that is past its date
 * (a trial or renewal whose expires_at has passed) or past due, asks Stripe
 * what really happened, and brings the row in line: for when a webhook never
 * arrived. --dry-run lists what it would do and changes nothing.
 */
class StripeSyncOverdueCommandTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private int $periodEnd;

    /** @var array<string, array|\Throwable> what Stripe answers per subscription id */
    private array $subscriptions = [];

    /** @var array<string, array> */
    private array $invoices = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['app.frontend_url' => 'https://app.example.test']);

        $this->periodEnd = now()->addMonth()->startOfSecond()->timestamp;
        $this->plan = Plan::create([
            'name' => 'creator', 'display_name' => 'Creator', 'price' => 79.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_creator',
        ]);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('getSubscription')->andReturnUsing(function (string $id) {
                $answer = $this->subscriptions[$id] ?? new \Exception("No such subscription: {$id}");
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            });
            $mock->shouldReceive('getInvoice')->andReturnUsing(fn (string $id) => $this->invoices[$id]);
        });
    }

    private function subscriber(string $subscriptionId, string $status, $expiresAt, string $email = 'dana@example.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->plans()->attach($this->plan->id, [
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => 'price_creator',
            'status' => $status,
            'starts_at' => now()->subDays(8),
            'expires_at' => $expiresAt,
        ]);

        return $user;
    }

    private function invoice(string $id, string $subscriptionId, string $status, array $extra = []): array
    {
        return $this->invoices[$id] = array_merge([
            'id' => $id, 'object' => 'invoice', 'status' => $status, 'customer' => 'cus_1',
            'parent' => ['subscription_details' => ['subscription' => $subscriptionId]],
            'amount_paid' => $status === 'paid' ? 7900 : 0,
            'amount_due' => 7900,
            'currency' => 'usd',
            'billing_reason' => 'subscription_cycle',
            'hosted_invoice_url' => "https://invoice.stripe.test/i/{$id}",
            'status_transitions' => ['paid_at' => $status === 'paid' ? now()->subDay()->timestamp : null],
        ], $extra);
    }

    private function stripeHas(string $subscriptionId, string $status, ?array $latestInvoice, array $extra = []): void
    {
        $this->subscriptions[$subscriptionId] = array_merge([
            'id' => $subscriptionId, 'status' => $status, 'customer' => 'cus_1', 'metadata' => [],
            'cancel_at_period_end' => false,
            'items' => ['data' => [['price' => ['id' => 'price_creator'], 'current_period_end' => $this->periodEnd]]],
            'latest_invoice' => $latestInvoice,
        ], $extra);
    }

    private function rowOf(string $subscriptionId): object
    {
        return DB::table('user_plans')->where('stripe_subscription_id', $subscriptionId)->sole();
    }

    // --- The dry run ----------------------------------------------------------

    public function test_a_dry_run_says_who_it_would_process_and_changes_nothing(): void
    {
        $user = $this->subscriber('sub_1', 'trialing', now()->subDay());
        $this->stripeHas('sub_1', 'active', $this->invoice('in_1', 'sub_1', 'paid'));

        $this->artisan('stripe:sync-overdue', ['--dry-run' => true])
            ->expectsOutputToContain('dana@example.com')
            ->expectsOutputToContain('apply paid invoice in_1')
            ->expectsOutputToContain('Dry run: nothing was changed')
            ->assertSuccessful();

        $this->assertSame('trialing', $this->rowOf('sub_1')->status);
        $this->assertSame(0, $user->fresh()->balanceInt);
        Mail::assertNothingSent();
    }

    // --- Paid in Stripe -------------------------------------------------------

    public function test_a_trial_that_stripe_charged_is_made_active_with_its_credits_and_invoice_email(): void
    {
        $user = $this->subscriber('sub_1', 'trialing', now()->subDay());
        $this->stripeHas('sub_1', 'active', $this->invoice('in_1', 'sub_1', 'paid'));

        $this->artisan('stripe:sync-overdue')->assertSuccessful();

        $row = $this->rowOf('sub_1');
        $this->assertSame('active', $row->status);
        $this->assertSame($this->periodEnd, strtotime($row->expires_at));
        $this->assertSame((int) config('credits.subscription_credits.default'), $user->fresh()->balanceInt);
        Mail::assertSent(InvoicePaidMail::class, 1);
    }

    public function test_a_past_due_row_whose_invoice_was_paid_is_made_active(): void
    {
        $user = $this->subscriber('sub_1', 'past_due', now()->addWeeks(3));
        $this->stripeHas('sub_1', 'active', $this->invoice('in_1', 'sub_1', 'paid'));

        $this->artisan('stripe:sync-overdue')->assertSuccessful();

        $this->assertSame('active', $this->rowOf('sub_1')->status);
        $this->assertSame((int) config('credits.subscription_credits.default'), $user->fresh()->balanceInt);
    }

    public function test_a_payment_already_applied_only_puts_the_row_right(): void
    {
        $user = $this->subscriber('sub_1', 'past_due', now()->subDay());
        $this->stripeHas('sub_1', 'active', $this->invoice('in_1', 'sub_1', 'paid'));
        $user->deposit(1000, ['stripe_transaction_id' => 'in_1']);

        $this->artisan('stripe:sync-overdue')
            ->expectsOutputToContain('set to active')
            ->assertSuccessful();

        $row = $this->rowOf('sub_1');
        $this->assertSame('active', $row->status);
        $this->assertSame($this->periodEnd, strtotime($row->expires_at));
        $this->assertSame(1000, $user->fresh()->balanceInt, 'credits are granted once per invoice');
        Mail::assertNothingSent();
    }

    // --- Not paid in Stripe ---------------------------------------------------

    public function test_a_trial_stripe_has_not_charged_yet_is_left_a_trial(): void
    {
        // The hour after a trial ends: Stripe says active, the first invoice is still a draft.
        $trialEnd = now()->subMinutes(10)->startOfSecond();
        $user = $this->subscriber('sub_1', 'trialing', $trialEnd);
        $this->stripeHas('sub_1', 'active', $this->invoice('in_1', 'sub_1', 'draft'));

        $this->artisan('stripe:sync-overdue')
            ->expectsOutputToContain('Stripe has not charged invoice in_1 yet')
            ->assertSuccessful();

        $row = $this->rowOf('sub_1');
        $this->assertSame('trialing', $row->status);
        $this->assertSame($trialEnd->timestamp, strtotime($row->expires_at));
        $this->assertSame(0, $user->fresh()->balanceInt);
        Mail::assertNothingSent();
    }

    public function test_a_trial_whose_charge_failed_is_locked_and_the_customer_is_told(): void
    {
        $user = $this->subscriber('sub_1', 'trialing', now()->subDay());
        $this->stripeHas('sub_1', 'past_due', $this->invoice('in_1', 'sub_1', 'open'));

        $this->artisan('stripe:sync-overdue')->assertSuccessful();

        $this->assertSame('past_due', $this->rowOf('sub_1')->status);
        Mail::assertSent(PaymentFailedMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_a_row_already_past_due_that_stripe_is_still_retrying_is_left_alone(): void
    {
        $this->subscriber('sub_1', 'past_due', now()->subDay());
        $this->stripeHas('sub_1', 'past_due', $this->invoice('in_1', 'sub_1', 'open'));

        $this->artisan('stripe:sync-overdue')
            ->expectsOutputToContain('nothing to do')
            ->assertSuccessful();

        $this->assertSame('past_due', $this->rowOf('sub_1')->status);
        Mail::assertNothingSent();
    }

    public function test_a_subscription_stripe_has_ended_is_expired(): void
    {
        $user = $this->subscriber('sub_1', 'active', now()->subDay());
        $user->deposit(400);
        $this->stripeHas('sub_1', 'canceled', $this->invoice('in_1', 'sub_1', 'void'), ['ended_at' => now()->subDay()->timestamp]);

        $this->artisan('stripe:sync-overdue')->assertSuccessful();

        $row = $this->rowOf('sub_1');
        $this->assertSame('expired', $row->status);
        $this->assertNotNull($row->cancelled_at);
        $this->assertSame(0, $user->fresh()->balanceInt);
    }

    public function test_a_payment_made_after_the_subscription_ended_is_left_for_a_person(): void
    {
        $user = $this->subscriber('sub_1', 'past_due', now()->subDays(20));
        $this->stripeHas('sub_1', 'canceled', $this->invoice('in_1', 'sub_1', 'paid'), ['ended_at' => now()->subDays(3)->timestamp]);

        $this->artisan('stripe:sync-overdue')
            ->expectsOutputToContain('stripe:sync-invoice in_1')
            ->assertSuccessful();

        $this->assertSame('past_due', $this->rowOf('sub_1')->status);
        $this->assertSame(0, $user->fresh()->balanceInt);
    }

    // --- What it looks at -----------------------------------------------------

    public function test_rows_that_are_not_overdue_are_not_looked_at(): void
    {
        $this->subscriber('sub_live', 'active', now()->addWeek());
        $this->subscriber('sub_trial', 'trialing', now()->addDays(2), 'trial@example.com');
        $this->subscriber('sub_gone', 'expired', now()->subMonth(), 'gone@example.com');
        // No answers registered: asking Stripe about any of them would fail the run.

        $this->artisan('stripe:sync-overdue')
            ->expectsOutputToContain('Nothing is overdue')
            ->assertSuccessful();
    }

    public function test_a_subscription_stripe_cannot_find_is_reported_and_the_rest_still_run(): void
    {
        $this->subscriber('sub_missing', 'active', now()->subDays(2), 'missing@example.com');
        $this->subscriber('sub_1', 'trialing', now()->subDay());
        $this->stripeHas('sub_1', 'active', $this->invoice('in_1', 'sub_1', 'paid'));

        $this->artisan('stripe:sync-overdue')
            ->expectsOutputToContain('No such subscription: sub_missing')
            ->assertFailed();

        $this->assertSame('active', $this->rowOf('sub_missing')->status);
        $this->assertSame('active', $this->rowOf('sub_1')->status);
    }
}
