<?php

namespace Tests\Feature;

use App\Support\UserAccess;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A user who can't use ViewsMax right now (unverified email, free trial over
 * with no plan since — see UserAccess) gets that answer from every tools/call
 * as an HTTP 200 tool result the AI can relay. initialize and tools/list still
 * work so the connector stays attached and nothing counts as a server error.
 */
class McpUserAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mint an MCP key directly: the REST rotate endpoint is behind
     * EnsureAccessActive, so an expired user couldn't mint one (they got the
     * key while the trial was open).
     */
    private function mcpKey(User $user): string
    {
        $plain = 'vmx_'.$user->generateTokenString();
        $user->tokens()->create(['name' => 'mcp', 'token' => hash('sha256', $plain), 'abilities' => ['mcp']]);

        return $plain;
    }

    private function paidPlan(): Plan
    {
        return Plan::create([
            'name' => 'pro', 'display_name' => 'Pro', 'price' => 99.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_pro',
        ]);
    }

    private function rpc(string $key, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    public function test_expired_trial_gets_an_upgrade_message_with_the_billing_link(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);
        $user = User::factory()->create(['promo_expires_at' => now()->subDay()]);
        $key = $this->mcpKey($user);

        $response = $this->rpc($key, 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []])
            ->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonPath('result.isError', true)
            ->assertJsonMissingPath('error');

        $text = $response->json('result.content.0.text');
        $this->assertSame(UserAccess::denial($user)->message, $text);
        $this->assertStringContainsString('upgrade to a paid subscription to continue using ViewsMax', $text);
        $this->assertStringContainsString('https://app.example.com/dashboard/billing', $text);
    }

    public function test_expired_trial_can_still_initialize_and_list_tools(): void
    {
        $key = $this->mcpKey(User::factory()->create(['promo_expires_at' => now()->subDay()]));

        $this->rpc($key, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'ViewsMax');
        $tools = $this->rpc($key, 'tools/list')->assertOk()->json('result.tools');
        $this->assertNotEmpty($tools);
    }

    public function test_unverified_email_gets_a_verify_message_instead_of_running_the_tool(): void
    {
        $user = User::factory()->unverified()->create(['signup_source' => 'agent']);
        $key = $this->mcpKey($user);

        $this->rpc($key, 'tools/list')->assertOk()->assertJsonMissingPath('error');

        $response = $this->rpc($key, 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonMissingPath('error');

        $text = $response->json('result.content.0.text');
        $this->assertSame(UserAccess::denial($user)->message, $text);
        $this->assertStringContainsString('Verify your email address', $text);
        $this->assertStringContainsString(route('login'), $text);
    }

    public function test_users_in_trial_or_on_a_plan_or_without_a_window_are_not_gated(): void
    {
        $inTrial = User::factory()->create(['promo_expires_at' => now()->addDay()]);
        $legacy = User::factory()->create(['promo_expires_at' => null]);
        $paid = User::factory()->create(['promo_expires_at' => now()->subDay()]);
        $paid->plans()->attach($this->paidPlan()->id, ['status' => 'active', 'starts_at' => now()]);

        foreach ([$inTrial, $legacy, $paid] as $user) {
            $this->assertFalse($user->accessExpired());
            $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []])
                ->assertOk()
                ->assertJsonPath('result.isError', false);
        }
    }
}
