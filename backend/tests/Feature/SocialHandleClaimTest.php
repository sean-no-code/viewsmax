<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\SocialHandleClaim;
use App\Models\User;
use App\Services\Social\GoogleGrantRevoker;
use App\Services\Social\HandleClaimedException;
use App\Services\Social\HandleClaims;
use App\Services\Social\Providers\XProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A social account belongs to the ViewsMax user who connected it first. The
 * claim outlives disconnects and "Delete my data", so a second signup with
 * the same channel is sent to sign in with the original account.
 */
class SocialHandleClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'social.platforms.x.client_id' => 'x-client', 'social.platforms.x.client_secret' => 'x-secret',
            'services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret',
        ]);
    }

    private function fakeX(): void
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7200, 'scope' => 'tweet.read tweet.write users.read offline.access media.write']),
            'api.twitter.com/2/users/me*' => Http::response(['data' => ['id' => '111', 'name' => 'Jo', 'username' => 'jo']]),
        ]);
    }

    private function fakeGoogle(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-at', 'refresh_token' => 'new-rt', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'https://www.googleapis.com/auth/youtube.readonly']),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'chan-1',
                'snippet' => ['title' => 'My Channel', 'description' => '', 'customUrl' => '@me', 'publishedAt' => '2020-01-01T00:00:00Z', 'thumbnails' => ['default' => ['url' => 'https://img/d.png']]],
                'statistics' => ['subscriberCount' => '10', 'videoCount' => '1', 'viewCount' => '100'],
            ]]]),
            GoogleGrantRevoker::REVOKE_URL => Http::response('', 200),
            '*' => Http::response([], 200),
        ]);
    }

    private function auth(User $user): array
    {
        // The guard caches the resolved user across requests in one test; drop it when switching users.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_connecting_records_the_handle_for_its_owner(): void
    {
        $this->fakeX();
        $user = User::factory()->create();

        (new XProvider)->connectFromCode($user, 'code', 'https://app/cb');

        $claim = SocialHandleClaim::sole();
        $this->assertSame([$user->id, 'x', '111', 'jo'], [$claim->user_id, $claim->platform, $claim->platform_account_id, $claim->handle]);
    }

    public function test_the_same_user_can_reconnect(): void
    {
        $this->fakeX();
        $user = User::factory()->create();
        (new XProvider)->connectFromCode($user, 'code', 'https://app/cb');
        $first = SocialHandleClaim::sole()->first_connected_at;

        $this->travel(1)->days();
        (new XProvider)->connectFromCode($user, 'code', 'https://app/cb');

        $this->assertSame(1, SocialHandleClaim::count());
        $this->assertTrue(SocialHandleClaim::sole()->first_connected_at->equalTo($first));
        $this->assertTrue(SocialHandleClaim::sole()->last_connected_at->isAfter($first));
    }

    public function test_another_user_connecting_the_same_account_is_told_to_sign_in_with_the_original(): void
    {
        $this->fakeX();
        $owner = User::factory()->create(['email' => 'sean@example.com']);
        (new XProvider)->connectFromCode($owner, 'code', 'https://app/cb');
        $other = User::factory()->create();

        try {
            (new XProvider)->connectFromCode($other, 'code', 'https://app/cb');
            $this->fail('expected HandleClaimedException');
        } catch (HandleClaimedException $e) {
            $this->assertStringContainsString('X account (jo) is already connected to another ViewsMax account', $e->getMessage());
            $this->assertStringContainsString('s***@example.com', $e->getMessage());
        }

        $this->assertSame([$owner->id], SocialAccount::pluck('user_id')->all(), 'nothing is stored for the second user');
        $this->assertSame($owner->id, SocialHandleClaim::sole()->user_id);
    }

    public function test_the_claim_survives_disconnect_and_delete_my_data(): void
    {
        $this->fakeGoogle();
        $owner = User::factory()->create(['email' => 'sean@example.com']);
        $this->withHeaders($this->auth($owner))
            ->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/oauth/callback'])
            ->assertOk();
        $this->assertSame('@me', SocialHandleClaim::sole()->handle);

        $this->withHeaders($this->auth($owner))->deleteJson('/api/user-data')->assertOk();

        $this->assertSame(0, Channel::count());
        $this->assertSame(0, SocialAccount::count());
        $this->assertSame($owner->id, SocialHandleClaim::sole()->user_id, 'the handle is remembered after the data is gone');

        // A second ViewsMax signup connecting the same channel is refused: no tokens, no channel, grant handed back.
        $other = User::factory()->create();
        $response = $this->withHeaders($this->auth($other))
            ->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/oauth/callback']);

        $response->assertStatus(422);
        $this->assertStringContainsString('sign in with that account (s***@example.com)', strtolower($response->json('message')));
        $this->assertSame(0, Channel::count());
        $this->assertSame(0, SocialAccount::count());
        $this->assertNull($other->fresh()->youtube_access_token);
        Http::assertSent(fn ($r) => $r->url() === GoogleGrantRevoker::REVOKE_URL && $r['token'] === 'new-rt');

        // The owner can still come back.
        $this->withHeaders($this->auth($owner))
            ->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/oauth/callback'])
            ->assertOk();
        $this->assertSame(1, Channel::count());
    }

    public function test_the_social_exchange_endpoint_reports_the_refusal_even_outside_debug(): void
    {
        config(['app.debug' => false]);
        $this->fakeX();
        $owner = User::factory()->create(['email' => 'sean@example.com']);
        (new XProvider)->connectFromCode($owner, 'code', 'https://app/cb');

        $response = $this->withHeaders($this->auth(User::factory()->create()))
            ->postJson('/api/social/x/exchange', ['code' => 'code', 'redirect_uri' => 'https://app/cb']);

        $response->assertStatus(422);
        $this->assertStringContainsString('already connected to another ViewsMax account', $response->json('message'));
    }

    public function test_accounts_without_a_platform_id_are_not_claimed(): void
    {
        $user = User::factory()->create();

        HandleClaims::claim($user, 'x', null, 'jo');
        HandleClaims::claim($user, 'x', '', 'jo');

        $this->assertSame(0, SocialHandleClaim::count());
    }
}
