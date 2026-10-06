<?php

namespace Tests;

use App\Models\User;
use Bavix\Wallet\Services\BookkeeperServiceInterface;
use Bavix\Wallet\Services\RegulatorServiceInterface;
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
     * Give a test user a durable credit balance.
     *
     * Under RefreshDatabase the wallet package (bavix/laravel-wallet) never
     * reaches its top-level commit, so a plain deposit() stays staged in
     * memory and is wiped the moment ANY connection begins a transaction —
     * e.g. a firstOrCreate on outlier_db. Deposit for the ledger row, then
     * persist the resulting balance to the wallet row and the bookkeeper so
     * it survives that purge. Production never hits this: there the commit
     * runs and the row is updated.
     */
    protected function fundCredits(User $user, int $amount): void
    {
        $user->deposit($amount);

        $wallet = $user->wallet;
        $balance = $user->balanceInt;

        app(RegulatorServiceInterface::class)->forget($wallet);
        $wallet->forceFill(['balance' => $balance])->saveQuietly();
        app(BookkeeperServiceInterface::class)->sync($wallet, $balance);
    }
}
