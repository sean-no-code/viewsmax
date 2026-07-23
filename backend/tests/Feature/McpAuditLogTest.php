<?php

namespace Tests\Feature;

use App\Models\McpToolInvocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Every MCP tools/call is audit-logged: who (user), what (tool + arguments),
 * and whether it errored — so a customer (or support) can always answer
 * "what did the AI actually do on this account".
 */
class McpAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function mcpKey(User $user): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');
    }

    private function rpc(string $key, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Accept' => 'application/json',
        ])->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);
    }

    public function test_successful_tool_calls_are_logged(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $this->rpc($key, 'tools/call', [
            'name' => 'create_offer',
            'arguments' => ['name' => 'Audit me', 'offer_url' => 'https://example.com/x'],
        ])->assertOk();

        $row = McpToolInvocation::where('user_id', $user->id)->first();
        $this->assertNotNull($row, 'Expected an audit row for the tool call.');
        $this->assertSame('create_offer', $row->tool);
        $this->assertSame('Audit me', $row->arguments['name']);
        $this->assertFalse($row->is_error);
        $this->assertSame('key', $row->auth_mode);
    }

    public function test_failed_tool_calls_are_logged_as_errors(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $other->offers()->create(['offer_url' => 'https://example.com/other']);

        $key = $this->mcpKey($user);
        $this->rpc($key, 'tools/call', [
            'name' => 'get_offer',
            'arguments' => ['id' => $foreign->id],
        ])->assertOk();

        $row = McpToolInvocation::where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('get_offer', $row->tool);
        $this->assertTrue($row->is_error);
    }

    public function test_non_tool_calls_are_not_logged(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        $this->rpc($key, 'tools/list')->assertOk();

        $this->assertSame(0, McpToolInvocation::count());
    }

    public function test_unauthenticated_requests_are_not_logged(): void
    {
        $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'list_offers', 'arguments' => []],
        ])->assertStatus(401);

        $this->assertSame(0, McpToolInvocation::count());
    }
}
