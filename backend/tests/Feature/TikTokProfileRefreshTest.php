<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_expired_token_is_refreshed_before_the_profile_fetch(): void
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
}
