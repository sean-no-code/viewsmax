<?php

namespace Tests\Feature;

use App\Exceptions\AccountAlreadyConnectedException;
use App\Models\Channel;
use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use App\Services\YouTubeAnalyticsService;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A platform account (YouTube channel, TikTok, X, ...) belongs to the first
 * ViewsMax account that connected it. Another ViewsMax account can't connect
 * it, even after the first one disconnected it (the row is soft-deleted, not
 * gone): that stops someone signing up again and again with the same channel
 * to collect new free credits. The refusal tells them which account has it,
 * with the email masked, e.g. "se****@gmail.com".
 */
class BlockReusedAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $newcomer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'sean@gmail.com']);
        $this->newcomer = User::factory()->create(['email' => 'newbie@example.com']);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function fakeTikTok(string $openId = 'open1'): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/*' => Http::response(['access_token' => 'acc', 'expires_in' => 3600, 'open_id' => $openId]),
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => $openId, 'display_name' => 'TT']]]),
        ]);
    }

    private function connectTikTok(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/tiktok/exchange', [
            'code' => 'code', 'redirect_uri' => 'https://app.test/callback',
        ], $this->headers($user));
    }

    public function test_a_second_account_cannot_connect_a_disconnected_account(): void
    {
        $this->fakeTikTok();
        $this->connectTikTok($this->owner)->assertOk();
        $this->owner->connections()->first()->delete();
        SocialAccount::where('user_id', $this->owner->id)->get()->each->delete();

        $this->connectTikTok($this->newcomer)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already created an account with this TikTok account on email se****@gmail.com. Log in with that email to use it.');

        $this->assertSame(0, Connection::withTrashed()->where('user_id', $this->newcomer->id)->count());
        $this->assertSame(0, SocialAccount::withTrashed()->where('user_id', $this->newcomer->id)->count());
    }

    public function test_a_second_account_cannot_connect_an_account_that_is_still_connected(): void
    {
        $this->fakeTikTok();
        $this->connectTikTok($this->owner)->assertOk();

        $this->connectTikTok($this->newcomer)->assertStatus(422);
        $this->assertSame(0, Connection::withTrashed()->where('user_id', $this->newcomer->id)->count());
    }

    public function test_the_first_owner_can_reconnect_their_own_account(): void
    {
        $this->fakeTikTok();
        $this->connectTikTok($this->owner)->assertOk();
        $this->owner->connections()->first()->delete();

        $this->connectTikTok($this->owner)->assertOk();
        $this->assertSame(1, $this->owner->connections()->count());
    }

    public function test_a_different_platform_account_is_not_blocked(): void
    {
        $this->owner->connections()->create([
            'provider' => 'tiktok', 'account_name' => 'TT', 'account_id' => 'open1', 'access_token' => 't',
        ]);

        $this->fakeTikTok('open2');
        $this->connectTikTok($this->newcomer)->assertOk();
    }

    public function test_the_social_provider_path_is_blocked_too(): void
    {
        SocialAccount::create([
            'user_id' => $this->owner->id, 'platform' => 'x', 'platform_account_id' => 'x-1', 'status' => 'connected',
        ])->delete();

        $provider = app(SocialProviderManager::class)->for('x');
        $store = new ReflectionMethod($provider, 'storeAccount');

        $this->expectException(AccountAlreadyConnectedException::class);
        $this->expectExceptionMessage('You already created an account with this X account on email se****@gmail.com.');
        $store->invoke($provider, $this->newcomer, ['platform_account_id' => 'x-1', 'name' => 'X']);
    }

    public function test_youtube_is_blocked_before_the_channel_is_claimed(): void
    {
        Channel::create(['user_id' => $this->owner->id, 'youtube_channel_id' => 'UC123', 'channel_name' => 'Mine'])->delete();
        $public = Channel::create(['user_id' => null, 'youtube_channel_id' => 'UC123', 'channel_name' => 'Public copy']);
        Http::fake(['*googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [['id' => 'UC123', 'snippet' => ['title' => 'Mine'], 'statistics' => []]],
        ])]);

        try {
            (new YouTubeChannelService(new YouTubeAnalyticsService))->createOrUpdateChannel($this->newcomer, 'token');
            $this->fail('Expected the channel to be refused.');
        } catch (AccountAlreadyConnectedException $e) {
            $this->assertSame('You already created an account with this channel on email se****@gmail.com. Log in with that email to use it.', $e->getMessage());
        }

        $this->assertNull($public->fresh()->user_id, 'The public copy must not be handed to the blocked user.');
        $this->assertSame(0, Channel::withTrashed()->where('user_id', $this->newcomer->id)->count());
    }

    public function test_the_owner_reconnecting_youtube_reuses_their_row_even_with_a_public_copy(): void
    {
        $mine = Channel::create(['user_id' => $this->owner->id, 'youtube_channel_id' => 'UC123', 'channel_name' => 'Mine']);
        $mine->delete();
        Channel::create(['user_id' => null, 'youtube_channel_id' => 'UC123', 'channel_name' => 'Public copy']);
        Http::fake(['*googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [['id' => 'UC123', 'snippet' => ['title' => 'Mine'], 'statistics' => []]],
        ])]);

        $channel = (new YouTubeChannelService(new YouTubeAnalyticsService))->createOrUpdateChannel($this->owner, 'token');

        $this->assertSame($mine->id, $channel->id);
        $this->assertFalse($channel->trashed());
    }

    public function test_the_newer_connect_path_returns_the_message_as_is(): void
    {
        // TikTok/X/Instagram/... connect through POST /api/social/{platform}/exchange.
        $provider = \Mockery::mock(\App\Services\Social\Contracts\SocialProviderInterface::class);
        $provider->shouldReceive('connectFromCode')->andThrow(AccountAlreadyConnectedException::forOwner($this->owner, 'x'));
        $this->mock(SocialProviderManager::class, function ($manager) use ($provider) {
            $manager->shouldReceive('supports')->andReturn(true);
            $manager->shouldReceive('isConfigured')->andReturn(true);
            $manager->shouldReceive('for')->andReturn($provider);
        });

        $this->postJson('/api/social/x/exchange', ['code' => 'c'], $this->headers($this->newcomer))
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already created an account with this X account on email se****@gmail.com. Log in with that email to use it.');
    }

    public function test_the_older_youtube_path_refuses_and_leaves_the_account_unchanged(): void
    {
        Channel::create(['user_id' => $this->owner->id, 'youtube_channel_id' => 'UC123', 'channel_name' => 'Mine'])->delete();
        $this->newcomer->forceFill(['youtube_access_token' => 'their-own-token', 'youtube_refresh_token' => 'their-own-refresh'])->save();
        config(['services.google.client_id' => 'cid', 'services.google.client_secret' => 'secret']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'blocked-access', 'refresh_token' => 'blocked-refresh', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC123', 'snippet' => ['title' => 'Mine'], 'statistics' => []]]]),
            '*googleapis.com*' => Http::response(['items' => [], 'rows' => []]),
        ]);

        $this->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/cb'], $this->headers($this->newcomer))
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already created an account with this channel on email se****@gmail.com. Log in with that email to use it.');

        $this->newcomer->refresh();
        $this->assertSame('their-own-token', $this->newcomer->youtube_access_token);
        $this->assertSame('their-own-refresh', $this->newcomer->youtube_refresh_token);
        $this->assertSame(0, Connection::withTrashed()->where('user_id', $this->newcomer->id)->count());
        $this->assertSame(0, SocialAccount::withTrashed()->where('user_id', $this->newcomer->id)->count());
    }

    public function test_a_deleted_first_owner_still_blocks_the_channel(): void
    {
        $this->fakeTikTok();
        $this->connectTikTok($this->owner)->assertOk();
        $this->owner->delete(); // users are soft-deleted too

        $this->connectTikTok($this->newcomer)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already created an account with this TikTok account on email se****@gmail.com. Log in with that email to use it.');
    }

    public function test_the_masked_email_keeps_only_the_first_two_letters(): void
    {
        $this->assertSame('se****@gmail.com', AccountAlreadyConnectedException::maskEmail('sean@gmail.com'));
        $this->assertSame('a****@x.com', AccountAlreadyConnectedException::maskEmail('a@x.com'));
    }
}
