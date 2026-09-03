<?php

namespace Tests;

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
}
