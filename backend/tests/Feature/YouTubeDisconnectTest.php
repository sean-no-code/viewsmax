<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\GoogleGrantRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Disconnecting YouTube revokes the Google grant (so ViewsMax leaves the
 * user's Google third-party access list) and clears every store the legacy
 * connect flow wrote it into, whichever page the disconnect came from.
 */
class YouTubeDisconnectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** The one grant the legacy connect mirrors into four stores. */
    private function connectYouTube(string $channelId = 'UC1', string $refresh = 'refresh-1', string $access = 'access-1'): array
    {
        $this->user->forceFill(['youtube_access_token' => $access, 'youtube_refresh_token' => $refresh, 'youtube_token_expires_at' => now()->addHour()])->save();
        $channel = Channel::create([
            'user_id' => $this->user->id, 'youtube_channel_id' => $channelId, 'channel_name' => 'Chan '.$channelId,
            'youtube_access_token' => $access, 'youtube_refresh_token' => $refresh, 'youtube_token_expires_at' => now()->addHour(),
        ]);
        // One legacy connection per provider (unique user_id+provider): a second login overwrites it, as the connect flow does.
        $connection = Connection::updateOrCreate(['user_id' => $this->user->id, 'provider' => 'youtube'], [
            'account_name' => 'Chan', 'account_id' => $channelId,
            'access_token' => $access, 'refresh_token' => $refresh, 'token_expires_at' => now()->addHour(),
        ]);
        $account = SocialAccount::create([
            'user_id' => $this->user->id, 'platform' => 'youtube', 'platform_account_id' => $channelId, 'name' => 'Chan',
            'access_token' => $access, 'refresh_token' => $refresh, 'token_expires_at' => now()->addHour(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        return compact('channel', 'connection', 'account');
    }

    private function revokeCalls(): array
    {
        return Http::recorded()->filter(fn ($pair) => $pair[0]->url() === GoogleGrantRevoker::REVOKE_URL)->values()->all();
    }

    public function test_disconnecting_the_youtube_social_account_revokes_the_grant_once_and_clears_every_store(): void
    {
        Http::fake([GoogleGrantRevoker::REVOKE_URL => Http::response('', 200)]);
        ['account' => $account] = $this->connectYouTube();

        $this->withHeaders($this->auth())->deleteJson("/api/social/accounts/{$account->id}")->assertOk();

        $calls = $this->revokeCalls();
        $this->assertCount(1, $calls, 'the shared grant is revoked once, not once per store');
        $this->assertSame('refresh-1', $calls[0][0]['token'], 'the refresh token is revoked (it kills the access token too)');
        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(0, Channel::count());
        $this->assertSame(0, Connection::count());
        $this->user->refresh();
        $this->assertNull($this->user->youtube_access_token);
        $this->assertNull($this->user->youtube_refresh_token);
    }

    public function test_disconnecting_another_platform_does_not_touch_google(): void
    {
        Http::fake();
        $x = SocialAccount::create(['user_id' => $this->user->id, 'platform' => 'x', 'platform_account_id' => 'x1', 'name' => 'X', 'access_token' => 't', 'status' => 'connected']);
        $this->connectYouTube();

        $this->withHeaders($this->auth())->deleteJson("/api/social/accounts/{$x->id}")->assertOk();

        Http::assertNothingSent();
        $this->assertSame(1, SocialAccount::where('platform', 'youtube')->count());
    }

    public function test_removing_a_channel_from_analytics_revokes_only_that_channels_grant(): void
    {
        Http::fake([GoogleGrantRevoker::REVOKE_URL => Http::response('', 200)]);
        ['channel' => $one] = $this->connectYouTube('UC1', 'refresh-1', 'access-1');
        $this->connectYouTube('UC2', 'refresh-2', 'access-2'); // a second Google login; the user row now holds this grant

        $this->withHeaders($this->auth())->deleteJson("/api/channels/{$one->id}")->assertOk();

        $this->assertSame(['refresh-1'], array_map(fn ($pair) => $pair[0]['token'], $this->revokeCalls()));
        $this->assertSame(['UC2'], Channel::pluck('youtube_channel_id')->all());
        $this->assertSame(['UC2'], SocialAccount::pluck('platform_account_id')->all());
        $this->assertSame(['UC2'], Connection::pluck('account_id')->all(), 'the legacy connection points at the other channel and stays');
        $this->assertSame('refresh-2', $this->user->fresh()->youtube_refresh_token, 'another channel still uses the user-level tokens');
    }

    public function test_removing_the_legacy_connection_revokes_too(): void
    {
        Http::fake([GoogleGrantRevoker::REVOKE_URL => Http::response('', 200)]);
        ['connection' => $connection] = $this->connectYouTube();

        $this->withHeaders($this->auth())->deleteJson("/api/connections/{$connection->id}")->assertOk();

        $this->assertCount(1, $this->revokeCalls());
        $this->assertSame(0, Connection::count());
        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(0, Channel::count());
    }

    public function test_a_grant_google_already_forgot_still_disconnects(): void
    {
        // 400 invalid_token: expired or revoked by the user at myaccount.google.com.
        Http::fake([GoogleGrantRevoker::REVOKE_URL => Http::response(['error' => 'invalid_token'], 400)]);
        ['account' => $account] = $this->connectYouTube();

        $this->withHeaders($this->auth())->deleteJson("/api/social/accounts/{$account->id}")->assertOk();

        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(0, Channel::count());
    }

    public function test_a_google_outage_never_blocks_the_disconnect(): void
    {
        Http::fake([GoogleGrantRevoker::REVOKE_URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);
        ['account' => $account] = $this->connectYouTube();

        $this->withHeaders($this->auth())->deleteJson("/api/social/accounts/{$account->id}")->assertOk();

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_delete_my_data_removes_all_youtube_data_and_revokes_every_grant(): void
    {
        Http::fake([GoogleGrantRevoker::REVOKE_URL => Http::response('', 200)]);
        $this->connectYouTube('UC1', 'refresh-1', 'access-1');
        $this->connectYouTube('UC2', 'refresh-2', 'access-2');
        $x = SocialAccount::create(['user_id' => $this->user->id, 'platform' => 'x', 'platform_account_id' => 'x1', 'name' => 'X', 'access_token' => 't', 'status' => 'connected']);

        $response = $this->withHeaders($this->auth())->deleteJson('/api/user-data');

        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.channels', 2)
            ->assertJsonPath('data.social_accounts', 2)
            ->assertJsonPath('data.revoked', 2);
        $this->assertEqualsCanonicalizing(['refresh-1', 'refresh-2'], array_map(fn ($pair) => $pair[0]['token'], $this->revokeCalls()));
        $this->assertSame(0, Channel::count());
        $this->assertSame(0, Connection::count());
        $this->assertSame([$x->id], SocialAccount::pluck('id')->all(), 'other platforms are untouched');
        $this->assertNull($this->user->fresh()->youtube_refresh_token);
    }

    public function test_delete_my_data_requires_authentication(): void
    {
        $this->deleteJson('/api/user-data')->assertStatus(401);
    }
}
