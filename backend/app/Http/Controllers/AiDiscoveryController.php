<?php

namespace App\Http\Controllers;

use App\Mcp\ViewsMaxServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public capability discovery for AI agents (the machine-readable "front
 * door", linked from llms.txt). Describes the MCP server, both auth modes,
 * every tool, the REST API, and rate limits so an agent can self-configure
 * without scraping the SPA. No user data is exposed.
 */
class AiDiscoveryController extends Controller
{
    /**
     * Bump the suffix whenever build() changes so deploys invalidate cleanly.
     */
    private const CACHE_KEY = 'ai-discovery:v4';

    /**
     * AI capability discovery
     *
     * @group Discovery
     * @unauthenticated
     */
    public function index(): JsonResponse
    {
        $payload = Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => $this->build());

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function build(): array
    {
        $tools = collect((new ViewsMaxServer)->tools)
            ->map(fn (string $class) => app($class))
            ->map(fn ($tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'access' => $tool->isWrite() ? 'write' : 'read',
            ])
            ->values()
            ->all();

        $site = rtrim((string) config('mcp.frontend_url'), '/');

        return [
            'name' => 'ViewsMax',
            'summary' => 'Social posting + link tracking/analytics SaaS. AI agents act on a '
                . "user's behalf: compose and schedule posts to YouTube, TikTok, X, LinkedIn, "
                . 'Threads, Instagram, and Bluesky; create offers and tracked links; read '
                . 'click, conversion, and revenue stats; research outlier videos (content '
                . 'that massively over-performed its channel) and get AI breakdowns of why '
                . 'they worked. User data is private — all access is authenticated.',
            'site' => $site,
            'docs' => [
                'agents' => $site . '/ai.md',
                'llms_txt' => $site . '/llms.txt',
                'api_reference' => url('/docs'),
                'openapi' => url('/docs.openapi'),
            ],
            'mcp' => [
                'endpoint' => url('/api/mcp'),
                'transport' => 'streamable-http',
                'auth' => [
                    [
                        'type' => 'oauth2',
                        'grant' => 'authorization_code',
                        'pkce' => true,
                        'scopes' => ['mcp', 'mcp:read', 'mcp:write'],
                        'authorization_server_metadata' => url('/.well-known/oauth-authorization-server'),
                        'protected_resource_metadata' => url('/.well-known/oauth-protected-resource/api/mcp'),
                        'dynamic_client_registration' => true,
                    ],
                    [
                        'type' => 'api_key',
                        'header' => 'Authorization: Bearer <key>',
                        'key_prefix' => 'vmx_',
                        'access_levels' => ['read', 'full'],
                        'obtain_at' => $site . '/dashboard/settings',
                    ],
                ],
                'tools' => $tools,
            ],
            'rest' => [
                'base_url' => url('/api'),
                'auth' => 'Same vmx_ API key as a Bearer token (posts, offers, tracking, '
                    . 'stats, and outliers endpoints only; read-only keys are limited to GET).',
                'openapi' => url('/docs.openapi'),
            ],
            'rate_limits' => [
                'mcp_requests_per_minute' => (int) config('mcp.rate_limits.per_minute'),
                'create_post_per_hour' => (int) config('mcp.rate_limits.create_post_per_hour'),
                'upload_media_per_hour' => (int) config('mcp.rate_limits.upload_media_per_hour'),
                'search_outliers_per_hour' => (int) config('mcp.rate_limits.search_outliers_per_hour'),
                'fetch_outlier_per_hour' => (int) config('mcp.rate_limits.fetch_outlier_per_hour'),
                'generate_outlier_breakdown_per_hour' => (int) config('mcp.rate_limits.generate_breakdown_per_hour'),
            ],
        ];
    }
}
