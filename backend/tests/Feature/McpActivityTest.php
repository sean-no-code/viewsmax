<?php

namespace Tests\Feature;

use App\Models\McpToolInvocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/user/mcp-activity — lets a user see what their connected AI
 * assistants actually did (reads from the mcp_tool_invocations audit log).
 */
class McpActivityTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_returns_own_activity_newest_first(): void
    {
        $user = User::factory()->create();

        McpToolInvocation::create([
            'user_id' => $user->id, 'tool' => 'create_offer',
            'arguments' => ['name' => 'First'], 'is_error' => false, 'auth_mode' => 'key',
            'created_at' => now()->subMinutes(5),
        ]);
        McpToolInvocation::create([
            'user_id' => $user->id, 'tool' => 'create_post',
            'arguments' => ['caption' => 'Newest'], 'is_error' => true, 'auth_mode' => 'oauth',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/user/mcp-activity', $this->authHeaders($user));

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(2, $items);
        $this->assertSame('create_post', $items[0]['tool']);
        $this->assertTrue($items[0]['is_error']);
        $this->assertSame('oauth', $items[0]['auth_mode']);
        $this->assertSame(['caption' => 'Newest'], $items[0]['arguments']);
        $this->assertSame('create_offer', $items[1]['tool']);
    }

    public function test_activity_is_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        McpToolInvocation::create([
            'user_id' => $other->id, 'tool' => 'delete_offer',
            'arguments' => [], 'is_error' => false, 'auth_mode' => 'key',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/user/mcp-activity', $this->authHeaders($user));

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/user/mcp-activity')->assertStatus(401);
    }
}
