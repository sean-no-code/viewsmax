<?php

namespace App\Http\Controllers\Oauth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Dynamic Client Registration (RFC 7591) for MCP clients such as Claude and
 * ChatGPT, which register themselves before starting the OAuth flow.
 *
 * Every client is registered as a public authorization-code client (PKCE, no
 * secret). Invalid metadata gets an RFC 7591 §3.2.2 error instead of the 500
 * laravel/mcp's built-in endpoint returns for a missing field.
 */
class McpClientRegistrationController extends Controller
{
    public function store(Request $request, ClientRepository $clients): JsonResponse
    {
        $redirectUris = $request->json('redirect_uris');

        if (! is_array($redirectUris) || $redirectUris === [] || ! collect($redirectUris)->every(
            fn ($uri) => is_string($uri) && filter_var($uri, FILTER_VALIDATE_URL) !== false
        )) {
            return response()->json([
                'error' => 'invalid_redirect_uri',
                'error_description' => 'redirect_uris must be a non-empty array of absolute URLs.',
            ], 400);
        }

        $name = trim((string) $request->json('client_name', ''));

        $client = $clients->createAuthorizationCodeGrantClient(
            name: Str::limit($name !== '' ? $name : 'MCP client', 255, ''),
            redirectUris: array_values($redirectUris),
            confidential: false,
        );

        return response()->json([
            'client_id' => (string) $client->id,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], 201);
    }
}
