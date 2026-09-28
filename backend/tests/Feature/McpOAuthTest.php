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
        return $this->issueTokens($user, $scopes, $approveExtra)['access_token'];
    }

    /**
     * Run the full authorization-code + PKCE flow and return the token
     * response plus the client id (needed to refresh).
     */
    private function issueTokens(User $user, array $scopes = ['mcp'], array $approveExtra = []): array
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

        return $token->json() + ['client_id' => $client->id];
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

    public function test_protected_resource_metadata_is_served_at_both_well_known_paths(): void
    {
        // RFC 9728: for a resource at /api/mcp, clients look up
        // /.well-known/oauth-protected-resource/api/mcp first (Claude does
        // exactly this) before falling back to the root document. This host
        // has one protected resource, so both documents describe /api/mcp:
        // the resource field must match the MCP URL exactly, path included,
        // and authorization_servers must be an array of issuer URLs.
        foreach (['/.well-known/oauth-protected-resource/api/mcp', '/.well-known/oauth-protected-resource'] as $path) {
            $response = $this->getJson($path);

            $response->assertOk();
            $response->assertJson([
                'resource' => url('/api/mcp'),
                'authorization_servers' => [url('/')],
                'bearer_methods_supported' => ['header'],
            ]);
            $this->assertEqualsCanonicalizing(['mcp:read', 'mcp:write'], $response->json('scopes_supported'));
        }
    }

    public function test_unauthenticated_mcp_request_challenges_with_resource_metadata_and_scope(): void
    {
        // The 401 challenge is how Claude and ChatGPT find the metadata. It
        // points at the document whose resource matches /api/mcp and names
        // the scopes to request, so the consent prompt asks for exactly the
        // MCP scopes (Claude requests the challenge's scope when present).
        $response = $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $response->assertStatus(401);
        $this->assertSame(
            'Bearer resource_metadata="' . url('/.well-known/oauth-protected-resource/api/mcp') . '", scope="mcp:read mcp:write"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_authorization_server_metadata_is_served(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $response->assertJson([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
        $this->assertEqualsCanonicalizing(['authorization_code', 'refresh_token'], $response->json('grant_types_supported'));
        // "none" is what DCR-registered public clients (Claude, ChatGPT) use.
        $this->assertContains('none', $response->json('token_endpoint_auth_methods_supported'));
        $this->assertEqualsCanonicalizing(['mcp:read', 'mcp:write'], $response->json('scopes_supported'));
    }

    public function test_openid_connect_is_not_offered(): void
    {
        // Decided 2026-09-15: neither the Claude nor the OpenAI directory
        // requires OpenID Connect. Advertising openid/email makes ChatGPT ask
        // users to share their email on the consent screen, so the discovery
        // document, UserInfo, and JWKS endpoints are intentionally absent.
        $metadata = $this->getJson('/.well-known/oauth-authorization-server');
        $metadata->assertJsonMissingPath('userinfo_endpoint');
        $metadata->assertJsonMissingPath('jwks_uri');
        $this->assertNotContains('openid', $metadata->json('scopes_supported'));
        $this->assertNotContains('email', $metadata->json('scopes_supported'));

        $this->getJson('/.well-known/openid-configuration')->assertNotFound();
        $this->getJson('/oauth/userinfo')->assertNotFound();
        $this->getJson('/oauth/jwks')->assertNotFound();
    }

    public function test_dynamic_client_registration_returns_a_public_client(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ]);
        $this->assertNotEmpty($response->json('client_id'));
        $this->assertIsInt($response->json('client_id_issued_at'));
    }

    public function test_dynamic_client_registration_rejects_missing_redirect_uris(): void
    {
        // RFC 7591 §3.2.2: invalid metadata gets a 400 with a registration
        // error code, not a 500 from a missing array key.
        $this->postJson('/oauth/register', ['client_name' => 'No redirects'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);

        $this->postJson('/oauth/register', ['redirect_uris' => ['not a url']])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);
    }

    public function test_dynamic_client_registration_names_unnamed_clients(): void
    {
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect']])
            ->assertCreated()
            ->assertJsonPath('client_name', 'MCP client');
    }

    public function test_refresh_token_grant_issues_a_new_access_token(): void
    {
        // The discovery document advertises refresh_token, and Claude/ChatGPT
        // refresh reactively on 401 — the grant has to actually work.
        $user = User::factory()->create();
        $tokens = $this->issueTokens($user);

        $this->assertNotEmpty($tokens['refresh_token']);

        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $tokens['client_id'],
        ], ['Content-Type' => 'application/x-www-form-urlencoded']);

        $refreshed->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer ' . $refreshed->json('access_token')])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk();
    }

    /** Render the consent screen for a client with the given redirect URIs. */
    private function consentScreen(array $redirectUris): \Illuminate\Testing\TestResponse
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Claude',
            $redirectUris,
            confidential: false,
        );

        [, $challenge] = $this->pkcePair();

        return $this->actingAs(User::factory()->create(), 'web')->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => $redirectUris[0],
            'response_type' => 'code',
            'scope' => 'mcp:read mcp:write',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ]));
    }

    public function test_consent_screen_shows_where_the_user_will_be_sent_back(): void
    {
        // The client name comes from self-registration, so anything can call
        // itself "Claude". The redirect host can't be faked the same way, and
        // the MCP authorization spec requires showing it on the consent screen
        // (see Claude's connector authentication docs).
        $response = $this->consentScreen(['https://claude.ai/api/mcp/auth_callback']);

        $response->assertOk();
        $response->assertSee('claude.ai');
        $response->assertDontSee('this computer');
    }

    public function test_consent_screen_warns_when_the_client_only_returns_to_this_computer(): void
    {
        // Any local process can claim a loopback redirect, so the spec asks for
        // an extra warning when every registered redirect is on the machine.
        $response = $this->consentScreen(['http://localhost:3118/callback', 'http://127.0.0.1:3118/callback']);

        $response->assertOk();
        $response->assertSee('this computer');
        $response->assertSee('Only approve this if you started the connection yourself.');
    }

    public function test_access_tokens_name_this_mcp_server_as_their_audience(): void
    {
        // OpenAI: "Configure your authorization server to copy that value into
        // the access token (commonly the `aud` claim) so your MCP server can
        // verify the token was minted for it and nobody else." Claude's sample
        // server checks the same thing. The client id stays first, because
        // league/oauth2-server reads oauth_client_id from aud[0].
        $tokens = $this->issueTokens(User::factory()->create());

        [, $payload] = explode('.', $tokens['access_token']);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        $this->assertSame($tokens['client_id'], $claims['aud'][0]);
        $this->assertContains(url('/api/mcp'), $claims['aud']);

        // The token still works end to end.
        $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access_token']])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk();
    }

    public function test_signed_in_clients_get_their_own_request_budget(): void
    {
        // The limiter only recognised MCP API keys, so every OAuth client
        // (Claude, ChatGPT, Codex) shared the 20/min "failed auth" budget of
        // its IP address. Those clients call from a handful of shared IPs,
        // so one busy user throttled everyone else.
        config(['mcp.rate_limits.per_minute' => 120, 'mcp.rate_limits.failed_auth_per_minute' => 20]);
        $first = $this->issueAccessToken(User::factory()->create());
        $second = $this->issueAccessToken(User::factory()->create());

        $call = fn (string $token) => $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $response = $call($first)->assertOk();
        $this->assertSame('120', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('119', $response->headers->get('X-RateLimit-Remaining'));

        // A different user from the same IP starts with a full budget.
        $this->assertSame('119', $call($second)->headers->get('X-RateLimit-Remaining'));
    }

    public function test_mcp_rejects_tokens_that_do_not_name_this_server_as_audience(): void
    {
        // MCP spec: servers "MUST reject tokens that do not include them in
        // the audience claim". OpenAI: "If a token arrives without the
        // expected audience or scopes, reject it". Passport's stock access
        // token only names the client, like tokens issued before this change.
        \Laravel\Passport\Passport::useAccessTokenEntity(\Laravel\Passport\Bridge\AccessToken::class);
        $token = $this->issueAccessToken(User::factory()->create());

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $response->assertStatus(401);
        // The challenge sends the client back through sign-in (or a refresh),
        // which issues a token with the right audience.
        $this->assertStringContainsString('resource_metadata=', $response->headers->get('WWW-Authenticate'));
    }

    public function test_openai_domain_verification_token_is_served_when_configured(): void
    {
        // OpenAI verifies we control api.viewsmax.com by fetching this exact
        // path: "The challenge endpoint must return only that plugin's
        // verification token. Do not return JSON, a list of tokens, or multiple
        // tokens from the same URL." The token arrives at submission time, so
        // it comes from config and the route stays closed until it is set.
        config(['mcp.openai_apps_challenge_token' => 'oa-challenge-abc123']);

        $response = $this->get('/.well-known/openai-apps-challenge');

        $response->assertOk();
        $this->assertSame('oa-challenge-abc123', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_openai_domain_verification_is_absent_until_configured(): void
    {
        config(['mcp.openai_apps_challenge_token' => null]);

        $this->get('/.well-known/openai-apps-challenge')->assertNotFound();
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
