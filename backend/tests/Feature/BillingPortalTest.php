<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Stripe Billing Portal is how a past_due (or any active) subscriber
 * self-serves a card update / pays the open invoice to recover. The endpoint
 * just mints a portal session URL for the SPA to redirect to; the actual
 * past_due -> active flip is driven by the invoice_payment.paid webhook.
 */
class BillingPortalTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    public function test_subscriber_gets_a_billing_portal_url(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_test123']);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('createBillingPortalSession')
                ->once()
                ->andReturn(\Stripe\BillingPortal\Session::constructFrom([
                    'url' => 'https://billing.stripe.com/session/test',
                ]));
        });

        $res = $this->postJson('/api/billing/stripe/portal', [], $this->authHeaders($user));

        $res->assertStatus(200);
        $this->assertSame('https://billing.stripe.com/session/test', $res->json('data.url'));
    }

    public function test_user_without_a_stripe_customer_cannot_open_the_portal(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => null]);

        // The service should never be hit when there's no customer to manage.
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('createBillingPortalSession');
        });

        $res = $this->postJson('/api/billing/stripe/portal', [], $this->authHeaders($user));

        $res->assertStatus(422);
    }

    public function test_portal_requires_authentication(): void
    {
        $this->postJson('/api/billing/stripe/portal')->assertStatus(401);
    }

    private function plan(string $name): \App\Models\Plan
    {
        return \App\Models\Plan::create([
            'name' => $name,
            'display_name' => ucfirst($name),
            'price' => 99.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'stripe_price_id' => 'price_' . $name,
        ]);
    }

    public function test_status_reports_past_due_so_the_ui_can_offer_recovery(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_pd']);
        $plan = $this->plan('pro');
        $user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_pd',
            'stripe_price_id' => $plan->stripe_price_id,
            'status' => 'past_due',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);

        $res = $this->getJson('/api/billing/status', $this->authHeaders($user));

        $res->assertStatus(200);
        $this->assertTrue($res->json('data.has_subscription'));
        $this->assertSame('past_due', $res->json('data.status'));
        $this->assertSame('Pro', $res->json('data.plan_display_name'));
    }

    public function test_status_reports_no_subscription_for_a_free_user(): void
    {
        $user = User::factory()->create();

        $res = $this->getJson('/api/billing/status', $this->authHeaders($user));

        $res->assertStatus(200);
        $this->assertFalse($res->json('data.has_subscription'));
    }
}
