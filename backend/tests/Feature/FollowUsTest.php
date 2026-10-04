<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Follow us" on the connect page: when the box is left ticked, connecting an
 * X or Bluesky account also follows the ViewsMax account configured in
 * social.follow_us. A failed follow must never fail the connection, and the X
 * follow permission is only requested when the box is ticked.
 */
class FollowUsTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'social.platforms.x.client_id' => 'x-client',
            'social.platforms.x.client_secret' => 'x-secret',
            'social.follow_us' => ['x' => 'viewsmax', 'bluesky' => 'viewsmax.bsky.social'],
        ]);

        $user = User::factory()->create();
        $this->headers = [
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function fakeX(int $followStatus = 200): void
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7200,
                'scope' => 'tweet.read tweet.write users.read offline.access follows.write',
            ]),
            'api.twitter.com/2/users/me*' => Http::response(['data' => ['id' => '111', 'name' => 'Jo', 'username' => 'jo']]),
            'api.twitter.com/2/users/by/username/viewsmax*' => Http::response(['data' => ['id' => '999', 'name' => 'ViewsMax', 'username' => 'viewsmax']]),
            'api.twitter.com/2/users/111/following' => Http::response(['data' => ['following' => true]], $followStatus),
        ]);
    }

    /** Run the X auth-url + exchange round trip; returns the exchange response. */
    private function connectX(bool $followUs)
    {
        $auth = $this->withHeaders($this->headers)
            ->getJson('/api/social/x/auth-url?redirect_uri=https://app.test/social/callback'.($followUs ? '&follow_us=1' : ''))
            ->assertOk();

        return $this->withHeaders($this->headers)->postJson('/api/social/x/exchange', [
            'code' => 'abc',
            'state' => $auth->json('data.state'),
        ]);
    }

    private function followRequests(): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/2/users/111/following'))->count();
    }

    public function test_catalog_says_which_platforms_offer_follow_us(): void
    {
        $catalog = collect($this->withHeaders($this->headers)->getJson('/api/social/platforms')->json('data'))
            ->pluck('follow_us', 'platform');

        $this->assertSame('viewsmax', $catalog['x']);
        $this->assertSame('viewsmax.bsky.social', $catalog['bluesky']);
        $this->assertNull($catalog['linkedin']);
    }

    public function test_handles_can_be_pasted_as_profile_links(): void
    {
        config(['social.follow_us' => ['x' => 'https://x.com/ViewsMax/', 'bluesky' => '@viewsmax.bsky.social']]);

        $catalog = collect($this->withHeaders($this->headers)->getJson('/api/social/platforms')->json('data'))
            ->pluck('follow_us', 'platform');

        $this->assertSame('ViewsMax', $catalog['x']);
        $this->assertSame('viewsmax.bsky.social', $catalog['bluesky']);
    }

    public function test_x_follow_permission_is_only_requested_when_ticked(): void
    {
        $base = '/api/social/x/auth-url?redirect_uri=https://app.test/social/callback';

        $ticked = $this->withHeaders($this->headers)->getJson($base.'&follow_us=1')->json('data.authorization_url');
        $unticked = $this->withHeaders($this->headers)->getJson($base)->json('data.authorization_url');

        $this->assertStringContainsString('follows.write', urldecode($ticked));
        $this->assertStringNotContainsString('follows.write', urldecode($unticked));

        // Nothing to follow → never ask for the permission.
        config(['social.follow_us' => []]);
        $off = $this->withHeaders($this->headers)->getJson($base.'&follow_us=1')->json('data.authorization_url');
        $this->assertStringNotContainsString('follows.write', urldecode($off));
    }

    public function test_connecting_x_with_the_box_ticked_follows_our_account(): void
    {
        $this->fakeX();

        $this->connectX(followUs: true)->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/2/users/111/following')
            && $r->method() === 'POST'
            && $r['target_user_id'] === '999');
    }

    public function test_connecting_x_with_the_box_unticked_follows_nobody(): void
    {
        $this->fakeX();

        $this->connectX(followUs: false)->assertOk();

        $this->assertSame(0, $this->followRequests());
    }

    public function test_a_failed_follow_does_not_fail_the_connection(): void
    {
        $this->fakeX(followStatus: 403);

        $this->connectX(followUs: true)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $this->followRequests());
        $this->assertDatabaseHas('social_accounts', ['platform' => 'x', 'platform_account_id' => '111']);
    }

    public function test_connecting_bluesky_with_the_box_ticked_follows_our_account(): void
    {
        Http::fake([
            '*/xrpc/com.atproto.server.createSession' => Http::response([
                'did' => 'did:plc:user', 'handle' => 'jo.bsky.social', 'accessJwt' => 'a', 'refreshJwt' => 'r',
            ]),
            '*/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:viewsmax']),
            '*/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://x', 'cid' => 'c']),
        ]);

        $this->withHeaders($this->headers)->postJson('/api/social/bluesky/connect', [
            'identifier' => 'jo.bsky.social', 'password' => 'app-pass', 'follow_us' => true,
        ])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'com.atproto.repo.createRecord')
            && $r['repo'] === 'did:plc:user'
            && $r['collection'] === 'app.bsky.graph.follow'
            && $r['record']['subject'] === 'did:plc:viewsmax');
    }

    public function test_connecting_bluesky_with_the_box_unticked_follows_nobody(): void
    {
        Http::fake([
            '*/xrpc/com.atproto.server.createSession' => Http::response([
                'did' => 'did:plc:user', 'handle' => 'jo.bsky.social', 'accessJwt' => 'a', 'refreshJwt' => 'r',
            ]),
        ]);

        $this->withHeaders($this->headers)->postJson('/api/social/bluesky/connect', [
            'identifier' => 'jo.bsky.social', 'password' => 'app-pass',
        ])->assertOk();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'createRecord'));
    }
}
