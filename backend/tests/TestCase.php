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
     * Website actions are free in tests (config/credits.php `web` all 0), so a
     * test about something else doesn't need a funded user to save an offer
     * or connect an account. A test about website credits sets this to true to
     * use the real prices.
     */
    protected bool $chargeWebActions = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->chargeWebActions) {
            config(['credits.web' => ['read_default' => 0, 'write_default' => 0, 'tools' => []]]);
        }
    }

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

    /**
     * A user who can afford the actions that cost credits: MCP tools, and the
     * website actions priced like them (ChargeWebAction: publishing posts,
     * uploads, outlier search/fetch/breakdown/channel add). For tests about
     * something else that happen to go through one of those actions.
     */
    protected function fundedUser(array $attributes = [], int $credits = 10_000): User
    {
        $user = User::factory()->create($attributes);
        $this->fundCredits($user, $credits);

        return $user;
    }
}
