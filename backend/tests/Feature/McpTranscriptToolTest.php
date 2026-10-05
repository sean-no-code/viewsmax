<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * get_transcript: the free transcript tool over MCP. Same service, cache,
 * output and per-minute limit as the web app's /free-tools/transcript.
 */
class McpTranscriptToolTest extends TestCase
{
    use RefreshDatabase;

    private const YT = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.captapi.api_key' => 'capt_live_test',
            'services.captapi.base_url' => 'https://api.captapi.com/v1',
        ]);
    }

    private function mcpKey(User $user, string $access = 'full'): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/user/api-key/rotate', ['access' => $access])
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

    private function assertToolError(TestResponse $response, ?string $contains = null): void
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'Expected a tool error');
        if ($contains !== null) {
            $this->assertStringContainsString($contains, $response->json('result.content.0.text'));
        }
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'api.captapi.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'platform' => 'youtube',
                    'url' => self::YT,
                    'text' => 'Never gonna give you up.',
                    'segments' => [['text' => 'Never gonna give you up.', 'startMs' => 0, 'endMs' => 2000]],
                    'language' => 'en',
                    'fetchedAt' => '2026-08-17T12:14:27.902Z',
                ],
                'cached' => false,
                'creditsUsed' => 1,
                'requestId' => 'req-1',
            ], 200),
        ]);
    }

    public function test_read_only_and_full_keys_both_see_the_tool(): void
    {
        $names = fn (string $key) => collect($this->withHeaders(['Authorization' => 'Bearer ' . $key])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50]])
            ->json('result.tools'))->pluck('name');

        $this->assertContains('get_transcript', $names($this->mcpKey(User::factory()->create())));
        $this->assertContains('get_transcript', $names($this->mcpKey(User::factory()->create(), 'read')));
    }

    public function test_returns_the_same_fields_as_the_web_tool_and_caches_them(): void
    {
        $this->fakeSuccess();
        $key = $this->mcpKey(User::factory()->create(), 'read');

        $first = $this->toolJson($this->callTool($key, 'get_transcript', ['platform' => 'youtube', 'url' => self::YT]));

        $this->assertSame([
            'platform' => 'youtube',
            'url' => self::YT,
            'text' => 'Never gonna give you up.',
            'segments' => [['text' => 'Never gonna give you up.', 'startMs' => 0, 'endMs' => 2000]],
            'language' => 'en',
            'cached' => false,
        ], $first);

        $second = $this->toolJson($this->callTool($key, 'get_transcript', ['platform' => 'youtube', 'url' => self::YT]));

        $this->assertTrue($second['cached']);
        Http::assertSentCount(1);
    }

    public function test_rejects_a_link_from_another_platform_without_calling_the_provider(): void
    {
        Http::fake();
        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError(
            $this->callTool($key, 'get_transcript', ['platform' => 'youtube', 'url' => 'https://www.instagram.com/p/DZFsjH9E3gK/']),
            "doesn't look like a valid youtube link"
        );
        Http::assertNothingSent();
    }

    public function test_rejects_bad_arguments(): void
    {
        Http::fake();
        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError($this->callTool($key, 'get_transcript', ['platform' => 'facebook', 'url' => self::YT]));
        $this->assertToolError($this->callTool($key, 'get_transcript', ['platform' => 'youtube', 'url' => 'not a url']));
        Http::assertNothingSent();
    }

    public function test_reports_a_provider_failure_as_a_tool_error(): void
    {
        Http::fake(['api.captapi.com/*' => Http::response(['success' => false, 'error' => 'Not found'], 404)]);
        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError($this->callTool($key, 'get_transcript', ['platform' => 'youtube', 'url' => self::YT]), 'Not found');
        $this->assertDatabaseCount('transcripts', 0, 'outlier_db');
    }

    public function test_uses_the_web_tools_limit_of_15_per_minute(): void
    {
        $this->fakeSuccess();
        $key = $this->mcpKey(User::factory()->create());

        for ($i = 0; $i < 15; $i++) {
            $this->toolJson($this->callTool($key, 'get_transcript', ['platform' => 'youtube', 'url' => self::YT]));
        }

        $this->assertToolError($this->callTool($key, 'get_transcript', ['platform' => 'youtube', 'url' => self::YT]), 'Rate limit');
    }
}
