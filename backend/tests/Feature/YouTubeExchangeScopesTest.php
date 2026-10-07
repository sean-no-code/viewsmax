<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\YouTubePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /api/auth/youtube/exchange mirrors a Google grant into the publishing
 * account. It must record the scopes Google granted and never swap a token
 * that can upload for one that can't.
 */
class YouTubeExchangeScopesTest extends TestCase
{
    use RefreshDatabase;

    private const READ_ONLY = 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly';

    private const WITH_UPLOAD = YouTubePublishService::UPLOAD_SCOPE.' https://www.googleapis.com/auth/youtube.readonly';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    }

    private function fakeGoogle(string $grantedScopes, string $refreshToken = 'new-rt'): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-at', 'refresh_token' => $refreshToken, 'expires_in' => 3600,
                'token_type' => 'Bearer', 'scope' => $grantedScopes,
            ]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'chan-1',
                'snippet' => ['title' => 'My Channel', 'description' => '', 'customUrl' => '@me', 'publishedAt' => '2020-01-01T00:00:00Z', 'thumbnails' => ['default' => ['url' => 'https://img/d.png']]],
                'statistics' => ['subscriberCount' => '10', 'videoCount' => '1', 'viewCount' => '100'],
            ]]]),
            '*' => Http::response([], 200),
        ]);
    }

    private function exchange(User $user)
    {
        $token = $user->createToken('t')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/oauth/callback']);
    }

    private function publishingAccount(User $user): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'platform_account_id' => 'chan-1',
            'name' => 'Old name', 'access_token' => 'old-at', 'refresh_token' => 'old-rt',
            'token_expires_at' => now()->addHour(), 'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => explode(' ', self::WITH_UPLOAD),
        ]);
    }

    public function test_a_read_only_grant_keeps_the_existing_publishing_token(): void
    {
        $user = User::factory()->create();
        $account = $this->publishingAccount($user);
        $this->fakeGoogle(self::READ_ONLY);

        $this->exchange($user)->assertOk()->assertJsonPath('data.channel_connected', true);

        $account->refresh();
        $this->assertSame('old-rt', $account->refresh_token);
        $this->assertSame('old-at', $account->access_token);
        $this->assertContains(YouTubePublishService::UPLOAD_SCOPE, $account->scopes);
        $this->assertSame('My Channel', $account->name); // profile still refreshed
        $this->assertSame(1, SocialAccount::count());
    }

    public function test_a_grant_with_the_upload_scope_replaces_the_token(): void
    {
        $user = User::factory()->create();
        $account = $this->publishingAccount($user);
        $this->fakeGoogle(self::WITH_UPLOAD);

        $this->exchange($user)->assertOk();

        $account->refresh();
        $this->assertSame('new-rt', $account->refresh_token);
        $this->assertSame('new-at', $account->access_token);
        $this->assertEqualsCanonicalizing(explode(' ', self::WITH_UPLOAD), $account->scopes);
    }

    public function test_a_first_read_only_grant_records_the_scopes_it_really_has(): void
    {
        // No publishing token to protect: store the grant, with its true
        // scopes, so the publish job can say "reconnect to grant upload" up
        // front instead of the row claiming an upload scope it never had.
        $user = User::factory()->create();
        $this->fakeGoogle(self::READ_ONLY);

        $this->exchange($user)->assertOk();

        $account = SocialAccount::sole();
        $this->assertSame('new-rt', $account->refresh_token);
        $this->assertEqualsCanonicalizing(explode(' ', self::READ_ONLY), $account->scopes);
        $this->assertNotContains(YouTubePublishService::UPLOAD_SCOPE, $account->scopes);
    }

    public function test_a_dead_publishing_token_is_replaced_even_by_a_read_only_grant(): void
    {
        $user = User::factory()->create();
        $account = $this->publishingAccount($user);
        $account->update(['status' => SocialAccount::STATUS_NEEDS_REAUTH]);
        $this->fakeGoogle(self::READ_ONLY);

        $this->exchange($user)->assertOk();

        $account->refresh();
        $this->assertSame('new-rt', $account->refresh_token);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status);
        $this->assertNotContains(YouTubePublishService::UPLOAD_SCOPE, $account->scopes);
    }
}
