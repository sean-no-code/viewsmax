<?php

namespace App\Http\Controllers\Oauth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * OAuth discovery documents for the MCP server at /api/mcp.
 *
 * Claude and ChatGPT both discover auth from these: the 401 challenge on
 * /api/mcp points at the protected resource metadata (RFC 9728), which names
 * this app as the authorization server, whose metadata (RFC 8414) lists the
 * endpoints and capabilities.
 *
 * Replaces laravel/mcp's Mcp::oauthRoutes(), whose documents use a singular
 * `authorization_server` key and an APP_URL resource that don't match what
 * either client requires.
 *
 * OpenID Connect (openid/email scopes, UserInfo, JWKS) is deliberately not
 * offered: neither directory requires it, and advertising those scopes makes
 * ChatGPT ask users to share their email on the consent screen.
 */
class McpOAuthMetadataController extends Controller
{
    /** Scopes that grant MCP tool access (see McpAuth). */
    public const MCP_SCOPES = ['mcp:read', 'mcp:write'];

    /**
     * The authorization server's issuer identifier. It must be byte-identical
     * everywhere it appears — clients compare it with exact string matching.
     */
    public static function issuer(): string
    {
        return url('/');
    }

    /** The one protected resource this host serves. */
    public static function resource(): string
    {
        return url('/api/mcp');
    }

    public static function resourceMetadataUrl(): string
    {
        return url('/.well-known/oauth-protected-resource/api/mcp');
    }

    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => self::resource(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => self::MCP_SCOPES,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => self::issuer(),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'scopes_supported' => self::MCP_SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // DCR registers public clients ("none"); Passport also accepts
            // secrets in the body or a Basic header for confidential clients.
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }
}
