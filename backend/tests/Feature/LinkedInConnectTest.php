<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\LinkedInProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LinkedIn's OIDC userinfo carries the member's email but no public handle.
 * The email must not be stored as `username`: list_connected_accounts and
 * list_brands expose that column to AI clients, and ChatGPT's plugin review
 * flagged an email coming back as a username.
 */
class LinkedInConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.linkedin.client_id' => 'id', 'social.platforms.linkedin.client_secret' => 'secret']);

        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000], 200),
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

        (new LinkedInProvider)->connectFromCode($user, 'code', 'https://app/cb');

        $account = SocialAccount::sole();
        $this->assertNull($account->username);
        $this->assertSame('connected', $account->status);
    }
}
