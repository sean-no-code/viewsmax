<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\InstagramProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression (real prod failure): connectFromCode silently fell back to the
 * 1-hour SHORT-LIVED token (with token_expires_at = null → treated as valid
 * forever, never refreshed) when the long-lived exchange failed. A "successful"
 * reconnect then died within the hour with OAuthException 190 at publish time,
 * looping the user through reconnect → expired → reconnect.
 */
class InstagramConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.instagram.client_id' => 'id', 'social.platforms.instagram.client_secret' => 'secret']);
    }

    public function test_failed_long_lived_exchange_fails_loudly_instead_of_storing_the_short_token(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 'ig-1'], 200),
            'graph.instagram.com/access_token*' => Http::response(['error' => ['message' => 'bad secret']], 400),
            'graph.instagram.com/*' => Http::response([], 200),
        ]);

        $this->expectException(RuntimeException::class);

        (new InstagramProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        // Nothing half-connected may be stored — the popup shows the real error.
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_successful_connect_stores_the_long_lived_token_with_a_real_expiry(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 'ig-1'], 200),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-lived', 'expires_in' => 5184000], 200),
            'graph.instagram.com/*/me*' => Http::response(['user_id' => 'ig-1', 'username' => 'viewsmax'], 200),
            'graph.instagram.com/*' => Http::response(['user_id' => 'ig-1', 'username' => 'viewsmax'], 200),
        ]);

        (new InstagramProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $account = SocialAccount::sole();
        $this->assertSame('connected', $account->status);
        $this->assertSame('long-lived', $account->access_token);
        $this->assertNotNull($account->token_expires_at);
        $this->assertTrue($account->token_expires_at->gt(now()->addDays(50)));
    }

    public function test_token_expiring_soon_is_refreshed_proactively(): void
    {
        Http::fake([
            'graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'refreshed', 'expires_in' => 5184000], 200),
        ]);
        $user = User::factory()->create();
        $account = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'instagram',
            'platform_account_id' => 'ig-1',
            'access_token' => 'aging',
            'token_expires_at' => now()->addDays(3), // valid, but inside the refresh window
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $fresh = (new InstagramProvider)->ensureFreshToken($account);

        $this->assertSame('refreshed', $fresh->access_token);
        $this->assertTrue($fresh->token_expires_at->gt(now()->addDays(50)));
    }

    public function test_failed_proactive_refresh_keeps_a_still_valid_token_usable(): void
    {
        Http::fake([
            'graph.instagram.com/refresh_access_token*' => Http::response(['error' => ['message' => 'too new']], 400),
        ]);
        $user = User::factory()->create();
        $account = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'instagram',
            'platform_account_id' => 'ig-1',
            'access_token' => 'still-good',
            'token_expires_at' => now()->addDays(3),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $result = (new InstagramProvider)->ensureFreshToken($account);

        // Refresh failed but the token hasn't expired — keep publishing with it.
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $result->status);
        $this->assertSame('still-good', $result->access_token);
    }
}
