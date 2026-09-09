<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Http\Request as LaravelRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Laravel\Sanctum\PersonalAccessToken;

class ApiAuth
{
    /**
     * REST endpoints a vmx_ MCP API key may use — the mirror of the MCP tool
     * surface, so headless agents (OpenClaw skills, Hermes plugins, curl) can
     * drive REST with one key. Patterns are passed to Request::is(), so a
     * bare entry is an exact match and wildcards are explicit. 'api/user' is
     * deliberately exact-only: 'api/user/*' would expose api-key rotation
     * (a read-key → full-key escalation) and other account endpoints.
     */
    private const API_KEY_ALLOWED_PATTERNS = [
        'api/posts', 'api/posts/*',
        'api/social', 'api/social/*',
        'api/tracking-events', 'api/tracking-events/*',
        'api/tracking-links', 'api/tracking-links/*',
        'api/goal-types',
        'api/contents', 'api/contents/*',
        'api/connections', 'api/connections/*',
        'api/feature-requests', 'api/feature-requests/*',
        'api/outliers', 'api/outliers/*',
        'api/automations', 'api/automations/*',
        'api/profile',
        'api/user',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(LaravelRequest $request, Closure $next): SymfonyResponse
    {
        // Always let CORS preflight pass through without auth
        if ($request->getMethod() === 'OPTIONS') {
            return new SymfonyResponse('', 204);
        }

        $token = $request->bearerToken();

        if (!$token) {
            return ResponseFacade::json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return ResponseFacade::json([
                'success' => false,
                'message' => 'Invalid token'
            ], 401);
        }

        // MCP API keys (vmx_) may use the REST endpoints mirroring the MCP
        // tool surface; they never reach billing, account, plans, admin, or
        // key rotation — a leaked key must not grant account takeover. Login
        // tokens (no mcp abilities) are unaffected and keep full access.
        // Matches every MCP scope: legacy `mcp` plus `mcp:read` / `mcp:write`.
        $abilities = $accessToken->abilities ?? [];
        if (array_intersect(['mcp', 'mcp:read', 'mcp:write'], $abilities)) {
            if (!$request->is(...self::API_KEY_ALLOWED_PATTERNS)) {
                return ResponseFacade::json([
                    'success' => false,
                    'message' => 'This endpoint is not available to API keys. Use a login session.'
                ], 403);
            }

            $canWrite = (bool) array_intersect(['mcp', 'mcp:write'], $abilities);
            if (!$canWrite && !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                return ResponseFacade::json([
                    'success' => false,
                    'message' => 'This API key is read-only. Rotate it with full access to make changes.'
                ], 403);
            }
        }

        // Set the authenticated user
        $request->setUserResolver(function () use ($accessToken) {
            return $accessToken->tokenable;
        });

        return $next($request);
    }
}
