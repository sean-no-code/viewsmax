<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * vmx_ MCP API keys on the REST API: keys may use the endpoints mirroring
 * the MCP tool surface (posts, social, offers, tracking, contents,
 * connections, feature requests, identity) so headless agents can drive REST
 * directly. Read-only keys are limited to GET/HEAD. A leaked key must never
 * reach billing, plans, admin, or key rotation (read→full escalation).
 */
class ApiKeyRestAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    private function loginHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->user->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function keyHeaders(string $access = 'full'): array
    {
        $key = $this->withHeaders($this->loginHeaders())
            ->postJson('/api/user/api-key/rotate', ['access' => $access])
            ->json('data.key');

        return ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'];
    }

    public function test_full_key_can_read_and_write_the_tool_surface(): void
    {
        $headers = $this->keyHeaders('full');

        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/tracking-events')->assertOk();
        $this->withHeaders($headers)->getJson('/api/social/accounts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/profile')->assertOk();
        $this->withHeaders($headers)->getJson('/api/outliers')->assertOk();
        $this->withHeaders($headers)->getJson('/api/outliers/library')->assertOk();

        $this->withHeaders($headers)->postJson('/api/posts', [
            'caption' => 'Posted via API key',
            'platforms' => ['x'],
            'status' => 'draft',
        ])->assertStatus(201);
    }

    public function test_read_only_key_is_limited_to_get(): void
    {
        $headers = $this->keyHeaders('read');

        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/outliers')->assertOk();

        $this->withHeaders($headers)->postJson('/api/posts', [
            'caption' => 'Should be blocked',
            'platforms' => ['x'],
            'status' => 'draft',
        ])->assertForbidden();

        $this->withHeaders($headers)->postJson('/api/outliers/search', ['term' => 'blocked'])->assertForbidden();
    }

    public function test_keys_never_reach_account_billing_or_admin_endpoints(): void
    {
        $headers = $this->keyHeaders('full');

        // Key management — a key must not be able to rotate itself to a
        // different access level or read its own metadata endpoint.
        $this->withHeaders($headers)->getJson('/api/user/api-key')->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/user/api-key/rotate')->assertForbidden();

        // Billing / plans / admin.
        $this->withHeaders($headers)->getJson('/api/billing/status')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/user-plans/current')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/admin/users')->assertForbidden();

        // Other non-tool surfaces stay login-only.
        $this->withHeaders($headers)->getJson('/api/beehiiv/connection')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/user/mcp-activity')->assertForbidden();
    }

    public function test_login_tokens_keep_full_access(): void
    {
        $headers = $this->loginHeaders();

        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/billing/status')->assertOk();
        $this->withHeaders($headers)->getJson('/api/user/api-key')->assertOk();
    }

    public function test_invalid_tokens_are_still_401_not_403(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer vmx_not_a_real_key', 'Accept' => 'application/json'])
            ->getJson('/api/posts')
            ->assertUnauthorized();
    }
}
