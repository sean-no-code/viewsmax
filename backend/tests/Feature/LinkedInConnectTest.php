<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\LinkedInProvider;
use App\Services\Social\SocialConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LinkedIn's OIDC userinfo carries the member's email but no public handle.
 * The email must not be stored as `username`: list_connected_accounts and
 * list_brands expose that column to AI clients, and ChatGPT's plugin review
 * flagged an email coming back as a username.
 *
 * Also: a reconnect must keep the stored refresh token when LinkedIn's token
 * response omits one, and a token granted without the scopes
 * publishing needs fails the connect up front.
 */
class LinkedInConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.linkedin.client_id' => 'id', 'social.platforms.linkedin.client_secret' => 'secret']);
    }

    /** Fake the code exchange (no refresh_token / scope unless given) and userinfo. */
    private function fakeLinkedIn(array $token = []): void
    {
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response($token + ['access_token' => 'tok', 'expires_in' => 5184000], 200),
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'abc',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'picture' => 'https://media.licdn.com/jane.jpg',
            ], 200),
        ]);
    }

    public function test_connect_stores_the_display_name_but_never_the_email_as_username(): void
    {
        $this->fakeLinkedIn();
        (new LinkedInProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $account = SocialAccount::sole();
        $this->assertSame('linkedin', $account->platform);
        $this->assertSame('abc', $account->platform_account_id);
        $this->assertSame('Jane Doe', $account->name);
        $this->assertNull($account->username);
        $this->assertSame('connected', $account->status);
    }

    public function test_reconnect_clears_an_email_that_older_code_stored_as_username(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'abc',
            'name' => 'Jane Doe',
            'username' => 'jane@example.com',
            'status' => 'needs_reauth',
        ]);

        $this->fakeLinkedIn();
        (new LinkedInProvider)->connectFromCode($user, 'code', 'https://app/cb');

        $account = SocialAccount::sole();
        $this->assertNull($account->username);
        $this->assertSame('connected', $account->status);
    }

    public function test_reconnect_without_a_refresh_token_keeps_the_stored_one(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'abc',
            'access_token' => 'old-access',
            'refresh_token' => 'long-lived-refresh',
            'status' => 'needs_reauth',
        ]);

        // The default token response carries no refresh_token at all.
        $this->fakeLinkedIn();
        (new LinkedInProvider)->connectFromCode($user, 'code', 'https://app/cb');

        $account = SocialAccount::sole();
        $this->assertSame('tok', $account->access_token);
        $this->assertSame('long-lived-refresh', $account->refresh_token);
        $this->assertSame('connected', $account->status);
    }

    public function test_connect_with_every_publishing_scope_succeeds(): void
    {
        $this->fakeLinkedIn(['scope' => 'openid,profile,email,w_member_social']);

        (new LinkedInProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $this->assertSame(['openid', 'profile', 'email', 'w_member_social'], SocialAccount::sole()->scopes);
    }

    public function test_connect_without_the_posting_scope_fails_and_stores_nothing(): void
    {
        $this->fakeLinkedIn(['scope' => 'openid,profile,email']);

        try {
            (new LinkedInProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');
            $this->fail('A token without w_member_social must not connect');
        } catch (SocialConnectException $e) {
            $this->assertSame(
                'Not enough permissions were granted for LinkedIn: missing w_member_social. Reconnect and tick every box.',
                $e->getMessage()
            );
        }

        $this->assertSame(0, SocialAccount::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/userinfo'));
    }

    public function test_connect_without_the_optional_email_scope_still_succeeds(): void
    {
        $this->fakeLinkedIn(['scope' => 'openid,profile,w_member_social']);

        (new LinkedInProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $this->assertSame('connected', SocialAccount::sole()->status);
    }
}
