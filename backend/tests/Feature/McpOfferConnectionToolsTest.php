<?php

namespace Tests\Feature;

use App\Models\BeehiivConnection;
use App\Models\FeatureRequest;
use App\Models\Offer;
use App\Models\SocialAccount;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MCP tools for the remaining sidebar areas: Monetization/Offers, Analytics,
 * Connections, and Feature requests. These tools reuse the existing
 * controllers, so behavior (plan limits, ownership, validation) is identical
 * to the REST API the frontend uses.
 */
class McpOfferConnectionToolsTest extends TestCase
{
    use RefreshDatabase;

    private function mcpKey(User $user): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');
    }

    private function callTool(string $key, string $tool, array $arguments = []): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Accept' => 'application/json',
        ])->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    private function toolJson(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse(
            $response->json('result.isError') ?? false,
            'Tool returned error: ' . json_encode($response->json('result'))
        );

        return json_decode($response->json('result.content.0.text'), true);
    }

    private function assertToolError(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
    }

    public function test_new_tools_are_listed(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $names = collect($this->withHeaders(['Authorization' => 'Bearer ' . $key])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50]])
            ->json('result.tools'))->pluck('name');

        foreach ([
            'list_offers', 'create_offer', 'get_offer', 'update_offer', 'delete_offer',
            'create_tracking_link', 'get_offer_stats', 'get_stats_timeseries',
            'disconnect_account', 'get_connect_url', 'create_feature_request',
        ] as $tool) {
            $this->assertContains($tool, $names, "Missing tool {$tool}");
        }
    }

    // ── Offers ──────────────────────────────────────────────────────────────

    public function test_create_offer_and_get_offer(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $data = $this->toolJson($this->callTool($key, 'create_offer', [
            'name' => 'Summer promo',
            'offer_url' => 'https://example.com/summer',
            'goals' => [
                ['event_type' => 'conversion', 'conversion_url' => '/thanks', 'conversion_value' => 50],
            ],
        ]));

        $this->assertArrayHasKey('id', $data);
        $offer = Offer::find($data['id']);
        $this->assertSame($user->id, $offer->user_id);
        $this->assertSame('https://example.com/summer', $offer->offer_url);

        $fetched = $this->toolJson($this->callTool($key, 'get_offer', ['id' => $data['id']]));
        $this->assertSame('Summer promo', $fetched['name']);
    }

    public function test_list_offers_caps_how_much_it_returns(): void
    {
        // Anthropic Directory Policy 5B: MCP servers "must be frugal with their
        // use of tokens". The web app still receives every offer; only the tool
        // trims the list, the same way list_posts does.
        $user = User::factory()->create();
        foreach (range(1, 4) as $i) {
            $user->offers()->create(['name' => "Offer {$i}", 'offer_url' => "https://example.com/{$i}"]);
        }

        $key = $this->mcpKey($user);

        $this->assertCount(4, $this->toolJson($this->callTool($key, 'list_offers'))['data']);
        $this->assertCount(2, $this->toolJson($this->callTool($key, 'list_offers', ['limit' => 2]))['data']);
        $this->assertToolError($this->callTool($key, 'list_offers', ['limit' => 500]));
    }

    public function test_plan_limit_error_explains_the_limit_without_promoting_an_upgrade(): void
    {
        // OpenAI: plugins "must not display subscription plans, initiate new
        // subscriptions, or promote upgrades", but they "may explain that a
        // certain feature is not available with the user's current plan".
        // The web app keeps its own wording; only the MCP reply is reworded.
        $this->mock(\App\Http\Controllers\TrackingEventController::class, fn ($mock) => $mock
            ->shouldReceive('store')
            ->andReturn(response()->json([
                'success' => false,
                'message' => "You've reached your plan's limit of 1 offer(s). Delete an existing offer or upgrade your plan to add more.",
            ], 422)));

        $key = $this->mcpKey(User::factory()->create());
        $response = $this->callTool($key, 'create_offer', ['offer_url' => 'https://example.com/promo']);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('allows 1 offer', $text);
        $this->assertStringContainsString('Delete an existing offer', $text);
        $this->assertStringNotContainsStringIgnoringCase('upgrade', $text);
    }

    public function test_offers_are_scoped_to_the_key_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $other->offers()->create(['offer_url' => 'https://example.com/other']);

        $key = $this->mcpKey($user);

        $this->assertToolError($this->callTool($key, 'get_offer', ['id' => $foreign->id]));
        $this->assertToolError($this->callTool($key, 'delete_offer', ['id' => $foreign->id]));
    }

    public function test_update_and_delete_offer(): void
    {
        $user = User::factory()->create();
        $offer = $user->offers()->create(['name' => 'Old', 'offer_url' => 'https://example.com/x']);

        $key = $this->mcpKey($user);

        $this->toolJson($this->callTool($key, 'update_offer', ['id' => $offer->id, 'name' => 'New']));
        $this->assertSame('New', $offer->fresh()->name);

        $this->toolJson($this->callTool($key, 'delete_offer', ['id' => $offer->id]));
        $this->assertNull(Offer::find($offer->id));
    }

    public function test_create_tracking_link_for_own_offer(): void
    {
        $user = User::factory()->create();
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/x']);

        $key = $this->mcpKey($user);

        $this->toolJson($this->callTool($key, 'create_tracking_link', [
            'tracking_event_id' => $offer->id,
            'placement' => 'website',
            'name' => 'Homepage link',
        ]));

        $this->assertSame(1, TrackingLink::where('tracking_event_id', $offer->id)->count());

        // Foreign offers are rejected by the controller's ownership validation.
        $other = User::factory()->create();
        $foreign = $other->offers()->create(['offer_url' => 'https://example.com/o']);
        $this->assertToolError($this->callTool($key, 'create_tracking_link', [
            'tracking_event_id' => $foreign->id,
        ]));
    }

    public function test_offer_tools_return_the_full_tracked_link_url(): void
    {
        // Without the URL the AI can only show a parameter id; the user needs
        // the link they paste into a video description or bio.
        $user = User::factory()->create();
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/workshop']);
        $withQuery = $user->offers()->create(['offer_url' => 'https://example.com/page?ref=yt']);
        $key = $this->mcpKey($user);

        $link = $this->toolJson($this->callTool($key, 'create_tracking_link', [
            'tracking_event_id' => $offer->id,
            'placement' => 'x',
        ]));
        $this->assertSame('https://example.com/workshop?trk=' . $link['parameter_id'], $link['url']);

        $other = $this->toolJson($this->callTool($key, 'create_tracking_link', [
            'tracking_event_id' => $withQuery->id,
            'placement' => 'x',
        ]));
        $this->assertSame('https://example.com/page?ref=yt&trk=' . $other['parameter_id'], $other['url']);

        $fetched = $this->toolJson($this->callTool($key, 'get_offer', ['id' => $offer->id]));
        $this->assertSame($link['url'], $fetched['links'][0]['url']);

        $listed = collect($this->toolJson($this->callTool($key, 'list_offers'))['data'])->keyBy('id');
        $this->assertSame($link['url'], $listed[$offer->id]['links'][0]['url']);
    }

    public function test_create_tracking_link_with_beehiiv_post_id_sets_beehiiv_placement(): void
    {
        // Mirrors the Offer Detail page's Beehiiv-post picker: attaching a post
        // auto-sets placement, the same way youtube_video_id sets "video".
        $user = User::factory()->create();
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/x']);
        $key = $this->mcpKey($user);

        $data = $this->toolJson($this->callTool($key, 'create_tracking_link', [
            'tracking_event_id' => $offer->id,
            'beehiiv_post_id' => 'post_abc123',
        ]));

        $link = TrackingLink::findOrFail($data['id']);
        $this->assertSame('beehiiv', $link->placement);
        $this->assertSame('post_abc123', $link->beehiiv_post_id);
    }

    // ── Analytics ───────────────────────────────────────────────────────────

    public function test_get_offer_stats_returns_aggregates(): void
    {
        $user = User::factory()->create();
        $user->offers()->create(['offer_url' => 'https://example.com/x']);

        $key = $this->mcpKey($user);
        $data = $this->toolJson($this->callTool($key, 'get_offer_stats', []));

        foreach (['views', 'clicks', 'callsBooked', 'emailSignups', 'sales', 'eventCount'] as $field) {
            $this->assertArrayHasKey($field, $data['data'] ?? $data, "Missing stats field {$field}");
        }
    }

    public function test_get_stats_timeseries_returns_daily_buckets(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $data = $this->toolJson($this->callTool($key, 'get_stats_timeseries', [
            'from' => now()->subDays(6)->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $this->assertNotEmpty($data);
    }

    // ── Connections ─────────────────────────────────────────────────────────

    public function test_disconnect_account_removes_social_account(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => 'x-1',
            'name' => 'My X',
            'status' => 'connected',
        ]);

        $key = $this->mcpKey($user);
        $this->toolJson($this->callTool($key, 'disconnect_account', ['platform' => 'x']));

        $this->assertSame(0, SocialAccount::where('user_id', $user->id)->count());
    }

    public function test_disconnect_account_errors_when_nothing_connected(): void
    {
        $key = $this->mcpKey(User::factory()->create());
        $this->assertToolError($this->callTool($key, 'disconnect_account', ['platform' => 'x']));
    }

    public function test_list_connected_accounts_includes_beehiiv(): void
    {
        // Beehiiv is a separate connection type (its own model, API-key based
        // instead of OAuth) bolted on after these tools were written — it must
        // show up here the same way the other platforms do.
        $user = User::factory()->create();
        BeehiivConnection::create([
            'user_id' => $user->id,
            'api_key' => 'test-key',
            'publication_id' => 'pub_1',
            'publication_name' => 'My Newsletter',
            'status' => BeehiivConnection::STATUS_CONNECTED,
        ]);

        $key = $this->mcpKey($user);
        $data = $this->toolJson($this->callTool($key, 'list_connected_accounts', []));

        $beehiiv = collect($data['accounts'])->firstWhere('platform', 'beehiiv');
        $this->assertNotNull($beehiiv, 'Beehiiv missing from list_connected_accounts');
        $this->assertSame('My Newsletter', $beehiiv['account_name']);
        $this->assertSame('connected', $beehiiv['status']);
    }

    public function test_disconnect_account_removes_beehiiv_connection(): void
    {
        $user = User::factory()->create();
        BeehiivConnection::create([
            'user_id' => $user->id,
            'api_key' => 'test-key',
            'publication_id' => 'pub_1',
            'status' => BeehiivConnection::STATUS_CONNECTED,
        ]);

        $key = $this->mcpKey($user);
        $this->toolJson($this->callTool($key, 'disconnect_account', ['platform' => 'beehiiv']));

        $this->assertNull($user->beehiivConnection()->first());
    }

    public function test_get_connect_url_returns_the_connections_page(): void
    {
        // The FE completes OAuth via its own popup flow, so the tool points
        // the user at the Connections page instead of minting an OAuth URL
        // whose redirect the FE cannot complete.
        config([
            'mcp.frontend_url' => 'https://app.viewsmax.test/',
            'social.platforms.youtube.client_id' => 'google-client',
            'social.platforms.youtube.client_secret' => 'google-secret',
        ]);

        $key = $this->mcpKey(User::factory()->create());
        $data = $this->toolJson($this->callTool($key, 'get_connect_url', ['platform' => 'youtube']));

        $this->assertSame('https://app.viewsmax.test/dashboard/connections', $data['connect_page_url']);
        $this->assertSame('youtube', $data['platform']);
        $this->assertNotEmpty($data['instructions']);
    }

    public function test_get_connect_url_refuses_a_platform_the_connections_page_shows_as_coming_soon(): void
    {
        // Without app credentials the Connections page disables the platform's
        // Connect button, so pointing the user there would be a dead end.
        config([
            'social.platforms.tiktok.client_id' => null,
            'social.platforms.tiktok.client_secret' => null,
            'social.platforms.youtube.client_id' => 'google-client',
            'social.platforms.youtube.client_secret' => 'google-secret',
        ]);

        $key = $this->mcpKey(User::factory()->create());
        $response = $this->callTool($key, 'get_connect_url', ['platform' => 'tiktok']);

        $this->assertToolError($response);
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString("TikTok isn't available to connect on ViewsMax yet", $text);
        $this->assertStringContainsString('youtube', $text);
        $this->assertStringNotContainsString('tiktok,', $text);
    }

    public function test_get_connect_url_only_offers_platforms_you_can_post_to(): void
    {
        // Google Business can be connected in the backend, but the Connections
        // page doesn't show it and nothing can post to it, so it's not offered.
        foreach (['google_business', 'tiktok'] as $platform) {
            config([
                "social.platforms.{$platform}.client_id" => "{$platform}-client",
                "social.platforms.{$platform}.client_secret" => "{$platform}-secret",
            ]);
        }

        $key = $this->mcpKey(User::factory()->create());
        $response = $this->callTool($key, 'get_connect_url', ['platform' => 'google_business']);

        $this->assertToolError($response);
        $this->assertStringContainsString("isn't available to connect on ViewsMax yet", $response->json('result.content.0.text'));
        $this->assertStringNotContainsString('google_business', $response->json('result.content.0.text'));
        $this->assertStringContainsString('tiktok', $response->json('result.content.0.text'));

        $unknown = $this->callTool($key, 'get_connect_url', ['platform' => 'snapchat']);
        $this->assertToolError($unknown);
        $this->assertStringNotContainsString('google_business', $unknown->json('result.content.0.text'));
    }

    public function test_connected_accounts_say_which_platforms_are_supported(): void
    {
        // Seeing no Facebook account, ChatGPT told the user to "connect
        // Facebook first", which isn't possible. The reply names what is.
        config([
            'social.platforms.x.client_id' => 'x-client',
            'social.platforms.x.client_secret' => 'x-secret',
            'social.platforms.facebook.client_id' => null,
            'social.platforms.facebook.client_secret' => null,
        ]);

        $key = $this->mcpKey(User::factory()->create());
        $data = $this->toolJson($this->callTool($key, 'list_connected_accounts'));

        $this->assertContains('x', $data['supported_platforms']);
        $this->assertNotContains('facebook', $data['supported_platforms']);
        $this->assertStringContainsString("Other platforms can't be connected", $data['note']);
    }

    public function test_feature_requests_are_not_for_plans_or_billing(): void
    {
        // Review test N2: "Upgrade my plan" must not trigger a tool call.
        $key = $this->mcpKey(User::factory()->create());
        $tools = collect($this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50]])
            ->json('result.tools'))->keyBy('name');

        $this->assertStringContainsString(
            "Don't use this for plan, billing, or purchase requests",
            $tools['create_feature_request']['description']
        );
    }

    public function test_get_connect_url_rejects_unknown_platform(): void
    {
        $key = $this->mcpKey(User::factory()->create());
        $this->assertToolError($this->callTool($key, 'get_connect_url', ['platform' => 'myspace']));
    }

    // ── Feature requests ────────────────────────────────────────────────────

    public function test_create_feature_request(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $this->toolJson($this->callTool($key, 'create_feature_request', [
            'title' => 'MCP is great',
            'description' => 'Please add more tools.',
        ]));

        $this->assertSame(1, FeatureRequest::where('user_id', $user->id)->count());
    }
}
