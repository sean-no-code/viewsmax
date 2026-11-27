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
 * A user who can't use ViewsMax right now (unverified email, subscription
 * ended, free trial over with no plan since, no card added — see UserAccess)
 * gets that answer from every tools/call as a plain HTTP 200 tool result (not
 * isError) the AI can relay, with the link that fixes it. initialize and
 * tools/list still work so the connector stays attached, and nothing counts
 * as a request or tool error in a connector directory's metrics.
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

    /**
     * What every denial gets: HTTP 200 and a normal (not isError) tool result
     * whose text is the denial message. Returns that text.
     */
    private function assertToldInsteadOfRun(TestResponse $response, User $user): string
    {
        $response->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1)
            ->assertJsonMissingPath('error')
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.content.0.text', UserAccess::denial($user)->message);

        return $response->json('result.content.0.text');
    }

    public function test_an_ended_subscription_gets_a_reactivate_message_with_the_billing_link(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);
        $user = User::factory()->expired()->withPlan('expired')->create();

        $response = $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []]);

        $this->assertSame(
            'Your ViewsMax subscription has ended. Reactivate it to continue using ViewsMax: https://app.example.com/dashboard/billing',
            $this->assertToldInsteadOfRun($response, $user),
        );
    }

    public function test_expired_trial_gets_an_upgrade_message_with_the_billing_link(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);
        $user = User::factory()->create(['promo_expires_at' => now()->subDay()]);

        $response = $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []]);

        $text = $this->assertToldInsteadOfRun($response, $user);
        $this->assertStringContainsString('upgrade to a paid subscription to continue using ViewsMax', $text);
        $this->assertStringContainsString('https://app.example.com/dashboard/billing', $text);
    }

    /** The client sees a normal result; our own audit log still says the tool was refused. */
    public function test_a_refused_call_is_logged_as_an_error_not_a_success(): void
    {
        $user = User::factory()->expired()->withPlan('cancelled')->create();

        $this->rpc($this->mcpKey($user), 'tools/call', ['name' => 'list_posts', 'arguments' => ['status' => 'draft']])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

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

        $text = $this->assertToldInsteadOfRun(
            $this->rpc($key, 'tools/call', ['name' => 'list_connected_accounts', 'arguments' => []]),
            $user,
        );
        $this->assertStringContainsString('Verify your email address', $text);
        $this->assertStringContainsString(route('login'), $text);
    }

    public function test_a_user_without_a_window_or_a_plan_is_gated(): void
    {
        $legacy = User::factory()->create(['promo_expires_at' => null]);

        $this->assertTrue($legacy->accessExpired());
        $this->assertToldInsteadOfRun(
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

        $text = $this->assertToldInsteadOfRun($response, $user);
        $this->assertSame(UserAccess::PAYMENT_REQUIRED, UserAccess::denial($user)->code);
        $this->assertStringContainsString('Add a card', $text);
        $this->assertStringContainsString(route('register.billing'), $text);
    }
}
