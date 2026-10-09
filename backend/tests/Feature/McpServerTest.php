<?php

namespace Tests\Feature;

use App\Http\Controllers\PostController;
use App\Models\Connection;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * MCP server foundation + Post tools, speaking real JSON-RPC over /api/mcp.
 * Auth is an MCP API key (Sanctum token with the `mcp` ability) — see ApiKeyTest.
 */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Post tools only offer platforms with app credentials; give every
        // platform some so tests don't depend on the local .env.
        foreach (array_keys(config('social.platforms')) as $platform) {
            config([
                "social.platforms.{$platform}.client_id" => "{$platform}-client",
                "social.platforms.{$platform}.client_secret" => "{$platform}-secret",
            ]);
        }
    }

    private function mcpKey(User $user): string
    {
        $this->fundCredits($user, 10_000); // MCP tool calls cost credits; keep test users funded

        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');
    }

    /** Mint an MCP key with explicit abilities (scopes), bypassing rotate(). */
    private function mcpKeyWith(User $user, array $abilities): string
    {
        $this->fundCredits($user, 10_000); // MCP tool calls cost credits; keep test users funded

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

    /**
     * Expected safety annotations per tool: [readOnlyHint, destructiveHint, openWorldHint].
     * Definitions follow the Anthropic and OpenAI directory review rules:
     * destructive = can delete, overwrite, revoke access, or publish something
     * that can't be taken back; open-world = reaches the public internet or
     * publishes to public platforms, not just the user's own ViewsMax account.
     */
    private const EXPECTED_ANNOTATIONS = [
        'list_connected_accounts' => [true, false, false],
        'list_brands' => [true, false, false],
        'upload_media' => [false, false, true],
        'create_post' => [false, true, true],
        'list_posts' => [true, false, false],
        'get_post' => [true, false, false],
        'update_post' => [false, true, true],
        'delete_post' => [false, true, false],
        'list_offers' => [true, false, false],
        // 'create_offer' => [false, false, false], // TEMP: hidden from the MCP for now
        'get_offer' => [true, false, false],
        'update_offer' => [false, true, false],
        'delete_offer' => [false, true, false],
        // 'create_tracking_link' => [false, false, false], // TEMP: hidden from the MCP for now
        'get_offer_stats' => [true, false, false],
        'get_stats_timeseries' => [true, false, false],
        'disconnect_account' => [false, true, false],
        'get_connect_url' => [true, false, false],
        // Outlier research. search/fetch scrape public platforms, and the
        // breakdown job pulls the public video's transcript, so those are
        // open-world; saving again replaces tags, which is an overwrite.
        'list_outliers' => [true, false, false],
        'search_outliers' => [false, false, true],
        'get_outlier' => [true, false, false],
        'fetch_outlier' => [false, false, true],
        'get_outlier_breakdown' => [true, false, false],
        'generate_outlier_breakdown' => [false, false, true],
        'list_saved_outliers' => [true, false, false],
        'save_outlier' => [false, true, false],
        'remove_saved_outlier' => [false, true, false],
        // Pulling a creator's recent videos reaches YouTube/TikTok/Instagram
        // but only adds to ViewsMax's outlier database; checking it is a read.
        // 'add_outlier_channel' => [false, false, true], // TEMP: hidden from the MCP for now
        'get_outlier_channel_ingest' => [true, false, false],
    ];

    public function test_every_tool_declares_a_title_and_all_three_safety_hints(): void
    {
        // Both directories reject tools without annotations: Anthropic wants a
        // title plus readOnlyHint/destructiveHint, OpenAI wants explicit
        // readOnlyHint, destructiveHint, and openWorldHint on every tool.
        $key = $this->mcpKeyWith(User::factory()->create(), ['mcp:read', 'mcp:write']);

        $tools = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'));

        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED_ANNOTATIONS), $tools->pluck('name')->all());

        foreach ($tools as $tool) {
            $annotations = $tool['annotations'] ?? [];
            $this->assertIsString($annotations['title'] ?? null, "{$tool['name']} has no title");
            $this->assertNotSame('', trim($annotations['title']), "{$tool['name']} has an empty title");

            foreach (['readOnlyHint', 'destructiveHint', 'openWorldHint'] as $hint) {
                $this->assertIsBool($annotations[$hint] ?? null, "{$tool['name']} is missing {$hint}");
            }
        }
    }

    public function test_tool_safety_hints_match_tool_behavior(): void
    {
        $key = $this->mcpKeyWith(User::factory()->create(), ['mcp:read', 'mcp:write']);

        $tools = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        foreach (self::EXPECTED_ANNOTATIONS as $name => [$readOnly, $destructive, $openWorld]) {
            $annotations = $tools[$name]['annotations'] ?? [];
            $this->assertSame($readOnly, $annotations['readOnlyHint'] ?? null, "{$name} readOnlyHint");
            $this->assertSame($destructive, $annotations['destructiveHint'] ?? null, "{$name} destructiveHint");
            $this->assertSame($openWorld, $annotations['openWorldHint'] ?? null, "{$name} openWorldHint");
        }
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

    public function test_ai_is_told_the_user_must_choose_tiktok_and_youtube_privacy(): void
    {
        // TikTok: "Users must manually select the privacy status ... there
        // should be no default value." A client without our skills once
        // picked PUBLIC_TO_EVERYONE on its own, so every surface says so.
        $key = $this->mcpKey(User::factory()->create());
        $tools = collect($this->rpc($key, 'tools/list', ['per_page' => 50])->assertOk()->json('result.tools'))
            ->keyBy('name');
        $instructions = $this->rpc($key, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ])->json('result.instructions');

        // YouTube: API clients "must clearly identify any content visibility
        // settings that will be set". Without one, the upload goes public.
        foreach (['Ask the user which TikTok privacy_level to use', 'Ask the user which YouTube privacy_status to use'] as $rule) {
            $this->assertStringContainsString($rule, $tools['create_post']['description']);
            $this->assertStringContainsString($rule, $tools['update_post']['description']);
            $this->assertStringContainsString($rule, $instructions);
        }
    }

    public function test_post_results_say_plainly_whether_publishing_finished(): void
    {
        // The post's own status is "posted" as soon as publishing starts, so
        // ChatGPT reported a failed post as published and a published one as
        // not published. Each reply now carries a plain overall result.
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $make = function (string $status, array $targets) use ($user) {
            $post = $user->posts()->create(['caption' => 'Result test', 'status' => $status, 'media' => []]);
            foreach ($targets as $platform => $targetStatus) {
                $post->targets()->create(['platform' => $platform, 'status' => $targetStatus]);
            }

            return $post;
        };
        $result = fn ($post) => $this->toolJson($this->callTool($key, 'get_post', ['id' => $post->id]))['publish_result'];

        $inProgress = $result($make(Post::STATUS_POSTED, ['x' => 'published', 'bluesky' => 'publishing']));
        $this->assertSame('in_progress', $inProgress['state']);
        $this->assertStringContainsString('bluesky', $inProgress['message']);
        $this->assertStringContainsString("don't report", $inProgress['message']);

        $this->assertSame('published', $result($make(Post::STATUS_POSTED, ['x' => 'published', 'linkedin' => 'published']))['state']);
        $this->assertSame('failed', $result($make(Post::STATUS_POSTED, ['bluesky' => 'failed']))['state']);

        $partly = $result($make(Post::STATUS_POSTED, ['x' => 'published', 'bluesky' => 'failed']));
        $this->assertSame('partly_failed', $partly['state']);
        $this->assertStringContainsString('bluesky', $partly['message']);

        $this->assertSame('draft', $result($make(Post::STATUS_DRAFT, ['linkedin' => 'pending']))['state']);
        $this->assertSame('scheduled', $result($make(Post::STATUS_SCHEDULED, ['threads' => 'pending']))['state']);
    }

    public function test_scheduling_in_the_past_is_refused_with_the_current_time(): void
    {
        // ChatGPT has no clock: it scheduled "in 2 minutes" for a minute ago,
        // the scheduler published it at once, and ChatGPT told the user it
        // hadn't scheduled anything. The error gives the AI the real time.
        $this->travelTo(now()->setDate(2030, 1, 1)->setTime(12, 0, 0));
        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError(
            $this->callTool($key, 'create_post', [
                'caption' => 'Too late',
                'platforms' => ['x'],
                'status' => 'scheduled',
                'scheduled_at' => '2030-01-01T11:59:00Z',
            ]),
            'The current time is 2030-01-01T12:00:00Z'
        );
        $this->assertSame(0, Post::count());

        $draft = $this->toolJson($this->callTool($key, 'create_post', ['caption' => 'Later', 'platforms' => ['x']]));
        $this->assertToolError(
            $this->callTool($key, 'update_post', [
                'id' => $draft['id'],
                'status' => 'scheduled',
                'scheduled_at' => '2030-01-01T11:00:00+00:00',
            ]),
            'in the past'
        );
        $this->assertSame(Post::STATUS_DRAFT, Post::find($draft['id'])->status);
    }

    public function test_scheduled_times_keep_their_timezone(): void
    {
        // Assigning "11:55+03:00" to the model stored 11:55 as if it were UTC,
        // publishing three hours late. AI clients send local offsets, as our
        // publish-post skill tells them to.
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $created = $this->toolJson($this->callTool($key, 'create_post', [
            'caption' => 'Timezone test',
            'platforms' => ['x'],
            'status' => 'scheduled',
            'scheduled_at' => '2030-01-15T11:55:00+03:00',
        ]));
        $this->assertSame('2030-01-15 08:55:00', Post::find($created['id'])->getRawOriginal('scheduled_at'));

        $this->toolJson($this->callTool($key, 'update_post', [
            'id' => $created['id'],
            'scheduled_at' => '2030-01-16T09:00:00-04:00',
        ]));
        $this->assertSame('2030-01-16 13:00:00', Post::find($created['id'])->getRawOriginal('scheduled_at'));
    }

    public function test_server_instructions_name_only_platforms_that_are_set_up(): void
    {
        config([
            'social.platforms.facebook.client_id' => null,
            'social.platforms.facebook.client_secret' => null,
        ]);

        $key = $this->mcpKey(User::factory()->create());
        $instructions = $this->rpc($key, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ])->assertOk()->json('result.instructions');

        $this->assertStringContainsString('bluesky', $instructions);
        $this->assertStringNotContainsStringIgnoringCase('facebook', $instructions);
        // Without this, ChatGPT searched the web for "ViewsMax pricing" and
        // sent the user to a different company's site.
        $this->assertStringContainsString(
            'Plans and billing are managed in the ViewsMax web app (' . config('mcp.frontend_url') . ')',
            $instructions
        );
        // search_outliers uses the YouTube Data API, so don't call it scraping.
        $this->assertStringNotContainsStringIgnoringCase('scrape', $instructions);
    }

    public function test_server_instructions_start_with_outliers(): void
    {
        // A fresh user has nothing connected, so posting is a dead end on day
        // one; outliers work immediately. The instructions must lead with them.
        $key = $this->mcpKey(User::factory()->create());
        $instructions = $this->rpc($key, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ])->assertOk()->json('result.instructions');

        $this->assertStringContainsString('Start here', $instructions);
        $this->assertStringContainsString('list_outliers with no arguments', $instructions);
        $this->assertLessThan(
            strpos($instructions, 'create_post'),
            strpos($instructions, 'list_outliers'),
            'outlier research should be introduced before posting'
        );
        $this->assertStringContainsString('generate_outlier_breakdown', $instructions);
    }

    public function test_notifications_are_accepted_with_202_and_no_body(): void
    {
        // Streamable HTTP: for a notification "the server MUST return HTTP
        // status code 202 Accepted with no body". Codex's client treated our
        // empty 200 as a broken connection and reconnected in a loop.
        $key = $this->mcpKey(User::factory()->create());

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $response->assertStatus(202);
        $this->assertSame('', $response->getContent());

        // Requests with an id still get their JSON-RPC reply.
        $this->rpc($key, 'ping')->assertOk()->assertJsonPath('id', 1);
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

    public function test_hourly_limit_fails_open_when_the_cache_backend_breaks(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);
        Http::fake(['example.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);
        $key = $this->mcpKey(User::factory()->create());

        // Reproduce the production failure: the file cache store throwing from
        // fopen() for the tool's bucket only. Other keys (the per-token
        // per-minute throttle middleware) keep working through the real limiter.
        $real = $this->app->make(\Illuminate\Cache\RateLimiter::class);
        $broken = Mockery::mock($real);
        $broken->shouldReceive('tooManyAttempts')->andReturnUsing(function (string $bucket, int $max) use ($real) {
            if (str_starts_with($bucket, 'mcp-tool:')) {
                throw new \ErrorException('fopen(storage/framework/cache/data/95/0b/950b76f5): Failed to open stream: No such file or directory');
            }

            return $real->tooManyAttempts($bucket, $max);
        });
        RateLimiter::swap($broken);
        Log::spy();
        Log::shouldReceive('channel')->andReturn(Log::getFacadeRoot()); // the credits log goes through channel()

        $json = $this->toolJson($this->callTool($key, 'upload_media', ['url' => 'https://example.com/a.png']));

        $this->assertArrayHasKey('url', $json);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'MCP hourly limit unavailable; allowing call'
                && $context['tool'] === 'upload_media'
                && $context['exception'] instanceof \ErrorException)
            ->once();
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

    public function test_post_tools_only_offer_platforms_that_are_set_up(): void
    {
        // A platform without app credentials shows "Coming soon" on the
        // Connections page, so the AI must not list it as somewhere to post.
        config([
            'social.platforms.facebook.client_id' => null,
            'social.platforms.facebook.client_secret' => null,
        ]);

        $key = $this->mcpKey(User::factory()->create());
        $tools = collect($this->rpc($key, 'tools/list', ['per_page' => 50])->assertOk()->json('result.tools'))
            ->keyBy('name');

        $this->assertStringContainsString('tiktok', $tools['create_post']['description']);
        $this->assertStringNotContainsStringIgnoringCase('facebook', $tools['create_post']['description']);
        $this->assertStringNotContainsStringIgnoringCase('facebook', $tools['create_post']['inputSchema']['properties']['platforms']['description']);
        $this->assertStringNotContainsStringIgnoringCase('facebook', $tools['list_connected_accounts']['description']);
    }

    public function test_tool_schemas_mark_the_inputs_each_tool_cannot_work_without_as_required(): void
    {
        // Without "required" in the schema, clients show every input as
        // optional and the AI can call e.g. update_post with no id.
        $expected = [
            // TEMP: add_outlier_channel, create_offer and create_tracking_link are hidden from the MCP for now
            'delete_offer' => ['id'],
            'delete_post' => ['id'],
            'disconnect_account' => ['platform'],
            'fetch_outlier' => ['platform', 'url'],
            'generate_outlier_breakdown' => ['platform', 'video_id'],
            'get_connect_url' => ['platform'],
            'get_offer' => ['id'],
            'get_outlier' => ['platform', 'video_id'],
            'get_outlier_channel_ingest' => ['ingest_id'],
            'get_outlier_breakdown' => ['platform', 'video_id'],
            'get_post' => ['id'],
            'remove_saved_outlier' => ['id'],
            'save_outlier' => ['platform', 'video_id'],
            'search_outliers' => ['term'],
            'update_offer' => ['id'],
            'update_post' => ['id'],
            'upload_media' => ['url'],
        ];

        $key = $this->mcpKey(User::factory()->create());
        $tools = collect($this->rpc($key, 'tools/list', ['per_page' => 50])->assertOk()->json('result.tools'));

        foreach ($tools as $tool) {
            $required = $tool['inputSchema']['required'] ?? [];
            sort($required);

            $this->assertSame($expected[$tool['name']] ?? [], $required, "{$tool['name']} required inputs");
        }
    }

    public function test_create_post_and_update_post_refuse_a_platform_that_is_not_set_up(): void
    {
        config([
            'social.platforms.facebook.client_id' => null,
            'social.platforms.facebook.client_secret' => null,
        ]);

        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $this->assertToolError(
            $this->callTool($key, 'create_post', ['caption' => 'Hi', 'platforms' => ['facebook']]),
            "Facebook isn't available to connect on ViewsMax yet"
        );
        $this->assertSame(0, Post::count());

        $draft = $this->toolJson($this->callTool($key, 'create_post', ['caption' => 'Hi', 'platforms' => ['x']]));

        $this->assertToolError(
            $this->callTool($key, 'update_post', ['id' => $draft['id'], 'platforms' => ['facebook']]),
            "Facebook isn't available to connect on ViewsMax yet"
        );
        $this->assertSame(['x'], Post::find($draft['id'])->targets->pluck('platform')->all());
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

    public function test_youtube_title_is_required_to_publish_through_mcp(): void
    {
        $key = $this->mcpKey(User::factory()->create());
        $video = [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4', 'path' => 'posts/1/clip.mp4']];

        $this->assertStringContainsString(
            'YouTube also requires a title',
            collect($this->rpc($key, 'tools/list')->json('result.tools'))->firstWhere('name', 'create_post')['description']
        );

        $this->assertToolError(
            $this->callTool($key, 'create_post', ['platforms' => ['youtube'], 'media' => $video, 'status' => 'posted']),
            'YouTube requires a title'
        );
        $this->assertSame(0, Post::count());

        $draft = $this->toolJson($this->callTool($key, 'create_post', ['platforms' => ['youtube'], 'media' => $video]));
        $this->assertToolError(
            $this->callTool($key, 'update_post', ['id' => $draft['id'], 'status' => 'posted']),
            'YouTube requires a title'
        );
        $this->assertSame(Post::STATUS_DRAFT, Post::find($draft['id'])->status);
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
        // The reply says there is more, so the AI doesn't report two posts as
        // all the user has, and the next page returns the rest.
        $this->assertSame([1, 3, true], [$data['page'], $data['total'], $data['has_more']]);

        $data = $this->toolJson($this->callTool($key, 'list_posts', ['limit' => 2, 'page' => 2]));
        $this->assertCount(1, $data['posts']);
        $this->assertFalse($data['has_more']);

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

    public function test_list_connected_accounts_never_returns_an_email_as_username(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => 'Jane Doe',
            'username' => 'jane@example.com', // what LinkedInProvider stored before it stopped
            'status' => 'connected',
        ]);

        $data = $this->toolJson($this->callTool($this->mcpKey($user), 'list_connected_accounts', []));

        $row = collect($data['accounts'])->firstWhere('platform', 'linkedin');
        $this->assertNull($row['username']);
        $this->assertSame('Jane Doe', $row['account_name']);
        $this->assertStringNotContainsString('jane@example.com', json_encode($data));
    }

    public function test_list_connected_accounts_account_name_never_falls_back_to_an_email(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => null,
            'username' => 'jane@example.com',
            'status' => 'connected',
        ]);

        $data = $this->toolJson($this->callTool($this->mcpKey($user), 'list_connected_accounts', []));

        $row = collect($data['accounts'])->firstWhere('platform', 'linkedin');
        $this->assertNull($row['account_name']);
        $this->assertNull($row['username']);
        $this->assertStringNotContainsString('jane@example.com', json_encode($data));
    }

    public function test_list_connected_accounts_keeps_handles_with_a_leading_at(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => 'UC-1',
            'name' => 'ViewsMax',
            'username' => '@viewsmax', // YouTube customUrl
            'status' => 'connected',
        ]);

        $data = $this->toolJson($this->callTool($this->mcpKey($user), 'list_connected_accounts', []));

        $this->assertSame('@viewsmax', collect($data['accounts'])->firstWhere('platform', 'youtube')['username']);
    }

    public function test_list_connected_accounts_folds_a_mirrored_connection_into_its_social_row(): void
    {
        $user = User::factory()->create();
        // The YouTube/TikTok OAuth flows write a Connection and mirror it into
        // SocialAccount — one account, two rows in the database.
        $ytSocial = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => 'UC-1',
            'name' => 'My Channel',
            'status' => 'connected',
        ]);
        $ytConn = Connection::create([
            'user_id' => $user->id,
            'provider' => 'youtube',
            'account_name' => 'My Channel',
            'account_id' => 'UC-1',
            'access_token' => 'tok',
        ]);
        $ttSocial = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'name' => 'My TikTok',
            'status' => 'connected',
        ]);
        $ttConn = Connection::create([
            'user_id' => $user->id,
            'provider' => 'tiktok',
            'account_name' => 'My TikTok',
            'account_id' => 'tt-1',
            'access_token' => 'tok',
        ]);

        $data = $this->toolJson($this->callTool($this->mcpKey($user), 'list_connected_accounts', []));

        $accounts = collect($data['accounts']);
        $this->assertCount(2, $accounts);
        $this->assertSame(['social'], $accounts->pluck('store')->unique()->values()->all());

        $yt = $accounts->firstWhere('platform', 'youtube');
        $this->assertSame($ytSocial->id, $yt['social_account_id']);
        $this->assertSame($ytConn->id, $yt['connection_id']);

        $tt = $accounts->firstWhere('platform', 'tiktok');
        $this->assertSame($ttSocial->id, $tt['social_account_id']);
        $this->assertSame($ttConn->id, $tt['connection_id']);
    }

    public function test_list_connected_accounts_keeps_a_legacy_row_with_no_social_counterpart(): void
    {
        $user = User::factory()->create();
        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => 'UC-1',
            'name' => 'My Channel',
            'status' => 'connected',
        ]);
        $ttConn = Connection::create([
            'user_id' => $user->id,
            'provider' => 'tiktok',
            'account_name' => 'Old TikTok',
            'account_id' => 'tt-1',
            'access_token' => 'tok',
        ]);

        $data = $this->toolJson($this->callTool($this->mcpKey($user), 'list_connected_accounts', []));

        $accounts = collect($data['accounts']);
        $this->assertCount(2, $accounts);
        $this->assertSame('social', $accounts->firstWhere('platform', 'youtube')['store']);
        $this->assertNull($accounts->firstWhere('platform', 'youtube')['connection_id']);

        $tt = $accounts->firstWhere('platform', 'tiktok');
        $this->assertSame('legacy', $tt['store']);
        $this->assertSame($ttConn->id, $tt['connection_id']);
        $this->assertNull($tt['social_account_id']);
    }

    public function test_list_brands_never_returns_an_email_as_account_name(): void
    {
        $user = User::factory()->create();
        $li = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => null,
            'username' => 'jane@example.com',
            'status' => 'connected',
        ]);
        $brand = $user->brands()->create(['name' => 'Acme']);
        $brand->socialAccounts()->sync([$li->id]);

        $data = $this->toolJson($this->callTool($this->mcpKey($user), 'list_brands', []));

        $this->assertNull($data['brands'][0]['accounts'][0]['account_name']);
        $this->assertStringNotContainsString('jane@example.com', json_encode($data));
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

    // ── Tool description rules ──────────────────────────────────────────────

    public function test_tools_that_create_something_warn_that_repeating_a_call_duplicates(): void
    {
        // OpenAI: "Tools should be safe to retry where possible, or explicitly
        // indicate when retries may cause repeated effects."
        $key = $this->mcpKeyWith(User::factory()->create(), ['mcp:read', 'mcp:write']);
        $tools = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        foreach (['create_post', 'upload_media'] as $name) { // TEMP: create_offer and create_tracking_link are hidden
            $this->assertStringContainsString(
                "don't repeat a call that already succeeded",
                $tools[$name]['description'],
                "{$name} should say that calling it again creates another record"
            );
        }
    }

    public function test_search_outliers_describes_its_real_data_source(): void
    {
        // Reviewers read tool descriptions in the submission dashboards, and
        // OpenAI's "authorized access" rule is about scraping. The tool runs a
        // YouTube Data API search, so it must not call itself a scrape.
        $key = $this->mcpKeyWith(User::factory()->create(), ['mcp:read', 'mcp:write']);
        $tools = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertStringNotContainsStringIgnoringCase('scrape', $tools['search_outliers']['description']);
        $this->assertStringContainsString('YouTube', $tools['search_outliers']['description']);
    }

    // ── Unexpected errors ───────────────────────────────────────────────────

    public function test_unexpected_tool_crash_returns_a_friendly_tool_error_and_logs_details(): void
    {
        // Anthropic and OpenAI reviews reject generic or debug-style errors, and
        // the MCP spec reports tool failures as results with isError: true. The
        // internal message must reach the logs, never the AI client.
        Log::spy();
        $this->mock(PostController::class, fn ($mock) => $mock
            ->shouldReceive('index')
            ->andThrow(new \Error('Cannot assign null to property App\\Services\\Secret::$apiKey of type string')));

        $key = $this->mcpKey(User::factory()->create());
        $response = $this->callTool($key, 'list_posts');

        $response->assertOk();
        $this->assertNull($response->json('error'), 'Crash must not surface as a JSON-RPC protocol error');
        $this->assertTrue((bool) $response->json('result.isError'));
        $text = $response->json('result.content.0.text');
        $this->assertSame("Couldn't list posts because of an unexpected problem on ViewsMax's side. Please try again shortly.", $text);
        $this->assertStringNotContainsString('App\\Services', $text);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'MCP tool call failed'
                && $context['tool'] === 'list_posts'
                && $context['exception'] instanceof \Error
        );
    }

    public function test_user_safe_provider_errors_are_passed_through(): void
    {
        // Provider services throw plain RuntimeException with messages meant for
        // users ("Video not found"); UserSafeError already decides these are safe.
        $this->mock(PostController::class, fn ($mock) => $mock
            ->shouldReceive('index')
            ->andThrow(new \RuntimeException('Video not found')));

        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError($this->callTool($key, 'list_posts'), 'Video not found');
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

    /**
     * Streamable HTTP clients may GET the endpoint (server-push stream) or
     * DELETE it (end session); we offer neither, so both get a plain 405 —
     * not a routed exception that lands in the error log per probe.
     */
    public function test_get_and_delete_on_the_endpoint_answer_405(): void
    {
        $this->get('/api/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->delete('/api/mcp')->assertStatus(405);
    }

    // ── Credits ─────────────────────────────────────────────────────────────
    //
    // Every successful tool call deducts the tool's credit cost from the
    // user's wallet (config/credits.php `mcp`). A call is refused before it
    // runs when the balance is below the cost, and the balance never goes
    // negative. Test users get a fresh wallet, so these tests set the balance
    // explicitly instead of going through mcpKey()'s deposit.

    /** An MCP key for a user holding exactly $balance credits. */
    private function mcpKeyWithBalance(int $balance): array
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);
        $user->withdraw($user->balanceInt - $balance);

        return [$user, $key];
    }

    public function test_tool_costs_come_from_config_with_read_and_write_defaults(): void
    {
        $credits = app(\App\Services\CreditService::class);

        $this->assertSame(0, $credits->mcpToolCost('list_offers', false)); // viewing is free
        $this->assertSame(5, $credits->mcpToolCost('create_offer', true));
        $this->assertSame(25, $credits->mcpToolCost('generate_outlier_breakdown', true));

        config(['credits.mcp.tools.list_offers' => 3]);
        $this->assertSame(3, $credits->mcpToolCost('list_offers', false));
    }

    public function test_successful_tool_call_charges_its_credit_cost(): void
    {
        [$user, $key] = $this->mcpKeyWithBalance(100);
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/a']);

        $this->toolJson($this->callTool($key, 'list_offers'));
        $this->assertSame(100, $user->fresh()->balanceInt); // viewing is free

        $this->toolJson($this->callTool($key, 'update_offer', ['id' => $offer->id, 'name' => 'A']));
        $this->assertSame(95, $user->fresh()->balanceInt);
    }

    public function test_failed_tool_call_is_not_charged(): void
    {
        [$user, $key] = $this->mcpKeyWithBalance(100);

        $this->assertToolError($this->callTool($key, 'get_offer', ['id' => 999999]), 'not found');
        $this->assertSame(100, $user->fresh()->balanceInt);
    }

    public function test_tool_call_is_refused_when_balance_is_below_cost(): void
    {
        config(['mcp.frontend_url' => 'https://app.viewsmax.test']);
        [$user, $key] = $this->mcpKeyWithBalance(3);
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/a', 'name' => 'Before']);

        $response = $this->callTool($key, 'update_offer', ['id' => $offer->id, 'name' => 'A']);

        $this->assertToolError($response, 'Not enough credits');
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('5 needed, 3 available', $text);
        $this->assertStringContainsString('https://app.viewsmax.test', $text);
        $this->assertStringNotContainsStringIgnoringCase('upgrade', $text); // no upsell in AI-facing text
        $this->assertSame('Before', $offer->fresh()->name); // refused before running
        $this->assertSame(3, $user->fresh()->balanceInt); // never negative
    }

    public function test_zero_balance_still_allows_viewing_but_refuses_changes(): void
    {
        [$user, $key] = $this->mcpKeyWithBalance(0);
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/a']);

        $this->toolJson($this->callTool($key, 'list_offers'));
        $this->assertToolError($this->callTool($key, 'update_offer', ['id' => $offer->id, 'name' => 'A']), 'Not enough credits');
        $this->assertSame(0, $user->fresh()->balanceInt);
    }

    public function test_an_x_post_with_a_link_costs_the_x_link_price_per_x_account(): void
    {
        [$user, $key] = $this->mcpKeyWithBalance(200);
        foreach (['x-1', 'x-2'] as $id) {
            SocialAccount::create([
                'user_id' => $user->id, 'platform' => 'x', 'platform_account_id' => $id, 'name' => $id, 'username' => $id,
                'access_token' => 't', 'token_expires_at' => now()->addDay(), 'scopes' => ['tweet.write'],
                'status' => SocialAccount::STATUS_CONNECTED,
            ]);
        }
        $scheduled = ['status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(), 'platforms' => ['x']];

        // No link: just create_post.
        $this->toolJson($this->callTool($key, 'create_post', ['caption' => 'Hello'] + $scheduled));
        $this->assertSame(190, $user->fresh()->balanceInt);

        // A link: create_post + x_link (10 + 25 = 35).
        $this->toolJson($this->callTool($key, 'create_post', ['caption' => 'Read example.com/post'] + $scheduled));
        $this->assertSame(155, $user->fresh()->balanceInt);

        // An email isn't a link; a draft isn't charged for links until it goes out.
        $this->toolJson($this->callTool($key, 'create_post', ['caption' => 'Mail me@example.com', 'status' => 'draft', 'platforms' => ['x']]));
        $this->assertSame(145, $user->fresh()->balanceInt);
    }

    public function test_unknown_tool_is_free(): void
    {
        [$user, $key] = $this->mcpKeyWithBalance(0);

        $this->assertToolError($this->callTool($key, 'no_such_tool'), 'Tool not found');
        $this->assertSame(0, $user->fresh()->balanceInt);
    }

    public function test_tool_descriptions_state_their_credit_cost(): void
    {
        $key = $this->mcpKey(User::factory()->create());
        $tools = collect($this->rpc($key, 'tools/list', ['per_page' => 50])->json('result.tools'))->keyBy('name');

        $this->assertStringContainsString('Costs 10 credits per call.', $tools['create_post']['description']);
        $this->assertStringContainsString('costs 25 more per X account', $tools['create_post']['description']);
        $this->assertStringEndsWith('Costs 0 credits per call.', $tools['list_offers']['description']);
    }

    public function test_server_instructions_explain_that_tool_calls_consume_credits(): void
    {
        $key = $this->mcpKey(User::factory()->create());
        $instructions = $this->rpc($key, 'initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ])->assertOk()->json('result.instructions');

        $this->assertStringContainsString('consumes credits', $instructions);
        $this->assertStringNotContainsStringIgnoringCase('upgrade', $instructions); // no upsell in AI-facing text
    }

    public function test_charges_and_refusals_are_logged_to_the_credits_channel(): void
    {
        Log::shouldReceive('channel')->twice()->with('credits')
            ->andReturn($spy = \Mockery::mock(\Psr\Log\LoggerInterface::class));
        $spy->shouldReceive('info')->once()
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'charged') && $ctx['tool'] === 'update_offer' && $ctx['cost'] === 5);
        $spy->shouldReceive('info')->once()
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'insufficient') && $ctx['tool'] === 'update_offer');

        [$user, $key] = $this->mcpKeyWithBalance(5);
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/a']);

        $this->toolJson($this->callTool($key, 'update_offer', ['id' => $offer->id, 'name' => 'B']));
        $this->assertToolError(
            $this->callTool($key, 'update_offer', ['id' => $offer->id, 'name' => 'A']),
            'Not enough credits'
        );
    }
}
