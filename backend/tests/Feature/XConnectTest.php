<?php

namespace Tests\Feature;

use App\Jobs\ImportRecentPostsJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\XProvider;
use App\Services\Social\SocialConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Connecting X checks the scopes the token was actually granted against what
 * publishing needs. A token missing tweet.write or media.write
 * would only fail at publish time; failing the connect instead sends the
 * user straight back to re-authorize with a message naming the gap.
 */
class XConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.x.client_id' => 'x-client', 'social.platforms.x.client_secret' => 'x-secret']);
    }

    private function fakeExchange(string $scope): void
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7200, 'scope' => $scope,
            ]),
            'api.twitter.com/2/users/me*' => Http::response(['data' => ['id' => '111', 'name' => 'Jo', 'username' => 'jo']]),
        ]);
    }

    public function test_connect_with_every_publishing_scope_succeeds(): void
    {
        $this->fakeExchange('tweet.read tweet.write users.read offline.access media.write');

        (new XProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $account = SocialAccount::sole();
        $this->assertSame('111', $account->platform_account_id);
        $this->assertContains('media.write', $account->scopes);
    }

    public function test_the_optional_follow_scope_is_not_required(): void
    {
        $this->fakeExchange('tweet.read tweet.write users.read offline.access media.write');

        (new XProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $this->assertSame('connected', SocialAccount::sole()->status);
    }

    public function test_connect_missing_publishing_scopes_fails_before_anything_is_stored(): void
    {
        $this->fakeExchange('tweet.read users.read offline.access');

        try {
            (new XProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');
            $this->fail('A token without tweet.write/media.write must not connect');
        } catch (SocialConnectException $e) {
            $this->assertSame(
                'Not enough permissions were granted for X: missing tweet.write, media.write. Reconnect and tick every box.',
                $e->getMessage()
            );
        }

        $this->assertSame(0, SocialAccount::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/users/me'));
    }

    public function test_the_exchange_endpoint_reports_the_missing_scopes(): void
    {
        $this->fakeExchange('tweet.read users.read offline.access media.write');
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $state = $this->withHeaders($headers)
            ->getJson('/api/social/x/auth-url?redirect_uri=https://app.test/social/callback')
            ->assertOk()->json('data.state');

        $this->withHeaders($headers)
            ->postJson('/api/social/x/exchange', ['code' => 'abc', 'state' => $state])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_successful_connect_queues_the_recent_posts_import(): void
    {
        Queue::fake();
        $this->fakeExchange('tweet.read tweet.write users.read offline.access media.write');
        // Analytics is the Pro tier upsell (see AnalyticsPlanGateTest for the gate).
        $user = $this->analyticsUser();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $state = $this->withHeaders($headers)
            ->getJson('/api/social/x/auth-url?redirect_uri=https://app.test/social/callback')
            ->assertOk()->json('data.state');

        $this->withHeaders($headers)
            ->postJson('/api/social/x/exchange', ['code' => 'abc', 'state' => $state])
            ->assertOk();

        $account = SocialAccount::sole();
        Queue::assertPushed(ImportRecentPostsJob::class, fn ($job) => $job->socialAccountId === $account->id);
    }
}
