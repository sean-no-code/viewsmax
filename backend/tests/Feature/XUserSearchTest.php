<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Backend proxy for the composer's @mention typeahead. Uses the user's own
 * connected X account token. X's /2/users/search endpoint is tier-gated: a
 * 403 flips an app-level flag and the endpoint degrades to exact-username
 * lookup (/2/users/by/username) which lower tiers allow.
 */
class XUserSearchTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_URL = 'api.twitter.com/2/users/search*';

    private const LOOKUP_URL = 'api.twitter.com/2/users/by/username/*';

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function connectX(User $user, string $accountId = 'x-1'): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'x',
            'platform_account_id' => $accountId,
            'name' => "Account {$accountId}",
            'username' => "user_{$accountId}",
            'access_token' => 'token-'.$accountId,
            'token_expires_at' => now()->addDay(),
            'scopes' => ['tweet.write', 'users.read'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function xUser(string $username, string $id = '1'): array
    {
        return [
            'id' => $id,
            'name' => ucfirst($username),
            'username' => $username,
            'profile_image_url' => "https://pbs.twimg.com/{$username}.jpg",
            'verified' => false,
        ];
    }

    public function test_search_returns_normalized_users(): void
    {
        $this->connectX($this->user);
        Http::fake([
            self::SEARCH_URL => Http::response(['data' => [$this->xUser('jane', '11'), $this->xUser('janet', '12')]]),
        ]);

        $response = $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=jan');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mode', 'search')
            ->assertJsonPath('data.degraded', false)
            ->assertJsonPath('data.users.0.username', 'jane')
            ->assertJsonPath('data.users.0.avatar_url', 'https://pbs.twimg.com/jane.jpg')
            ->assertJsonPath('data.users.1.username', 'janet');
    }

    public function test_search_403_falls_back_to_lookup_and_caches_the_tier_flag(): void
    {
        $this->connectX($this->user);
        Http::fake([
            self::SEARCH_URL => Http::response(['title' => 'client-not-enrolled'], 403),
            self::LOOKUP_URL => Http::response(['data' => $this->xUser('jane', '11')]),
        ]);

        $first = $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=jane');
        $first->assertStatus(200)
            ->assertJsonPath('data.mode', 'lookup')
            ->assertJsonPath('data.degraded', true)
            ->assertJsonPath('data.users.0.username', 'jane');

        // Second request must go straight to lookup — the tier flag is cached.
        Http::fake([
            self::LOOKUP_URL => Http::response(['data' => $this->xUser('janet', '12')]),
        ]);
        $second = $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=janet');
        $second->assertStatus(200)->assertJsonPath('data.mode', 'lookup');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '2/users/search'));
    }

    public function test_lookup_miss_returns_empty_list(): void
    {
        $this->connectX($this->user);
        cache()->put('x-mention:search-blocked', true, 600);
        Http::fake([
            // X reports an unknown username as 200 + errors, no data key.
            self::LOOKUP_URL => Http::response(['errors' => [['title' => 'Not Found Error']]]),
        ]);

        $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=nobody')
            ->assertStatus(200)
            ->assertJsonPath('data.mode', 'lookup')
            ->assertJsonPath('data.users', []);
    }

    public function test_invalid_handle_characters_return_empty_without_calling_x(): void
    {
        $this->connectX($this->user);
        Http::fake();

        $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q='.urlencode('ja ne!'))
            ->assertStatus(200)
            ->assertJsonPath('data.users', []);

        Http::assertNothingSent();
    }

    public function test_no_connected_x_account_is_422(): void
    {
        Http::fake();

        $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=jane')
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_foreign_account_id_is_422(): void
    {
        $other = User::factory()->create();
        $foreign = $this->connectX($other, 'x-9');
        $this->connectX($this->user);
        Http::fake();

        $this->withHeaders($this->auth())
            ->getJson('/api/social/x/users/search?q=jane&social_account_id='.$foreign->id)
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_x_rate_limit_passes_through_as_429(): void
    {
        $this->connectX($this->user);
        Http::fake([
            self::SEARCH_URL => Http::response(['title' => 'Too Many Requests'], 429),
        ]);

        $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=jane')
            ->assertStatus(429);
    }

    public function test_search_results_are_cached_per_query(): void
    {
        $account = $this->connectX($this->user);
        Http::fake([
            self::SEARCH_URL => Http::response(['data' => [$this->xUser('jane', '11')]]),
        ]);

        $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=jane')->assertStatus(200);
        $this->withHeaders($this->auth())->getJson('/api/social/x/users/search?q=jane')->assertStatus(200);

        Http::assertSentCount(1);
    }
}
