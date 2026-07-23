<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * OAuth 2.1 + PKCE as an MCP auth mode — the customer-facing path (see
 * McpServerTest for the header/URL-key modes aimed at developer clients).
 * Drives Passport's real /oauth/authorize and /oauth/token routes end to
 * end, the same way a real OAuth client (e.g. Claude) would.
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'https://client.example.test/callback';

    private function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    private function issueAccessToken(User $user, array $scopes = ['mcp'], array $approveExtra = []): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Test MCP Client',
            [self::REDIRECT_URI],
            confidential: false,
        );

        [$verifier, $challenge] = $this->pkcePair();

        $authorize = $this->actingAs($user, 'web')->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ]));

        $authorize->assertOk();
        $authToken = session('authToken');
        $this->assertNotNull($authToken, 'Expected Passport to store an authToken in the session for the consent step.');

        $approve = $this->post('/oauth/authorize', ['auth_token' => $authToken] + $approveExtra);
        $approve->assertRedirect();

        parse_str(parse_url($approve->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query, 'Expected a redirect containing an authorization code.');

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ]);

        $token->assertOk();

        return $token->json('access_token');
    }

    public function test_oauth_access_token_can_authenticate_an_mcp_tool_call(): void
    {
        $user = User::factory()->create();
        $accessToken = $this->issueAccessToken($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'list_connected_accounts', 'arguments' => []],
            ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), 'Tool call failed: ' . json_encode($response->json('result')));
    }

    public function test_oauth_token_issued_without_requesting_scopes_defaults_to_mcp(): void
    {
        // Claude's connector client doesn't send a scope parameter at all, so
        // an empty scope request must still yield a token that can call MCP.
        $user = User::factory()->create();
        $accessToken = $this->issueAccessToken($user, scopes: []);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'list_connected_accounts', 'arguments' => []],
            ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), 'Tool call failed: ' . json_encode($response->json('result')));
    }

    public function test_read_only_consent_yields_a_token_that_cannot_use_write_tools(): void
    {
        // The consent screen lets the user downgrade to read-only by posting
        // access=read; the issued token then carries only mcp:read, so write
        // tools are unregistered for it (hidden from tools/list, unresolvable).
        $user = User::factory()->create();
        $accessToken = $this->issueAccessToken(
            $user,
            scopes: ['mcp:read', 'mcp:write'],
            approveExtra: ['access' => 'read'],
        );

        $list = $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = collect($list->assertOk()->json('result.tools'))->pluck('name')->all();
        $this->assertContains('list_posts', $names);
        $this->assertNotContains('create_post', $names);
    }

    public function test_path_suffixed_protected_resource_metadata_is_served(): void
    {
        // RFC 9728: for a resource at /api/mcp, clients look up
        // /.well-known/oauth-protected-resource/api/mcp first (Claude does
        // exactly this) before falling back to the root document. The
        // resource field must be the full resource URL including its path.
        $response = $this->getJson('/.well-known/oauth-protected-resource/api/mcp');

        $response->assertOk();
        $response->assertJson([
            'resource' => url('/api/mcp'),
            'authorization_server' => url('/.well-known/oauth-authorization-server'),
        ]);
    }

    public function test_oauth_token_without_the_mcp_scope_is_rejected(): void
    {
        $user = User::factory()->create();

        // Register a second scope so the client can request something other
        // than 'mcp' — proves the middleware checks scope, not just token validity.
        \Laravel\Passport\Passport::tokensCan([
            'mcp' => 'Access ViewsMax MCP tools',
            'other' => 'Unrelated scope',
        ]);

        $accessToken = $this->issueAccessToken($user, scopes: ['other']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'list_connected_accounts', 'arguments' => []],
            ]);

        $response->assertStatus(401);
    }
}
