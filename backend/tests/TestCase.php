<?php

namespace Tests;

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
}
