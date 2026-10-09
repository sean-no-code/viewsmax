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
        $this->fundCredits($user, 10_000); // MCP tool calls cost credits; keep test users funded

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
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/x']);
        $key = $this->mcpKey($user);

        $this->rpc($key, 'tools/call', [
            'name' => 'update_offer',
            'arguments' => ['id' => $offer->id, 'name' => 'Audit me'],
        ])->assertOk();

        $row = McpToolInvocation::where('user_id', $user->id)->first();
        $this->assertNotNull($row, 'Expected an audit row for the tool call.');
        $this->assertSame('update_offer', $row->tool);
        $this->assertSame('Audit me', $row->arguments['name']);
        $this->assertFalse($row->is_error);
        $this->assertNull($row->error);
        $this->assertSame('key', $row->auth_mode);
        $this->assertSame(5, $row->credits_charged); // create_offer = write default
    }

    public function test_calls_refused_for_insufficient_credits_are_logged_as_errors_with_no_charge(): void
    {
        $user = User::factory()->create();
        $key = $this->mcpKey($user);
        $user->withdraw($user->balanceInt); // drain the test funding

        $this->rpc($key, 'tools/call', [
            'name' => 'create_offer',
            'arguments' => ['name' => 'Refused', 'offer_url' => 'https://example.com/x'],
        ])->assertOk();

        $row = McpToolInvocation::where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->is_error);
        $this->assertStringContainsString('Not enough credits', $row->error);
        $this->assertNull($row->credits_charged);
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
        $this->assertNotEmpty($row->error, 'Expected the tool error text to be recorded.');
        $this->assertNull($row->credits_charged, 'A failed call is free.');
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
