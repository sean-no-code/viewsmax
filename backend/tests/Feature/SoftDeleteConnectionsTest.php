<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OAuthConnectionService;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Disconnecting an account (YouTube, TikTok, X, ...) no longer deletes its row.
 * The row is soft-deleted: hidden everywhere in the app, but kept with the
 * platform account id so we know the account was connected before (needed to
 * stop one channel being used to sign up again and again). The access and
 * refresh tokens are cleared on disconnect, so we stop holding access to the
 * account. Reconnecting the same account brings the same row back.
 */
class SoftDeleteConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function callTool(User $user, string $tool, array $arguments = []): TestResponse
    {
        $this->fundCredits($user, 1_000);
        $key = $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('mobile-app')->plainTextToken])
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');

        return $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
    }

    private function socialAccount(User $user, string $platform = 'x', string $accountId = 'x-1'): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'platform_account_id' => $accountId,
            'name' => 'My account',
            'status' => SocialAccount::STATUS_CONNECTED,
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
        ]);
    }

    public function test_disconnecting_a_social_account_keeps_a_hidden_copy_without_tokens(): void
    {
        $user = User::factory()->create();
        $account = $this->socialAccount($user);
        $headers = $this->authHeaders($user);

        $this->deleteJson("/api/social/accounts/{$account->id}", [], $headers)->assertOk();

        $this->assertSoftDeleted('social_accounts', ['id' => $account->id]);
        $kept = SocialAccount::withTrashed()->find($account->id);
        $this->assertSame('x-1', $kept->platform_account_id);
        $this->assertNull($kept->access_token);
        $this->assertNull($kept->refresh_token);

        $this->getJson('/api/social/accounts', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_disconnecting_a_connection_keeps_a_hidden_copy_without_tokens(): void
    {
        $user = User::factory()->create();
        $connection = $user->connections()->create([
            'provider' => 'tiktok', 'account_name' => 'TT', 'account_id' => 'open1',
            'access_token' => 'old-access', 'refresh_token' => 'old-refresh',
        ]);
        $headers = $this->authHeaders($user);

        $this->deleteJson("/api/connections/{$connection->id}", [], $headers)->assertOk();

        $this->assertSoftDeleted('connections', ['id' => $connection->id]);
        $kept = Connection::withTrashed()->find($connection->id);
        $this->assertSame('open1', $kept->account_id);
        $this->assertSame('', $kept->access_token);
        $this->assertNull($kept->refresh_token);

        $this->getJson('/api/connections', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, $user->fresh()->apiPayload()['connections_count']);
    }

    public function test_disconnecting_a_youtube_channel_keeps_a_hidden_copy_without_tokens(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'user_id' => $user->id, 'youtube_channel_id' => 'UC123', 'channel_name' => 'My Channel',
            'youtube_access_token' => 'old-access', 'youtube_refresh_token' => 'old-refresh',
        ]);

        $this->deleteJson("/api/channels/{$channel->id}", [], $this->authHeaders($user))->assertOk();

        $this->assertSoftDeleted('channels', ['id' => $channel->id]);
        $kept = Channel::withTrashed()->find($channel->id);
        $this->assertSame('UC123', $kept->youtube_channel_id);
        $this->assertNull($kept->youtube_access_token);
        $this->assertNull($kept->youtube_refresh_token);
        $this->assertSame(0, $user->channels()->count());
    }

    public function test_disconnecting_removes_brand_links_and_boost_settings_as_before(): void
    {
        // A hard delete removed these through FK cascades; the soft delete must too.
        $user = User::factory()->create();
        $account = $this->socialAccount($user);
        $connection = $user->connections()->create([
            'provider' => 'tiktok', 'account_name' => 'TT', 'account_id' => 'open1', 'access_token' => 't',
        ]);
        $brand = $user->brands()->create(['name' => 'Acme']);
        $brand->socialAccounts()->attach($account->id);
        DB::table('brand_accounts')->insert(['brand_id' => $brand->id, 'connection_id' => $connection->id, 'created_at' => now(), 'updated_at' => now()]);
        \App\Models\BoostSetting::create([
            'user_id' => $user->id, 'social_account_id' => $account->id, 'feature' => 'auto_repost', 'enabled' => true,
        ]);
        $headers = $this->authHeaders($user);

        $this->deleteJson("/api/social/accounts/{$account->id}", [], $headers)->assertOk();
        $this->deleteJson("/api/connections/{$connection->id}", [], $headers)->assertOk();

        $this->assertDatabaseMissing('brand_accounts', ['social_account_id' => $account->id]);
        $this->assertDatabaseMissing('brand_accounts', ['connection_id' => $connection->id]);
        $this->assertDatabaseMissing('boost_settings', ['social_account_id' => $account->id]);
        $this->getJson('/api/boosts/settings', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_disconnecting_wipes_the_metadata_that_can_hold_page_tokens(): void
    {
        $user = User::factory()->create();
        $account = $this->socialAccount($user, 'facebook', 'page-1');
        $account->forceFill(['metadata' => ['page_access_token' => 'secret-page-token']])->save();

        $this->deleteJson("/api/social/accounts/{$account->id}", [], $this->authHeaders($user))->assertOk();

        $this->assertNull(SocialAccount::withTrashed()->find($account->id)->metadata);
    }

    public function test_a_disconnected_channels_videos_are_kept_but_not_listed(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'youtube_channel_id' => 'UC123', 'channel_name' => 'My Channel']);
        $video = \App\Models\Video::forceCreate([
            'channel_id' => $channel->id, 'youtube_video_id' => 'vid1',
            'title' => 'My video', 'description' => 'd',
        ]);
        $headers = $this->authHeaders($user);
        $this->getJson('/api/videos', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/channels/{$channel->id}", [], $headers)->assertOk();

        $this->getJson('/api/videos', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull(\App\Models\Video::find($video->id));
    }

    public function test_a_disconnected_account_is_left_out_of_the_ai_tools_and_audience_page(): void
    {
        $user = User::factory()->create();
        $kept = $this->socialAccount($user, 'x', 'x-1');
        $gone = $this->socialAccount($user, 'tiktok', 'tt-1');
        $gone->delete();

        $listed = json_decode($this->callTool($user, 'list_connected_accounts')->assertOk()->json('result.content.0.text'), true);
        $this->assertSame(['x'], array_column($listed['accounts'], 'platform'));

        $platforms = $this->getJson('/api/analytics/audience', $this->authHeaders($user))->assertOk()->json('data.platforms');
        $this->assertCount(1, $platforms);
        $this->assertStringContainsString((string) $kept->id, json_encode($platforms));
    }

    public function test_reconnecting_youtube_through_the_older_path_brings_all_three_rows_back(): void
    {
        config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'secret']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC123', 'snippet' => ['title' => 'My Channel'], 'statistics' => []]]]),
            '*googleapis.com*' => Http::response(['items' => [], 'rows' => []]),
        ]);
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);
        $connect = fn () => $this->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/cb'], $headers)->assertOk();

        $connect();
        $ids = [
            Channel::where('user_id', $user->id)->value('id'),
            Connection::where('user_id', $user->id)->value('id'),
            SocialAccount::where('user_id', $user->id)->value('id'),
        ];
        Channel::where('user_id', $user->id)->get()->each->delete();
        Connection::where('user_id', $user->id)->get()->each->delete();
        SocialAccount::where('user_id', $user->id)->get()->each->delete();

        $connect();

        $this->assertSame($ids, [
            Channel::where('user_id', $user->id)->value('id'),
            Connection::where('user_id', $user->id)->value('id'),
            SocialAccount::where('user_id', $user->id)->value('id'),
        ]);
        $this->assertSame(1, Channel::withTrashed()->where('user_id', $user->id)->count());
        $this->assertSame(1, Connection::withTrashed()->where('user_id', $user->id)->count());
        $this->assertSame(1, SocialAccount::withTrashed()->where('user_id', $user->id)->count());
    }

    public function test_the_ai_disconnect_tool_keeps_a_hidden_copy_without_tokens(): void
    {
        $user = User::factory()->create();
        $account = $this->socialAccount($user);

        $this->callTool($user, 'disconnect_account', ['platform' => 'x'])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $this->assertSoftDeleted('social_accounts', ['id' => $account->id]);
        $this->assertNull(SocialAccount::withTrashed()->find($account->id)->access_token);
    }

    public function test_reconnecting_tiktok_brings_the_same_rows_back(): void
    {
        $user = User::factory()->create();
        $connection = $user->connections()->create([
            'provider' => 'tiktok', 'account_name' => 'TT', 'account_id' => 'open1', 'access_token' => 'old',
        ]);
        $account = $this->socialAccount($user, 'tiktok', 'open1');
        $connection->delete();
        $account->delete();

        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/*' => Http::response([
                'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 86400, 'open_id' => 'open1',
            ]),
            'open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => ['user' => ['open_id' => 'open1', 'display_name' => 'TT', 'avatar_url' => null]],
            ]),
        ]);

        app(OAuthConnectionService::class)->exchange($user, 'tiktok', 'code', 'https://app.test/callback');

        $this->assertSame(1, Connection::withTrashed()->where('user_id', $user->id)->count());
        $this->assertSame(1, SocialAccount::withTrashed()->where('user_id', $user->id)->count());
        $this->assertFalse($connection->fresh()->trashed());
        $this->assertSame('new-access', $connection->fresh()->access_token);
        $this->assertFalse($account->fresh()->trashed());
        $this->assertSame('new-access', $account->fresh()->access_token);
    }

    public function test_reconnecting_through_a_social_provider_brings_the_same_row_back(): void
    {
        $user = User::factory()->create();
        $account = $this->socialAccount($user, 'x', 'x-1');
        $account->delete();

        $provider = app(SocialProviderManager::class)->for('x');
        $store = new ReflectionMethod($provider, 'storeAccount');
        $store->invoke($provider, $user, ['platform_account_id' => 'x-1', 'name' => 'My X', 'access_token' => 'new-access']);

        $this->assertSame(1, SocialAccount::withTrashed()->where('user_id', $user->id)->count());
        $this->assertFalse($account->fresh()->trashed());
        $this->assertSame('new-access', $account->fresh()->access_token);
    }

    public function test_reconnecting_a_youtube_channel_brings_the_same_row_back(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'youtube_channel_id' => 'UC123', 'channel_name' => 'Old']);
        $channel->delete();

        $again = Channel::reconnect(
            ['user_id' => $user->id, 'youtube_channel_id' => 'UC123'],
            ['channel_name' => 'My Channel', 'youtube_access_token' => 'new-access'],
        );

        $this->assertSame($channel->id, $again->id);
        $this->assertFalse($again->trashed());
        $this->assertSame(1, Channel::withTrashed()->where('user_id', $user->id)->count());
    }
}
