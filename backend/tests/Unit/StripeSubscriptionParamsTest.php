<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The parameters StripeService sends to Subscription::create. The free window
 * is the trial: a card added while it is open starts a trialing subscription
 * that charges the $1 activation fee today (a one-time Stripe price) and the
 * plan price when the window closes. A card added after the window pays the
 * plan price today and no fee. Either way a declined card fails the call.
 */
class StripeSubscriptionParamsTest extends TestCase
{
    use RefreshDatabase;

    private function params(array $trial, ?string $referral = null): array
    {
        $user = User::factory()->create();

        return app(StripeService::class)->subscriptionParams($user, 'cus_123', 'price_plan', $trial, $referral);
    }

    public function test_trial_during_the_window_adds_the_activation_fee(): void
    {
        config(['services.stripe.activation_fee_price_id' => 'price_fee']);

        $params = $this->params(['trial_end' => 1_800_000_000]);

        $this->assertSame('cus_123', $params['customer']);
        $this->assertSame([['price' => 'price_plan']], $params['items']);
        $this->assertSame(1_800_000_000, $params['trial_end']);
        $this->assertSame([['price' => 'price_fee']], $params['add_invoice_items']);
        $this->assertSame('error_if_incomplete', $params['payment_behavior']);
    }

    public function test_no_fee_line_when_no_fee_price_is_configured(): void
    {
        config(['services.stripe.activation_fee_price_id' => null]);

        $params = $this->params(['trial_end' => 1_800_000_000]);

        $this->assertArrayNotHasKey('add_invoice_items', $params);
        $this->assertSame(1_800_000_000, $params['trial_end']);
    }

    public function test_charging_today_has_no_trial_and_no_fee(): void
    {
        config(['services.stripe.activation_fee_price_id' => 'price_fee']);

        $params = $this->params([]);

        $this->assertArrayNotHasKey('trial_end', $params);
        $this->assertArrayNotHasKey('add_invoice_items', $params);
        $this->assertSame('error_if_incomplete', $params['payment_behavior']);
    }

    public function test_metadata_carries_the_user_and_referral(): void
    {
        $params = $this->params([], 'ref_abc');

        $this->assertSame('ref_abc', $params['metadata']['referral']);
        $this->assertArrayHasKey('user_id', $params['metadata']);
        $this->assertArrayNotHasKey('referral', $this->params([])['metadata']);
    }
}
