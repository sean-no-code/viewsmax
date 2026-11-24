<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The parameters StripeService sends to Subscription::create. The free window
 * is the trial: a card added while it is open starts a trialing subscription
 * that charges nothing today and the plan price when the window closes (the
 * $1 activation fee was dropped on 2026-10-25). A card added after the window
 * pays the plan price today. Either way a declined card fails the call.
 */
class StripeSubscriptionParamsTest extends TestCase
{
    use RefreshDatabase;

    private function params(array $trial, ?string $referral = null): array
    {
        $user = User::factory()->create();

        return app(StripeService::class)->subscriptionParams($user, 'cus_123', 'price_plan', $trial, $referral);
    }

    public function test_trial_during_the_window_charges_nothing_today(): void
    {
        $params = $this->params(['trial_end' => 1_800_000_000]);

        $this->assertSame('cus_123', $params['customer']);
        $this->assertSame([['price' => 'price_plan']], $params['items']);
        $this->assertSame(1_800_000_000, $params['trial_end']);
        // No one-time line on the first invoice: the trial is $0.
        $this->assertArrayNotHasKey('add_invoice_items', $params);
        $this->assertSame('error_if_incomplete', $params['payment_behavior']);
    }

    public function test_charging_today_has_no_trial(): void
    {
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
