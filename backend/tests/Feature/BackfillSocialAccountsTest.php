<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The backfill migration copies existing single-account TikTok/YouTube
 * `connections` into the multi-account `social_accounts` store so nobody has to
 * reconnect when publishing moves onto that store.
 */
class BackfillSocialAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_07_21_110000_backfill_tiktok_youtube_social_accounts.php');
        $migration->up();
    }

    public function test_it_copies_tiktok_and_youtube_connections_into_social_accounts(): void
    {
        $user = User::factory()->create();
        Connection::create([
            'user_id' => $user->id, 'provider' => 'tiktok', 'account_name' => 'My TikTok',
            'account_id' => 'tt-open-1', 'access_token' => 'tt-token', 'refresh_token' => 'tt-refresh',
            'token_expires_at' => now()->addDay(),
        ]);
        Connection::create([
            'user_id' => $user->id, 'provider' => 'youtube', 'account_name' => 'My Channel',
            'account_id' => 'yt-chan-1', 'access_token' => 'yt-token',
        ]);

        $this->runBackfill();

        $tt = SocialAccount::where('platform', 'tiktok')->where('platform_account_id', 'tt-open-1')->first();
        $this->assertNotNull($tt);
        $this->assertSame($user->id, $tt->user_id);
        $this->assertSame('My TikTok', $tt->name);
        $this->assertSame('tt-token', $tt->access_token); // encrypted cast round-trips
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $tt->status);

        $this->assertDatabaseHas('social_accounts', [
            'platform' => 'youtube', 'platform_account_id' => 'yt-chan-1', 'user_id' => $user->id,
        ]);
    }

    public function test_it_is_idempotent_and_ignores_other_providers(): void
    {
        $user = User::factory()->create();
        Connection::create([
            'user_id' => $user->id, 'provider' => 'tiktok', 'account_name' => 'My TikTok',
            'account_id' => 'tt-open-1', 'access_token' => 'tt-token',
        ]);
        // Instagram lives in social_accounts already — the legacy IG connection
        // (if any) must NOT be duplicated by this TikTok/YouTube-only backfill.
        Connection::create([
            'user_id' => $user->id, 'provider' => 'instagram', 'account_name' => 'IG',
            'account_id' => 'ig-1', 'access_token' => 'ig-token',
        ]);

        $this->runBackfill();
        $this->runBackfill(); // second run must not create duplicates

        $this->assertSame(1, SocialAccount::where('platform', 'tiktok')->count());
        $this->assertSame(0, SocialAccount::where('platform', 'instagram')->count());
    }
}
