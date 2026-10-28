<?php

namespace Tests;

use App\Models\Plan;
use App\Models\User;
use App\Support\SafeHttp;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep the auxiliary outlier_db connection (in-memory SQLite in tests) alive for
     * the whole suite. RefreshDatabase reads this property; without outlier_db listed,
     * its :memory: tables are lost when the connection is recreated between test classes,
     * breaking any test that touches OutlierVideo/OutlierChannel. (null = default.)
     */
    protected $connectionsToTransact = [null, 'outlier_db'];

    /**
     * SafeHttp checks every host's DNS before fetching. Tests must never do real
     * lookups (fake hosts like cdn.example don't resolve), so every host reads as
     * one public address; IP-literal URLs (169.254.169.254) are still checked as-is.
     * Tests about resolution itself (SafeHttpTest) install their own resolver.
     */
    protected function setUp(): void
    {
        parent::setUp();
        SafeHttp::resolveUsing(fn (string $host) => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SafeHttp::resolveUsing(null);
        parent::tearDown();
    }

    /**
     * A plan row by its canonical PlanSeeder name, created on demand. There is
     * no PlanFactory on purpose: the five tier names are the product, so tests
     * ask for one of them rather than inventing plans.
     */
    protected function planNamed(string $name): Plan
    {
        return Plan::firstOrCreate(
            ['name' => $name],
            [
                'display_name' => ucfirst($name),
                'price' => 59,
                'currency' => 'USD',
                'is_active' => true,
                'stripe_price_id' => 'price_'.$name,
            ],
        );
    }

    /**
     * A user whose plan includes analytics (App\Support\AnalyticsAccess), for
     * the tests whose subject is an import or a snapshot rather than the tier
     * gate itself. The bare factory user has an open free window but no plan,
     * so it gets no analytics.
     */
    protected function analyticsUser(array $attributes = []): User
    {
        return User::factory()->withPlan('active', $this->planNamed('pro'))->create($attributes);
    }
}
