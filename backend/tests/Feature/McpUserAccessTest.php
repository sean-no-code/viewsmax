<?php

namespace Tests\Feature;

use App\Models\McpToolInvocation;
use App\Support\UserAccess;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A user who has to pay before using ViewsMax (subscription ended, free trial
 * over with no plan since, no card added — see UserAccess) gets HTTP 402 from
 * every tools/call: a JSON-RPC error whose data names the code and the link
 * that fixes it. initialize and tools/list still answer 200 so the connector
 * stays attached. An unverified email is not a payment matter and stays a
 * plain HTTP 200 tool result the AI can relay.
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

    /** The 402 every paying-matter denial gets: a JSON-RPC error carrying the denial. */
    private function assertPaymentRequired(TestResponse $response, User $user): void
    {
        $denial = UserAccess::denial($user);

        $response->assertStatus(402)
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1)
            ->assertJsonMissingPath('result')
            ->assertJsonPath('error.code', -32000)
            ->assertJsonPath('error.message', $denial->message)
            ->assertJsonPath('error.data.code', $denial->code)
            ->assertJsonPath('error.data.message', $denial->message)
            ->assertJsonPath('error.data.upgrade_url', $denial->url);
    }

    public function test_an_ended_subscription_gets_402_with_the_billing_link(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);
        $user = User::factory()->expired()->withPlan('expired')->create();

        $response = $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []]);

        $this->assertPaymentRequired($response, $user);
        $response->assertJsonPath('error.data.code', 'subscription_inactive')
            ->assertJsonPath('error.data.upgrade_url', 'https://app.example.com/dashboard/billing')
            ->assertJsonPath('error.message', 'Your ViewsMax subscription has ended. Reactivate it to continue using ViewsMax: https://app.example.com/dashboard/billing');
    }

    public function test_expired_trial_gets_402_with_the_billing_link(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);
        $user = User::factory()->create(['promo_expires_at' => now()->subDay()]);

        $response = $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []]);

        $this->assertPaymentRequired($response, $user);
        $response->assertJsonPath('error.data.code', 'access_expired');
        $this->assertStringContainsString('upgrade to a paid subscription to continue using ViewsMax', $response->json('error.message'));
        $this->assertStringContainsString('https://app.example.com/dashboard/billing', $response->json('error.message'));
    }

    public function test_a_refused_call_is_logged_as_an_error_not_a_success(): void
    {
        $user = User::factory()->expired()->withPlan('cancelled')->create();

        $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_posts', 'arguments' => ['status' => 'draft']])
            ->assertStatus(402);

        $row = McpToolInvocation::where('user_id', $user->id)->sole();
        $this->assertSame('list_posts', $row->tool);
        $this->assertTrue((bool) $row->is_error);
        $this->assertSame(UserAccess::denial($user)->message, $row->error);
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
            ->assertJsonPath('result.isError', false)
            ->assertJsonMissingPath('error');

        $text = $response->json('result.content.0.text');
        $this->assertSame(UserAccess::denial($user)->message, $text);
        $this->assertStringContainsString('Verify your email address', $text);
        $this->assertStringContainsString(route('login'), $text);
    }

    public function test_a_user_without_a_window_or_a_plan_is_gated(): void
    {
        $legacy = User::factory()->create(['promo_expires_at' => null]);

        $this->assertTrue($legacy->accessExpired());
        $this->assertPaymentRequired(
            $this->rpc($this->mcpKey($legacy), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []]),
            $legacy,
        );
    }

    public function test_users_in_trial_or_on_a_plan_are_not_gated(): void
    {
        $inTrial = User::factory()->create(['promo_expires_at' => now()->addDay()]);
        $paid = User::factory()->create(['promo_expires_at' => now()->subDay()]);
        $paid->plans()->attach($this->paidPlan()->id, ['status' => 'active', 'starts_at' => now()]);

        foreach ([$inTrial, $paid] as $user) {
            $this->assertFalse($user->accessExpired());
            $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []])
                ->assertOk()
                ->assertJsonPath('result.isError', false);
        }
    }

    public function test_a_signup_without_a_card_is_told_to_add_one_instead_of_running_the_tool(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null, 'promo_expires_at' => now()->addDays(6), 'signup_source' => 'agent']);

        $response = $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []]);

        $this->assertPaymentRequired($response, $user);
        $response->assertJsonPath('error.data.code', 'payment_required')
            ->assertJsonPath('error.data.upgrade_url', route('register.billing'));
        $this->assertStringContainsString('Add a card', $response->json('error.message'));
    }
}
