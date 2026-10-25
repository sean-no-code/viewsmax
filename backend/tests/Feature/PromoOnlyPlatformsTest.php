<?php

namespace Tests\Feature;

use App\Mcp\Tools\CreatePost;
use App\Models\Role;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Limited release: a platform marked `promo_only` in config/social is fully
 * configured and live, but only promotional customers (User::PROMO_ROLE) and
 * admins may connect it. Customers see the Connections page's "Coming soon" state,
 * and the AI never offers it. `enabled` stays the kill switch for everybody.
 *
 * Instagram stands in for the Meta trio (facebook, instagram, threads) — they
 * share one code path, only the config key differs.
 */
class PromoOnlyPlatformsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Credentials for every platform so availability depends on the flags
        // under test, not the local .env.
        foreach (array_keys(config('social.platforms')) as $platform) {
            config([
                "social.platforms.{$platform}.client_id" => "{$platform}-client",
                "social.platforms.{$platform}.client_secret" => "{$platform}-secret",
                "social.platforms.{$platform}.enabled" => true,
                "social.platforms.{$platform}.promo_only" => false,
            ]);
        }

        config(['social.platforms.instagram.promo_only' => true]);
    }

    private function user(bool $promotional): User
    {
        return $this->userWithRoles($promotional ? [User::PROMO_ROLE] : []);
    }

    /** @param array<int, string> $roles */
    private function userWithRoles(array $roles): User
    {
        $user = User::factory()->create();

        foreach ($roles as $name) {
            $user->roles()->attach(
                Role::firstOrCreate(['name' => $name], ['display_name' => $name])->id
            );
        }

        return $user;
    }

    private function auth(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /** @return array<string, mixed> */
    private function catalogEntry(User $user, string $platform): array
    {
        $catalog = $this->withHeaders($this->auth($user))
            ->getJson('/api/social/platforms')
            ->assertOk()
            ->json('data');

        return collect($catalog)->firstWhere('platform', $platform);
    }

    private function mcpKey(User $user): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$login])
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');
    }

    private function callTool(string $key, string $tool, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
    }

    private function assertToolError(TestResponse $response, string $needle): void
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'expected the tool to error');
        $this->assertStringContainsStringIgnoringCase($needle, $response->json('result.content.0.text'));
    }

    // ── The platform catalog the Connections page renders ───────────────────

    public function test_a_promo_only_platform_is_unavailable_in_the_catalog_for_a_normal_user(): void
    {
        $entry = $this->catalogEntry($this->user(promotional: false), 'instagram');

        // `configured` stays honest about credentials; `available` is the
        // per-user answer the connect screen renders "Coming soon" from.
        $this->assertTrue($entry['configured']);
        $this->assertFalse($entry['available']);
    }

    public function test_a_promo_only_platform_is_available_to_a_promotional_customer(): void
    {
        $entry = $this->catalogEntry($this->user(promotional: true), 'instagram');

        $this->assertTrue($entry['available']);
    }

    public function test_platforms_that_are_not_promo_only_stay_available_to_everyone(): void
    {
        $entry = $this->catalogEntry($this->user(promotional: false), 'x');

        $this->assertTrue($entry['available']);
    }

    public function test_the_enabled_kill_switch_beats_the_promotional_role(): void
    {
        config(['social.platforms.instagram.enabled' => false]);

        $entry = $this->catalogEntry($this->user(promotional: true), 'instagram');

        $this->assertFalse($entry['available']);
        $this->assertFalse($entry['configured']);
    }

    public function test_clearing_promo_only_opens_the_platform_to_a_normal_user(): void
    {
        config(['social.platforms.instagram.promo_only' => false]);

        $entry = $this->catalogEntry($this->user(promotional: false), 'instagram');

        $this->assertTrue($entry['available']);
    }

    // ── The connect endpoints ──────────────────────────────────────────────

    public function test_auth_url_refuses_a_promo_only_platform_for_a_normal_user(): void
    {
        $this->withHeaders($this->auth($this->user(promotional: false)))
            ->getJson('/api/social/instagram/auth-url?redirect_uri=https://app.test/cb')
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_auth_url_builds_the_provider_url_for_a_promotional_customer(): void
    {
        $this->withHeaders($this->auth($this->user(promotional: true)))
            ->getJson('/api/social/instagram/auth-url?redirect_uri=https://app.test/cb')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['authorization_url']]);
    }

    public function test_an_admin_may_connect_without_the_promotional_role(): void
    {
        // Admins are in the limited-release audience alongside promotional
        // customers, so the team can test a platform before it opens.
        $admin = $this->userWithRoles(['admin']);

        $this->assertTrue($this->catalogEntry($admin, 'instagram')['available']);

        $this->withHeaders($this->auth($admin))
            ->getJson('/api/social/instagram/auth-url?redirect_uri=https://app.test/cb')
            ->assertOk()
            ->assertJsonStructure(['data' => ['authorization_url']]);

        $this->assertContains('instagram', CreatePost::availablePlatforms($admin));
    }

    public function test_a_customer_role_alone_does_not_unlock_a_promo_only_platform(): void
    {
        $customer = $this->userWithRoles(['customer']);

        $this->assertFalse($this->catalogEntry($customer, 'instagram')['available']);
    }

    public function test_an_admin_who_also_holds_the_promotional_role_may_connect(): void
    {
        $admin = $this->userWithRoles(['admin', User::PROMO_ROLE]);

        $this->assertTrue($this->catalogEntry($admin, 'instagram')['available']);
    }

    public function test_the_legacy_connection_exchange_does_not_accept_instagram(): void
    {
        // /api/auth/{provider}/exchange writes to the older Connection store.
        // Instagram connects through /api/social, so the old path must refuse
        // it outright, before any call to Meta, for every user.
        Http::fake();

        $this->withHeaders($this->auth($this->user(promotional: true)))
            ->postJson('/api/auth/instagram/exchange', [
                'code' => 'abc',
                'redirect_uri' => 'https://app.test/cb',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_legacy_connection_exchange_still_serves_an_open_platform(): void
    {
        // TikTok isn't in limited release, so the gate must not touch it: the
        // call gets through to the provider exchange and fails there instead.
        Http::fake();

        $this->withHeaders($this->auth($this->user(promotional: false)))
            ->postJson('/api/auth/tiktok/exchange', [
                'code' => 'abc',
                'redirect_uri' => 'https://app.test/cb',
            ])
            ->assertStatus(422);
    }

    public function test_exchange_refuses_a_promo_only_platform_for_a_normal_user(): void
    {
        // The gate can't be skipped by posting straight to exchange with a code
        // obtained elsewhere.
        Http::fake();

        $this->withHeaders($this->auth($this->user(promotional: false)))
            ->postJson('/api/social/instagram/exchange', ['code' => 'abc'])
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    // ── The MCP tools ──────────────────────────────────────────────────────

    public function test_the_ai_is_not_offered_a_promo_only_platform_for_a_normal_user(): void
    {
        $user = $this->user(promotional: false);

        $this->assertNotContains('instagram', CreatePost::availablePlatforms($user));
        $this->assertContains('x', CreatePost::availablePlatforms($user));
    }

    public function test_the_ai_is_offered_a_promo_only_platform_for_a_promotional_customer(): void
    {
        $this->assertContains('instagram', CreatePost::availablePlatforms($this->user(promotional: true)));
    }

    public function test_create_post_refuses_a_promo_only_platform_for_a_normal_user(): void
    {
        $this->assertToolError(
            $this->callTool(
                $this->mcpKey($this->user(promotional: false)),
                'create_post',
                ['caption' => 'Hello', 'platforms' => ['instagram']],
            ),
            "Instagram isn't available to connect",
        );
    }

    public function test_get_connect_url_refuses_a_promo_only_platform_for_a_normal_user(): void
    {
        $this->assertToolError(
            $this->callTool(
                $this->mcpKey($this->user(promotional: false)),
                'get_connect_url',
                ['platform' => 'instagram'],
            ),
            "isn't available to connect",
        );
    }

    public function test_get_connect_url_points_a_promotional_customer_at_the_connections_page(): void
    {
        $response = $this->callTool(
            $this->mcpKey($this->user(promotional: true)),
            'get_connect_url',
            ['platform' => 'instagram'],
        )->assertOk();

        $this->assertFalse((bool) $response->json('result.isError'));
    }

    // ── The service predicate itself ───────────────────────────────────────

    public function test_availability_without_a_user_treats_a_promo_only_platform_as_restricted(): void
    {
        // Console/queue code has no authenticated user; it must not assume access.
        $manager = app(SocialProviderManager::class);

        $this->assertFalse($manager->isAvailableTo(null, 'instagram'));
        $this->assertTrue($manager->isAvailableTo(null, 'x'));
    }

    public function test_the_meta_platforms_ship_restricted_in_production_and_open_elsewhere(): void
    {
        // The three platforms awaiting Meta App Review. Re-evaluate the config
        // file under each APP_ENV so this pins the shipped defaults rather than
        // whatever setUp() forced.
        $under = function (string $appEnv): array {
            $original = ['env' => $_ENV['APP_ENV'] ?? null, 'server' => $_SERVER['APP_ENV'] ?? null];
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $appEnv;

            try {
                return require config_path('social.php');
            } finally {
                foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
                    if ($original[$key] === null) {
                        unset($GLOBALS[$global]['APP_ENV']);
                    } else {
                        $GLOBALS[$global]['APP_ENV'] = $original[$key];
                    }
                }
            }
        };

        $production = $under('production');
        $local = $under('local');

        foreach (['facebook', 'instagram', 'threads'] as $platform) {
            $this->assertTrue(
                $production['platforms'][$platform]['promo_only'],
                "{$platform} must be promotional-only in production until App Review lands",
            );
            $this->assertTrue(
                $production['platforms'][$platform]['enabled'],
                "{$platform} must stay enabled in production so promotional customers can connect it",
            );
            $this->assertFalse(
                $local['platforms'][$platform]['promo_only'],
                "{$platform} must stay open outside production for development",
            );
        }

        $this->assertArrayNotHasKey('promo_only', $production['platforms']['x']);
    }
}
