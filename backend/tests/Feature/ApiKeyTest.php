<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * MCP API key lifecycle: a single non-expiring key per user,
 * rotate-to-invalidate, hash-only storage with a display hint.
 */
class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private function loginHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . $user->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    public function test_api_key_endpoints_require_auth(): void
    {
        $this->getJson('/api/user/api-key')->assertUnauthorized();
        $this->postJson('/api/user/api-key/rotate')->assertUnauthorized();
    }

    public function test_get_returns_null_when_no_key_exists(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->loginHeaders($user))
            ->getJson('/api/user/api-key')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => null]);
    }

    public function test_rotate_creates_key_shown_once_with_hint(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->loginHeaders($user))
            ->postJson('/api/user/api-key/rotate')
            ->assertOk()
            ->assertJsonPath('success', true);

        $key = $response->json('data.key');
        $hint = $response->json('data.hint');

        $this->assertStringStartsWith('vmx_', $key);
        $this->assertGreaterThanOrEqual(44, strlen($key));
        $this->assertSame(substr($key, 0, 8) . '...' . substr($key, -5), $hint);

        // Stored hashed; defaults to full access (read + write).
        $token = PersonalAccessToken::findToken($key);
        $this->assertNotNull($token);
        $this->assertSame(['mcp:read', 'mcp:write'], $token->abilities);
        $this->assertSame('full', $response->json('data.access'));
        $this->assertSame($hint, $token->key_hint);
        $this->assertSame(hash('sha256', $key), $token->token);

        // GET now returns the hint and access level but never the key.
        $this->withHeaders($this->loginHeaders($user))
            ->getJson('/api/user/api-key')
            ->assertOk()
            ->assertJsonPath('data.hint', $hint)
            ->assertJsonPath('data.access', 'full')
            ->assertJsonMissingPath('data.key');
    }

    public function test_rotate_can_create_a_read_only_key(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->loginHeaders($user))
            ->postJson('/api/user/api-key/rotate', ['access' => 'read'])
            ->assertOk();

        $key = $response->json('data.key');
        $this->assertSame('read', $response->json('data.access'));

        $token = PersonalAccessToken::findToken($key);
        $this->assertSame(['mcp:read'], $token->abilities);

        $this->withHeaders($this->loginHeaders($user))
            ->getJson('/api/user/api-key')
            ->assertOk()
            ->assertJsonPath('data.access', 'read');
    }

    public function test_rotate_rejects_an_unknown_access_level(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->loginHeaders($user))
            ->postJson('/api/user/api-key/rotate', ['access' => 'admin'])
            ->assertStatus(422);
    }

    public function test_rotate_invalidates_previous_key(): void
    {
        $user = User::factory()->create();
        $headers = $this->loginHeaders($user);

        $first = $this->withHeaders($headers)->postJson('/api/user/api-key/rotate')->json('data.key');
        $second = $this->withHeaders($headers)->postJson('/api/user/api-key/rotate')->json('data.key');

        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->assertNotNull(PersonalAccessToken::findToken($second));

        // Only one MCP key row exists for the user.
        $this->assertSame(1, $user->tokens()->where('name', 'mcp')->count());
    }

    public function test_mcp_key_is_rejected_outside_the_tool_surface(): void
    {
        $user = User::factory()->create();

        $key = $this->withHeaders($this->loginHeaders($user))
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');

        $headers = ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'];

        // Keys work on the REST mirror of the MCP tool surface (see
        // ApiKeyRestAccessTest for the full contract) ...
        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();

        // ... but never on account/billing endpoints.
        $this->withHeaders($headers)->getJson('/api/billing/status')->assertForbidden();
    }

    public function test_login_token_still_works_on_the_normal_api(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->loginHeaders($user))
            ->getJson('/api/posts')
            ->assertOk();
    }

    public function test_rotate_is_rate_limited(): void
    {
        config(['mcp.rate_limits.key_rotate_per_hour' => 2]);

        $user = User::factory()->create();
        $headers = $this->loginHeaders($user);

        $this->withHeaders($headers)->postJson('/api/user/api-key/rotate')->assertOk();
        $this->withHeaders($headers)->postJson('/api/user/api-key/rotate')->assertOk();
        $this->withHeaders($headers)->postJson('/api/user/api-key/rotate')->assertStatus(429);
    }
}
