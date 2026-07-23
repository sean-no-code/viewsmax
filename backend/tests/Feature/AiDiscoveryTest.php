<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public AI discovery endpoint: GET /api/ai describes the platform, the MCP
 * server (endpoint, auth modes, tools), the REST API, and rate limits so AI
 * agents can self-configure without scraping the SPA.
 */
class AiDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_is_public(): void
    {
        $this->getJson('/api/ai')
            ->assertOk()
            ->assertJsonPath('name', 'ViewsMax');
    }

    public function test_discovery_describes_the_mcp_server(): void
    {
        $response = $this->getJson('/api/ai')->assertOk();

        $this->assertStringEndsWith('/api/mcp', $response->json('mcp.endpoint'));
        $this->assertSame('streamable-http', $response->json('mcp.transport'));

        $authTypes = collect($response->json('mcp.auth'))->pluck('type');
        $this->assertContains('oauth2', $authTypes);
        $this->assertContains('api_key', $authTypes);
    }

    public function test_discovery_lists_every_tool_with_access_level(): void
    {
        $tools = collect($this->getJson('/api/ai')->assertOk()->json('mcp.tools'));

        $this->assertCount(19, $tools);

        $tools->each(function (array $tool) {
            $this->assertNotSame('', $tool['name']);
            $this->assertNotSame('', $tool['description']);
            $this->assertContains($tool['access'], ['read', 'write']);
        });

        $byName = $tools->keyBy('name');
        $this->assertSame('write', $byName['create_post']['access']);
        $this->assertSame('read', $byName['list_posts']['access']);
        $this->assertSame('read', $byName['get_stats_timeseries']['access']);
    }

    public function test_discovery_includes_docs_rest_and_rate_limits(): void
    {
        $response = $this->getJson('/api/ai')->assertOk();

        $this->assertNotEmpty($response->json('docs.openapi'));
        $this->assertNotEmpty($response->json('docs.llms_txt'));
        $this->assertNotEmpty($response->json('rest.base_url'));
        $this->assertIsInt($response->json('rate_limits.mcp_requests_per_minute'));
    }

    public function test_discovery_is_cacheable(): void
    {
        $cacheControl = $this->getJson('/api/ai')
            ->assertOk()
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
    }
}
