<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stripe is the source of truth for what a plan costs: plans:sync-stripe-prices
 * copies each linked price's amount and currency onto the plans table, which
 * is what /api/plans, Billing and the trial checkout display.
 */
class SyncStripePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic regardless of the local .env's STRIPE_PRICE_* values.
        config(['services.stripe.prices' => []]);
    }

    private function plan(string $name, float $price, ?string $priceId): Plan
    {
        return Plan::create([
            'name' => $name, 'display_name' => ucfirst($name), 'price' => $price, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => $priceId,
        ]);
    }

    public function test_copies_amount_and_currency_from_each_linked_stripe_price(): void
    {
        $starter = $this->plan('starter', 29.00, 'price_starter');
        $pro = $this->plan('pro', 69.00, 'price_pro');
        $free = $this->plan('free', 0.00, null);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('retrievePrice')->with('price_starter')->once()->andReturn(['amount' => 19.00, 'currency' => 'USD']);
            $mock->shouldReceive('retrievePrice')->with('price_pro')->once()->andReturn(['amount' => 59.00, 'currency' => 'EUR']);
        });

        $this->artisan('plans:sync-stripe-prices')
            ->expectsOutputToContain('starter')
            ->assertExitCode(0);

        $this->assertSame('19.00', (string) $starter->fresh()->price);
        $this->assertSame('59.00', (string) $pro->fresh()->price);
        $this->assertSame('EUR', $pro->fresh()->currency);
        $this->assertSame('0.00', (string) $free->fresh()->price);
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $starter = $this->plan('starter', 29.00, 'price_starter');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('retrievePrice')->with('price_starter')->once()->andReturn(['amount' => 19.00, 'currency' => 'USD']);
        });

        $this->artisan('plans:sync-stripe-prices --dry-run')
            ->expectsOutputToContain('19.00')
            ->assertExitCode(0);

        $this->assertSame('29.00', (string) $starter->fresh()->price);
    }

    public function test_one_failing_price_does_not_stop_the_others_and_fails_the_run(): void
    {
        $starter = $this->plan('starter', 29.00, 'price_starter');
        $pro = $this->plan('pro', 69.00, 'price_pro');

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('retrievePrice')->with('price_starter')->once()->andThrow(new \RuntimeException('No such price'));
            $mock->shouldReceive('retrievePrice')->with('price_pro')->once()->andReturn(['amount' => 59.00, 'currency' => 'USD']);
        });

        $this->artisan('plans:sync-stripe-prices')
            ->expectsOutputToContain('No such price')
            ->assertExitCode(1);

        $this->assertSame('29.00', (string) $starter->fresh()->price);
        $this->assertSame('59.00', (string) $pro->fresh()->price);
    }

    public function test_reseeding_keeps_the_stripe_synced_price(): void
    {
        $this->plan('starter', 19.00, 'price_starter');
        config(['services.stripe.prices.starter' => 'price_starter']);

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $starter = Plan::where('name', 'starter')->first();
        $this->assertSame('19.00', (string) $starter->price, 'the seeder must not overwrite a Stripe-linked price');
        $this->assertSame(5, $starter->max_channels, 'limits are still re-tuned by the seeder');
        $this->assertSame('79.00', (string) Plan::where('name', 'agency')->first()->price, 'new plans get the seeder default');
    }

    public function test_a_new_price_id_in_config_replaces_the_stored_one(): void
    {
        // The .env changed to a new Stripe price but the seeder was never re-run:
        // the configured id wins, and the amount comes from the new price.
        $starter = $this->plan('starter', 29.00, 'price_old');
        config(['services.stripe.prices.starter' => 'price_new']);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('retrievePrice')->with('price_new')->once()->andReturn(['amount' => 19.00, 'currency' => 'USD']);
            $mock->shouldNotReceive('retrievePrice')->with('price_old');
        });

        $this->artisan('plans:sync-stripe-prices')
            ->expectsOutputToContain('price_old → price_new')
            ->assertExitCode(0);

        $this->assertSame('price_new', $starter->fresh()->stripe_price_id);
        $this->assertSame('19.00', (string) $starter->fresh()->price);
    }

    public function test_a_plan_with_a_configured_price_but_no_stored_id_is_linked(): void
    {
        $pro = $this->plan('pro', 59.00, null);
        config(['services.stripe.prices.pro' => 'price_pro']);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('retrievePrice')->with('price_pro')->once()->andReturn(['amount' => 59.00, 'currency' => 'USD']);
        });

        $this->artisan('plans:sync-stripe-prices')->assertExitCode(0);

        $this->assertSame('price_pro', $pro->fresh()->stripe_price_id);
    }

    public function test_an_equal_amount_is_reported_unchanged_whatever_its_php_type(): void
    {
        $this->plan('starter', 29.00, 'price_starter');

        $this->mock(StripeService::class, function ($mock) {
            // unit_amount / 100 is an int in PHP when it divides evenly.
            $mock->shouldReceive('retrievePrice')->with('price_starter')->once()->andReturn(['amount' => 29, 'currency' => 'USD']);
        });

        $this->artisan('plans:sync-stripe-prices')
            ->expectsOutputToContain('unchanged')
            ->doesntExpectOutputToContain('updated')
            ->assertExitCode(0);
    }
}
