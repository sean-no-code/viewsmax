<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Automation tools over the MCP server (JSON-RPC on /api/mcp). */
class McpAutomationToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $key;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'social.platforms.instagram.automations_enabled' => true,
            'social.platforms.instagram.client_id' => 'id',
            'social.platforms.instagram.client_secret' => 'secret',
        ]);
        $this->user = User::factory()->create();
        Plan::create(['name' => 'free', 'display_name' => 'Free', 'price' => 0, 'is_active' => true, 'max_automations' => 1]);
        $this->account = SocialAccount::create([
            'user_id' => $this->user->id, 'platform' => 'instagram', 'platform_account_id' => 'ig-1', 'username' => 'me',
            'access_token' => 'tok', 'token_expires_at' => now()->addMonth(), 'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => array_merge(['instagram_business_basic'], Automation::REQUIRED_IG_SCOPES),
        ]);
        $plain = 'vmx_'.$this->user->generateTokenString();
        $this->user->tokens()->create(['name' => 'mcp', 'token' => hash('sha256', $plain), 'abilities' => ['mcp:read', 'mcp:write']]);
        $this->key = $plain;
    }

    private function callTool(string $tool, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
    }

    private function toolJson(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse($response->json('result.isError') ?? false, 'Tool returned error: '.json_encode($response->json('result')));

        return json_decode($response->json('result.content.0.text'), true);
    }

    public function test_tools_are_listed(): void
    {
        $names = collect($this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools'))->pluck('name');

        foreach (['list_automations', 'create_automation', 'update_automation', 'start_automation', 'stop_automation', 'delete_automation', 'get_automation_runs'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_create_start_list_runs_stop_and_delete_round_trip(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/subscribed_apps' => Http::response(['success' => true])]);

        $created = $this->toolJson($this->callTool('create_automation', [
            'social_account_id' => $this->account->id, 'trigger_type' => 'dm', 'name' => 'DM info',
            'keyword_mode' => 'contains', 'keywords' => ['info'], 'dm_text' => 'Here is the info',
            'dm_button_label' => 'Open', 'dm_button_url' => 'https://example.com',
        ]));
        $id = $created['data']['id'];
        $this->assertSame('stopped', $created['data']['status']);

        $this->assertSame('live', $this->toolJson($this->callTool('start_automation', ['id' => $id]))['data']['status']);
        $this->assertSame('Renamed', $this->toolJson($this->callTool('update_automation', ['id' => $id, 'name' => 'Renamed']))['data']['name']);

        $list = $this->toolJson($this->callTool('list_automations', ['status' => 'live']));
        $this->assertCount(1, $list['data']['automations']);
        $this->assertSame(['runs' => 0, 'dms_sent' => 0, 'clicked' => 0, 'ctr' => null], $list['data']['automations'][0]['stats']);

        $this->assertSame(0, $this->toolJson($this->callTool('get_automation_runs', ['id' => $id]))['data']['total']);
        $this->assertSame('stopped', $this->toolJson($this->callTool('stop_automation', ['id' => $id]))['data']['status']);
        $this->toolJson($this->callTool('delete_automation', ['id' => $id]));
        $this->assertSoftDeleted('automations', ['id' => $id]);
    }

    public function test_plan_limit_and_validation_surface_as_tool_errors(): void
    {
        $args = ['social_account_id' => $this->account->id, 'trigger_type' => 'dm', 'keyword_mode' => 'any', 'dm_text' => 'hi'];
        $this->toolJson($this->callTool('create_automation', $args));

        $res = $this->callTool('create_automation', $args);
        $res->assertOk();
        $this->assertTrue((bool) $res->json('result.isError'));
        $this->assertStringContainsString("plan's limit", $res->json('result.content.0.text'));

        $res = $this->callTool('start_automation', ['id' => 999]);
        $this->assertTrue((bool) $res->json('result.isError'));
    }
}
