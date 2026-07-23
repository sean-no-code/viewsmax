<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Laravel\Sanctum\PersonalAccessToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Auth for the /mcp endpoint. Two modes are accepted, both as a Bearer header:
 *  - OAuth 2.1 + PKCE access token — the customer-facing path (Claude "Add
 *    custom connector" via OAuth).
 *  - MCP API key — for header-native clients (Cursor, Claude Code, raw API use).
 * Login tokens (Sanctum tokens without the `mcp` ability) are rejected,
 * mirroring how ApiAuth rejects MCP keys on the rest of the API. Passing a key
 * in the URL path is deliberately not supported: secrets in URLs leak into
 * access logs, browser history, and Referer headers.
 */
class McpAuth
{
    /**
     * The MCP API key, always as a Bearer header.
     */
    public static function resolveToken(LaravelRequest $request): ?string
    {
        return $request->bearerToken();
    }

    /**
     * Request attribute holding the granted MCP scopes for this request,
     * normalized to a subset of ['mcp:read', 'mcp:write']. Tools read it via
     * self::grantedScopes() to decide whether to register for the request.
     */
    public const SCOPES_ATTRIBUTE = 'mcp_scopes';

    public function handle(LaravelRequest $request, Closure $next): SymfonyResponse
    {
        if ($user = $this->resolveOAuthUser($request)) {
            $request->setUserResolver(fn () => $user);
            Auth::setUser($user);
            $request->attributes->set('mcp_auth_mode', 'oauth');

            return $next($request);
        }

        $token = self::resolveToken($request);

        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        $scopes = $accessToken ? self::normalizeScopes($accessToken->abilities ?? []) : [];

        if (! $accessToken || $scopes === []) {
            // The WWW-Authenticate header is how OAuth-capable clients (e.g.
            // Claude) discover that this endpoint supports OAuth and where to
            // find the discovery documents, instead of just seeing a bare 401.
            return ResponseFacade::json([
                'success' => false,
                'message' => 'Unauthenticated. Provide an MCP API key or OAuth access token as a Bearer token.',
            ], 401)->header(
                'WWW-Authenticate',
                'Bearer resource_metadata="' . url('/.well-known/oauth-protected-resource') . '"'
            );
        }

        $request->attributes->set(self::SCOPES_ATTRIBUTE, $scopes);
        $request->setUserResolver(fn () => $accessToken->tokenable);

        // Delegated tools call into pre-MCP controllers that authenticate via
        // the Auth facade (e.g. Auth::user()->posts()), not $request->user() —
        // set the user directly so the facade guard is populated for them.
        Auth::setUser($accessToken->tokenable);
        $request->attributes->set('mcp_auth_mode', 'key');

        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    /**
     * The granted MCP scopes for the current request, as stashed by handle().
     * Absent (e.g. non-MCP context) means no scoping applies — full access.
     */
    public static function grantedScopes(LaravelRequest $request): array
    {
        return $request->attributes->has(self::SCOPES_ATTRIBUTE)
            ? (array) $request->attributes->get(self::SCOPES_ATTRIBUTE)
            : ['mcp:read', 'mcp:write'];
    }

    /**
     * Reduce raw abilities/scopes to a subset of ['mcp:read', 'mcp:write'].
     * The legacy single `mcp` scope means full access, so it expands to both.
     */
    private static function normalizeScopes(array $raw): array
    {
        if (in_array('mcp', $raw, true)) {
            return ['mcp:read', 'mcp:write'];
        }

        return array_values(array_intersect(['mcp:read', 'mcp:write'], $raw));
    }

    /**
     * Validate the Bearer token directly against Passport's resource server,
     * rather than via Laravel's Passport guard/HasApiTokens trait. The User
     * model deliberately doesn't implement Passport's OAuthenticatable —
     * Passport's HasApiTokens trait redeclares tokens()/createToken()/
     * currentAccessToken(), which ApiKeyController, AuthController, and the
     * Sanctum-based auth modes above already depend on for a different
     * (Sanctum) meaning. Bypassing the guard avoids that collision entirely.
     */
    private function resolveOAuthUser(LaravelRequest $request): ?User
    {
        if (! $request->bearerToken()) {
            return null;
        }

        try {
            $psr = app(ResourceServer::class)->validateAuthenticatedRequest(
                (new PsrHttpFactory)->createRequest($request)
            );
        } catch (OAuthServerException) {
            return null;
        }

        $scopes = self::normalizeScopes($psr->getAttribute('oauth_scopes', []));

        if ($scopes === []) {
            return null;
        }

        $request->attributes->set(self::SCOPES_ATTRIBUTE, $scopes);

        return User::find($psr->getAttribute('oauth_user_id'));
    }
}
