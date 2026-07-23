<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MCP server foundation + Post tools, speaking real JSON-RPC over /api/mcp.
 * Auth is an MCP API key (Sanctum token with the `mcp` ability) — see ApiKeyTest.
 */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private function mcpKey(User $user): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');
    }

    /** Mint an MCP key with explicit abilities (scopes), bypassing rotate(). */
    private function mcpKeyWith(User $user, array $abilities): string
    {
        $plain = 'vmx_' . $user->generateTokenString();
        $user->tokens()->create([
            'name' => 'mcp',
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
        ]);

        return $plain;
    }

    private function rpc(string $key, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Accept' => 'application/json',
        ])->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);
    }

    private function callTool(string $key, string $tool, array $arguments = []): TestResponse
    {
        return $this->rpc($key, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    /** Decode the JSON payload a tool returned via ToolResult::json(). */
    private function toolJson(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse(
            $response->json('result.isError') ?? false,
            'Tool returned error: ' . json_encode($response->json('result'))
        );

        return json_decode($response->json('result.content.0.text'), true);
    }

    private function assertToolError(TestResponse $response, string $needle): void
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsStringIgnoringCase($needle, $response->json('result.content.0.text'));
    }

    // ── Auth ────────────────────────────────────────────────────────────────

    public function test_mcp_requires_an_api_key(): void
    {
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();
    }

    public function test_login_tokens_are_rejected_on_mcp(): void
    {
        $user = User::factory()->create();
        $login = $user->createToken('mobile-app')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();
    }

    public function test_key_in_the_url_path_is_no_longer_accepted(): void
    {
        // The key-in-URL mode was removed as a security liability: secrets in
        // URLs leak into server/proxy access logs, browser history, and
        // Referer headers. Only the Bearer header and OAuth remain, so the
        // /mcp/{key} route is gone entirely — a valid key in the path 404s.
        $key = $this->mcpKey(User::factory()->create());

        $this->withoutHeader('Authorization')->postJson('/api/mcp/' . $key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ])->assertNotFound();
    }

    public function test_read_only_key_cannot_see_or_call_write_tools(): void
    {
        // A read-only credential (mcp:read, no mcp:write) sees only read tools;
        // write tools are unregistered for the request, so they neither appear
        // in tools/list nor resolve on tools/call.
        $user = User::factory()->create();
        $key = $this->mcpKeyWith($user, ['mcp:read']);

        $names = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))
            ->pluck('name')->all();
        $this->assertContains('list_posts', $names);
        $this->assertNotContains('create_post', $names);

        $this->callTool($key, 'create_post', ['caption' => 'Hi', 'platforms' => ['x']])
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        // Read tools still work with a read-only key.
        $this->callTool($key, 'list_posts')->assertOk()->assertJsonPath('result.isError', false);
    }

    public function test_full_access_key_can_see_and_call_write_tools(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKeyWith($user, ['mcp:read', 'mcp:write']);

        $names = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))
            ->pluck('name')->all();
        $this->assertContains('list_posts', $names);
        $this->assertContains('create_post', $names);
    }

    public function test_legacy_mcp_ability_grants_full_access(): void
    {
        // Keys minted before read/write scopes carry the single `mcp` ability;
        // it must keep granting both read and write so they don't break.
        $user = User::factory()->create();
        $key = $this->mcpKeyWith($user, ['mcp']);

        $names = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))
            ->pluck('name')->all();
        $this->assertContains('create_post', $names);
        $this->assertContains('list_posts', $names);
    }

    public function test_initialize_counter_offers_unknown_protocol_versions(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        // Per the MCP spec, a version the server doesn't know (e.g. Claude
        // Code's 2025-11-25, or anything future) gets answered with the
        // newest version the server does support — never an error.
        foreach (['2025-11-25', '2099-01-01'] as $requested) {
            $this->rpc($key, 'initialize', [
                'protocolVersion' => $requested,
                'capabilities' => [],
                'clientInfo' => ['name' => 'claude-code', 'version' => '1.0'],
            ])->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18');
        }

        $this->rpc($key, 'initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0'],
        ])->assertOk()->assertJsonPath('result.protocolVersion', '2024-11-05');
    }

    public function test_mcp_key_can_list_tools(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $names = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))
            ->pluck('name');

        foreach ([
            'list_connected_accounts', 'upload_media', 'create_post',
            'list_posts', 'get_post', 'update_post', 'delete_post',
        ] as $tool) {
            $this->assertContains($tool, $names, "Missing tool {$tool}");
        }
    }

    public function test_create_post_has_its_own_hourly_limit(): void
    {
        config(['mcp.rate_limits.create_post_per_hour' => 1]);
        $key = $this->mcpKey(User::factory()->create());

        $this->toolJson($this->callTool($key, 'create_post', ['caption' => 'One', 'platforms' => ['x']]));
        $this->assertToolError(
            $this->callTool($key, 'create_post', ['caption' => 'Two', 'platforms' => ['x']]),
            'rate limit'
        );
        $this->assertSame(1, Post::count());
    }

    public function test_upload_media_has_its_own_hourly_limit(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public', 'mcp.rate_limits.upload_media_per_hour' => 1]);
        Http::fake(['example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

        $key = $this->mcpKey(User::factory()->create());

        $this->toolJson($this->callTool($key, 'upload_media', ['url' => 'https://example.com/a.png']));
        $this->assertToolError(
            $this->callTool($key, 'upload_media', ['url' => 'https://example.com/b.png']),
            'rate limit'
        );
    }

    public function test_mcp_is_rate_limited_per_token(): void
    {
        config(['mcp.rate_limits.per_minute' => 2]);
        $key = $this->mcpKey(User::factory()->create());

        $this->rpc($key, 'tools/list')->assertOk();
        $this->rpc($key, 'tools/list')->assertOk();
        $this->rpc($key, 'tools/list')->assertStatus(429);
    }

    // ── create_post ─────────────────────────────────────────────────────────

    public function test_create_post_and_update_post_descriptions_list_platform_char_limits(): void
    {
        // The AI has no other way to know platform caption limits (e.g. X's
        // 280) before calling the tool, so they must be in the description
        // it reads up front — a response-time warning is too late, since the
        // draft is already saved by the time the AI sees it.
        $key = $this->mcpKey(User::factory()->create());
        $tools = collect($this->rpc($key, 'tools/list', ['per_page' => 50])->assertOk()->json('result.tools'))
            ->keyBy('name');

        foreach (['tiktok' => 2200, 'youtube' => 5000, 'x' => 280, 'linkedin' => 3000, 'threads' => 500, 'instagram' => 2200] as $platform => $limit) {
            $this->assertStringContainsString(
                "{$platform}: {$limit}",
                $tools['create_post']['description'],
                "create_post description missing {$platform} limit"
            );
            $this->assertStringContainsString(
                "{$platform}: {$limit}",
                $tools['update_post']['description'],
                "update_post description missing {$platform} limit"
            );
        }
    }

    public function test_create_post_advertises_the_tiktok_auto_add_music_option(): void
    {
        // Agents must be able to discover the TikTok slideshow music toggle —
        // and that it defaults off — from the tool description they read up front.
        $key = $this->mcpKey(User::factory()->create());
        $desc = collect($this->rpc($key, 'tools/list', ['per_page' => 50])->assertOk()->json('result.tools'))
            ->keyBy('name')['create_post']['description'];

        $this->assertStringContainsString('auto_add_music', $desc);
        $this->assertStringContainsStringIgnoringCase('default false', $desc);
    }

    public function test_create_post_creates_draft_with_targets(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $data = $this->toolJson($this->callTool($key, 'create_post', [
            'caption' => 'Hello world',
            'platforms' => ['x', 'linkedin'],
        ]));

        $this->assertSame('draft', $data['status']);
        $post = Post::find($data['id']);
        $this->assertNotNull($post);
        $this->assertSame($user->id, $post->user_id);
        $this->assertEqualsCanonicalizing(['x', 'linkedin'], $post->targets->pluck('platform')->all());
        $this->assertSame(['pending'], $post->targets->pluck('status')->unique()->all());
    }

    public function test_create_post_maps_twitter_alias_to_x(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $data = $this->toolJson($this->callTool($key, 'create_post', [
            'caption' => 'Alias test',
            'platforms' => ['twitter'],
        ]));

        $this->assertSame(['x'], Post::find($data['id'])->targets->pluck('platform')->all());
    }

    public function test_create_post_rejects_unsupported_platform(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError(
            $this->callTool($key, 'create_post', ['caption' => 'Nope', 'platforms' => ['pinterest']]),
            'pinterest'
        );
        $this->assertSame(0, Post::count());
    }

    public function test_create_post_enforces_video_rules_for_tiktok_and_youtube(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError(
            $this->callTool($key, 'create_post', [
                'caption' => 'No video',
                'platforms' => ['tiktok'],
                'status' => 'scheduled',
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ]),
            'video'
        );

        $this->assertToolError(
            $this->callTool($key, 'create_post', [
                'caption' => 'No video',
                'platforms' => ['youtube'],
                'status' => 'posted',
            ]),
            'video'
        );
    }

    public function test_create_post_validates_identically_to_the_rest_endpoint(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);
        $login = $user->createToken('mobile-app')->plainTextToken;

        // The media rules live only in PostController; both surfaces must
        // reject this payload with the controller's message.
        $payload = ['caption' => 'Bad media', 'platforms' => ['x'], 'media' => [['type' => 'audio']]];

        $rest = $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/posts', $payload);
        $rest->assertStatus(422);

        $mcp = $this->callTool($key, 'create_post', $payload);
        $this->assertToolError($mcp, 'media.0.type');
        $this->assertStringContainsString('media.0.type', $rest->json('message'));
        $this->assertSame(0, Post::count());
    }

    // ── list / get / update / delete ────────────────────────────────────────

    public function test_list_posts_only_returns_own_posts_and_respects_limit(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->posts()->createMany(array_map(
            fn ($i) => ['caption' => "Mine {$i}", 'status' => Post::STATUS_DRAFT, 'media' => []],
            range(1, 3)
        ));
        $other->posts()->create(['caption' => 'Not mine', 'status' => Post::STATUS_DRAFT, 'media' => []]);

        $key = $this->mcpKey($user);

        $data = $this->toolJson($this->callTool($key, 'list_posts', []));
        $this->assertCount(3, $data['posts']);
        $this->assertStringStartsWith('Mine', $data['posts'][0]['caption']);

        $data = $this->toolJson($this->callTool($key, 'list_posts', ['limit' => 2]));
        $this->assertCount(2, $data['posts']);

        $data = $this->toolJson($this->callTool($key, 'list_posts', ['status' => 'posted']));
        $this->assertCount(0, $data['posts']);
    }

    public function test_get_post_returns_targets_and_hides_other_users_posts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $post = $user->posts()->create(['caption' => 'Mine', 'status' => Post::STATUS_DRAFT, 'media' => []]);
        $post->targets()->create(['platform' => 'x', 'status' => 'pending']);
        $foreign = $other->posts()->create(['caption' => 'Foreign', 'status' => Post::STATUS_DRAFT, 'media' => []]);

        $key = $this->mcpKey($user);

        $data = $this->toolJson($this->callTool($key, 'get_post', ['id' => $post->id]));
        $this->assertSame('Mine', $data['caption']);
        $this->assertSame('x', $data['targets'][0]['platform']);

        $this->assertToolError(
            $this->callTool($key, 'get_post', ['id' => $foreign->id]),
            'not found'
        );
    }

    public function test_update_post_edits_drafts_but_refuses_published_posts(): void
    {
        $user = User::factory()->create();
        $draft = $user->posts()->create(['caption' => 'Old', 'status' => Post::STATUS_DRAFT, 'media' => []]);
        $posted = $user->posts()->create(['caption' => 'Live', 'status' => Post::STATUS_POSTED, 'media' => []]);

        $key = $this->mcpKey($user);

        $data = $this->toolJson($this->callTool($key, 'update_post', [
            'id' => $draft->id,
            'caption' => 'New',
            'platforms' => ['threads'],
        ]));
        $this->assertSame('New', $data['caption']);
        $this->assertSame(['threads'], $draft->fresh()->targets->pluck('platform')->all());

        $this->assertToolError(
            $this->callTool($key, 'update_post', ['id' => $posted->id, 'caption' => 'Nope']),
            'published'
        );
    }

    public function test_delete_post_removes_own_post(): void
    {
        $user = User::factory()->create();
        $post = $user->posts()->create(['caption' => 'Bye', 'status' => Post::STATUS_DRAFT, 'media' => []]);

        $key = $this->mcpKey($user);
        $this->toolJson($this->callTool($key, 'delete_post', ['id' => $post->id]));

        $this->assertNull(Post::find($post->id));
    }

    // ── accounts & media ────────────────────────────────────────────────────

    public function test_list_connected_accounts_merges_both_stores(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => 'x-123',
            'name' => 'My X',
            'status' => 'connected',
        ]);
        Connection::create([
            'user_id' => $user->id,
            'provider' => 'youtube',
            'account_name' => 'My Channel',
            'account_id' => 'yt-123',
            'access_token' => 'tok',
        ]);

        $key = $this->mcpKey($user);
        $data = $this->toolJson($this->callTool($key, 'list_connected_accounts', []));

        $platforms = collect($data['accounts'])->pluck('platform');
        $this->assertContains('x', $platforms);
        $this->assertContains('youtube', $platforms);
    }

    public function test_list_connected_accounts_returns_every_account_with_ids(): void
    {
        $user = User::factory()->create();
        $a = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => 'x-1',
            'name' => 'First X',
            'status' => 'connected',
        ]);
        $b = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => 'x-2',
            'name' => 'Second X',
            'status' => 'connected',
        ]);
        $legacy = Connection::create([
            'user_id' => $user->id,
            'provider' => 'youtube',
            'account_name' => 'My Channel',
            'account_id' => 'yt-123',
            'access_token' => 'tok',
        ]);

        $key = $this->mcpKey($user);
        $data = $this->toolJson($this->callTool($key, 'list_connected_accounts', []));

        // Both X accounts must be visible (no per-platform dedup) with the ids
        // an agent needs to pair with list_brands / account-pinned targets.
        $xAccounts = collect($data['accounts'])->where('platform', 'x');
        $this->assertCount(2, $xAccounts);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $xAccounts->pluck('social_account_id')->all());
        $this->assertSame(['social'], $xAccounts->pluck('store')->unique()->values()->all());

        $yt = collect($data['accounts'])->firstWhere('platform', 'youtube');
        $this->assertSame($legacy->id, $yt['connection_id']);
        $this->assertNull($yt['social_account_id']);
        $this->assertSame('legacy', $yt['store']);
    }

    // ── brands ──────────────────────────────────────────────────────────────

    private function makeBrandWithAccounts(User $user): array
    {
        $x = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => 'x-1',
            'name' => 'Brand X',
            'status' => 'connected',
        ]);
        $expired = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => 'Old LinkedIn',
            'status' => 'needs_reauth',
        ]);
        $yt = Connection::create([
            'user_id' => $user->id,
            'provider' => 'youtube',
            'account_name' => 'My Channel',
            'account_id' => 'yt-1',
            'access_token' => 'tok',
        ]);
        $brand = $user->brands()->create(['name' => 'Acme']);
        $brand->socialAccounts()->sync([$x->id, $expired->id]);
        $brand->connections()->sync([$yt->id]);

        return [$brand, $x, $expired, $yt];
    }

    public function test_list_brands_returns_brands_with_member_accounts(): void
    {
        $user = User::factory()->create();
        [$brand, $x, , $yt] = $this->makeBrandWithAccounts($user);

        $key = $this->mcpKey($user);
        $data = $this->toolJson($this->callTool($key, 'list_brands', []));

        $this->assertCount(1, $data['brands']);
        $this->assertSame($brand->id, $data['brands'][0]['id']);
        $this->assertSame('Acme', $data['brands'][0]['name']);

        $accounts = collect($data['brands'][0]['accounts']);
        $this->assertCount(3, $accounts);
        $this->assertSame($x->id, $accounts->firstWhere('platform', 'x')['social_account_id']);
        $this->assertSame($yt->id, $accounts->firstWhere('platform', 'youtube')['connection_id']);
        $this->assertSame('needs_reauth', $accounts->firstWhere('platform', 'linkedin')['status']);
    }

    public function test_create_post_with_brand_id_expands_to_healthy_targets(): void
    {
        $user = User::factory()->create();
        [$brand, $x] = $this->makeBrandWithAccounts($user);

        $key = $this->mcpKey($user);
        $data = $this->toolJson($this->callTool($key, 'create_post', [
            'caption' => 'brand post',
            'status' => 'draft',
            'brand_id' => $brand->id,
        ]));

        $post = Post::findOrFail($data['id']);
        $this->assertSame($brand->id, $post->brand_id);

        // The healthy X account is pinned; the legacy YouTube connection posts
        // unpinned; the needs_reauth LinkedIn account is skipped entirely.
        $targets = $post->targets->map(fn ($t) => [$t->platform, $t->social_account_id])->all();
        $this->assertEqualsCanonicalizing([['x', $x->id], ['youtube', null]], $targets);
    }

    public function test_create_post_rejects_brand_id_plus_platforms(): void
    {
        $user = User::factory()->create();
        [$brand] = $this->makeBrandWithAccounts($user);

        $key = $this->mcpKey($user);
        $this->assertToolError(
            $this->callTool($key, 'create_post', [
                'caption' => 'both',
                'brand_id' => $brand->id,
                'platforms' => ['x'],
            ]),
            'not both'
        );
    }

    public function test_create_post_with_empty_brand_errors_by_name(): void
    {
        $user = User::factory()->create();
        $brand = $user->brands()->create(['name' => 'Ghost']);

        $key = $this->mcpKey($user);
        $this->assertToolError(
            $this->callTool($key, 'create_post', ['caption' => 'hi', 'brand_id' => $brand->id]),
            'Ghost'
        );
    }

    public function test_create_post_with_foreign_brand_errors(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$foreignBrand] = $this->makeBrandWithAccounts($other);

        $key = $this->mcpKey($user);
        $this->assertToolError(
            $this->callTool($key, 'create_post', ['caption' => 'hi', 'brand_id' => $foreignBrand->id]),
            'not found'
        );
    }

    public function test_upload_media_downloads_and_stores_file(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);
        Http::fake(['example.com/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/png'])]);

        $key = $this->mcpKey(User::factory()->create());

        $data = $this->toolJson($this->callTool($key, 'upload_media', [
            'url' => 'https://example.com/pic.png',
        ]));

        $this->assertSame('image', $data['type']);
        $this->assertNotEmpty($data['url']);
        $this->assertNotEmpty($data['path']);
        Storage::disk('public')->assertExists($data['path']);
    }
}
