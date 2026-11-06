<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * TikTok avatar URLs are short-lived signed links, so GET /api/connections
 * refreshes stale (≥24h) TikTok profiles server-side. A refresh failure must
 * never break the list.
 */
class TikTokProfileRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        config(['services.tiktok.client_key' => 'k', 'services.tiktok.client_secret' => 's']);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function makeConnection(array $overrides = []): Connection
    {
        return Connection::create(array_merge([
            'user_id' => $this->user->id,
            'provider' => 'tiktok',
            'account_name' => 'Old Name',
            'account_id' => 'tt-1',
            'avatar_url' => 'https://cdn.tiktok.example/expired-signed-url.jpg',
            'access_token' => 'live-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDay(),
        ], $overrides));
    }

    public function test_stale_tiktok_profile_is_refreshed_on_index(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => ['user' => [
                    'open_id' => 'tt-1',
                    'display_name' => 'Fresh Name',
                    'avatar_url' => 'https://cdn.tiktok.example/fresh-signed-url.jpg',
                ]],
            ]),
        ]);
        $connection = $this->makeConnection();
        Connection::whereKey($connection->id)->update(['updated_at' => now()->subDays(2)]);

        $response = $this->withHeaders($this->auth())->getJson('/api/connections');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('provider', 'tiktok');
        $this->assertSame('Fresh Name', $row['account_name']);
        $this->assertSame('https://cdn.tiktok.example/fresh-signed-url.jpg', $row['avatar_url']);
    }

    public function test_recently_updated_profile_is_not_refetched(): void
    {
        Http::fake();
        $this->makeConnection(); // updated_at = now

        $this->withHeaders($this->auth())->getJson('/api/connections')->assertOk();

        Http::assertNothingSent();
    }

    public function test_refresh_failure_does_not_break_the_list(): void
    {
        Http::fake([
            'open.tiktokapis.com/*' => Http::response(['error' => 'nope'], 500),
        ]);
        $connection = $this->makeConnection();
        Connection::whereKey($connection->id)->update(['updated_at' => now()->subDays(2)]);

        $response = $this->withHeaders($this->auth())->getJson('/api/connections');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('provider', 'tiktok');
        $this->assertSame('Old Name', $row['account_name']); // stale but present
    }

    public function test_a_connection_with_no_mirrored_account_still_refreshes_its_own_token(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 86400,
            ]),
            'open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => ['user' => ['open_id' => 'tt-1', 'display_name' => 'Fresh', 'avatar_url' => 'https://cdn.tiktok.example/a.jpg']],
            ]),
        ]);
        $connection = $this->makeConnection(['token_expires_at' => now()->subHour()]);
        Connection::whereKey($connection->id)->update(['updated_at' => now()->subDays(2)]);

        $this->withHeaders($this->auth())->getJson('/api/connections')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth/token'));
        $this->assertSame('new-access', $connection->fresh()->access_token);
    }

    // ---- one refresher per grant ----------
    // Publishing reads the same TikTok grant from social_accounts. TikTok
    // retires a refresh token once it issues the next one, so two stores
    // refreshing on their own means the second is rejected.

    /** The same grant in the publishing store. */
    private function mirroredAccount(): SocialAccount
    {
        config(['social.platforms.tiktok.client_id' => 'k', 'social.platforms.tiktok.client_secret' => 's']);

        return SocialAccount::create([
            'user_id' => $this->user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'name' => 'Old Name',
            'access_token' => 'social-access',
            'refresh_token' => 'social-refresh',
            'token_expires_at' => now()->subHour(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function staleConnection(): Connection
    {
        $connection = $this->makeConnection(['token_expires_at' => now()->subHour(), 'refresh_token' => 'legacy-refresh']);
        Connection::whereKey($connection->id)->update(['updated_at' => now()->subDays(2)]);

        return $connection;
    }

    public function test_a_mirrored_account_is_refreshed_through_the_publishing_store_only(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 86400]),
            'open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => ['user' => ['open_id' => 'tt-1', 'display_name' => 'Fresh', 'avatar_url' => 'https://cdn.tiktok.example/a.jpg']],
            ]),
        ]);
        $account = $this->mirroredAccount();
        $this->staleConnection();

        $response = $this->withHeaders($this->auth())->getJson('/api/connections');

        $response->assertOk();
        $this->assertSame('Fresh', collect($response->json('data'))->firstWhere('provider', 'tiktok')['account_name']);
        $refreshes = Http::recorded(fn ($r) => str_contains($r->url(), 'oauth/token'));
        $this->assertCount(1, $refreshes);
        $this->assertSame('social-refresh', $refreshes->first()[0]['refresh_token']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'user/info') && $r->hasHeader('Authorization', 'Bearer new-access'));
        $account->refresh();
        $this->assertSame('new-access', $account->access_token);
        $this->assertSame('new-refresh', $account->refresh_token);
    }

    public function test_a_failed_refresh_of_a_mirrored_account_does_not_fall_back_to_the_legacy_token(): void
    {
        Sleep::fake();
        Http::fake(['open.tiktokapis.com/*' => Http::response(['error' => 'server_error'], 503)]);
        $account = $this->mirroredAccount();
        $this->staleConnection();

        $response = $this->withHeaders($this->auth())->getJson('/api/connections');

        $response->assertOk();
        $this->assertSame('Old Name', collect($response->json('data'))->firstWhere('provider', 'tiktok')['account_name']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'oauth/token') && $r['refresh_token'] === 'legacy-refresh');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'user/info'));
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->refresh()->status);
    }
}
