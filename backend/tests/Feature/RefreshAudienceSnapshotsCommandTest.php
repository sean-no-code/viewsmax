<?php

namespace Tests\Feature;

use App\Models\AudienceSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * audience:refresh snapshots each connected account's follower/subscriber count
 * once per day (one row per account per day) using the platform provider's
 * fetchFollowerCount. Accounts where the platform can't supply a count (null)
 * are skipped, not zeroed, and the run still succeeds.
 */
class RefreshAudienceSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user, string $platform, string $status = SocialAccount::STATUS_CONNECTED): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-1',
            'name' => ucfirst($platform),
            'access_token' => 'tok',
            'status' => $status,
        ]);
    }

    /** Bind a SocialProviderManager whose providers return the given per-platform counts. */
    private function fakeManager(array $followersByPlatform): void
    {
        $provider = \Mockery::mock(\App\Services\Social\Contracts\SocialProviderInterface::class);
        $provider->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($a) => $a);
        $provider->shouldReceive('fetchFollowerCount')
            ->andReturnUsing(fn ($account) => $followersByPlatform[$account->platform] ?? null);

        $manager = \Mockery::mock(SocialProviderManager::class);
        $manager->shouldReceive('supports')->andReturn(true);
        $manager->shouldReceive('for')->andReturn($provider);

        $this->app->instance(SocialProviderManager::class, $manager);
    }

    public function test_snapshots_follower_count_for_each_connected_account(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'x');
        $this->account($user, 'youtube');
        $this->fakeManager(['x' => 1000, 'youtube' => 2500]);

        $this->artisan('audience:refresh')->assertExitCode(0);

        $this->assertSame(2, AudienceSnapshot::count());
        $x = AudienceSnapshot::whereHas('socialAccount', fn ($q) => $q->where('platform', 'x'))->first();
        $this->assertSame(1000, $x->follower_count);
        $this->assertSame(now()->toDateString(), $x->snapshot_date);
    }

    public function test_skips_accounts_where_follower_count_is_unsupported(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'x');
        $this->account($user, 'linkedin');
        $this->fakeManager(['x' => 500]); // linkedin → null

        $this->artisan('audience:refresh')->assertExitCode(0);

        $this->assertSame(1, AudienceSnapshot::count());
    }

    public function test_upserts_one_row_per_account_per_day(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'x');

        $this->fakeManager(['x' => 1000]);
        $this->artisan('audience:refresh')->assertExitCode(0);

        $this->fakeManager(['x' => 1200]);
        $this->artisan('audience:refresh')->assertExitCode(0);

        $this->assertSame(1, AudienceSnapshot::count());
        $this->assertSame(1200, AudienceSnapshot::first()->follower_count);
    }

    public function test_ignores_disconnected_accounts(): void
    {
        $user = User::factory()->create();
        $this->account($user, 'x', SocialAccount::STATUS_NEEDS_REAUTH);
        $this->fakeManager(['x' => 1000]);

        $this->artisan('audience:refresh')->assertExitCode(0);

        $this->assertSame(0, AudienceSnapshot::count());
    }

    public function test_succeeds_with_no_accounts(): void
    {
        $this->fakeManager([]);
        $this->artisan('audience:refresh')->assertExitCode(0);
    }
}
