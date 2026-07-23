<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * This app is otherwise API-only (SPA does token-based login), but Passport's
 * /oauth/authorize consent screen needs a real 'web' (session) guard login.
 * Covers the bridge at GET/POST /login that makes that possible.
 */
class OAuthLoginBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function authorizeUrl(): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Test Client',
            ['https://example.test/callback'],
            confidential: false,
        );

        return '/oauth/authorize?' . http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://example.test/callback',
            'response_type' => 'code',
            'scope' => 'mcp',
            'code_challenge' => str_repeat('x', 43),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function test_guest_hitting_oauth_authorize_is_redirected_to_the_login_form(): void
    {
        $response = $this->get($this->authorizeUrl());

        $response->assertRedirect('/login');
    }

    public function test_login_form_redirects_back_to_the_originally_intended_url(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $authorizeUrl = $this->authorizeUrl();

        $this->get($authorizeUrl)->assertRedirect('/login');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertSame(parse_url($authorizeUrl, PHP_URL_PATH), parse_url($location, PHP_URL_PATH));
        parse_str(parse_url($authorizeUrl, PHP_URL_QUERY), $expectedQuery);
        parse_str(parse_url($location, PHP_URL_QUERY), $actualQuery);
        ksort($expectedQuery);
        ksort($actualQuery);
        $this->assertSame($expectedQuery, $actualQuery);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_form_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_json_requests_to_login_still_get_the_api_redirect_message(): void
    {
        $response = $this->getJson('/login');

        $response->assertStatus(401);
        $response->assertJsonStructure(['message', 'api_login_url']);
    }
}
