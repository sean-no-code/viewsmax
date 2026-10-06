<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MCP prompts are slash-command style entry points. find-outliers walks a new
 * user to outlier value with nothing connected.
 */
class McpPromptsTest extends TestCase
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

    public function test_find_outliers_prompt_is_listed_with_its_arguments(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $prompts = $this->rpc($key, 'prompts/list')->assertOk()->json('result.prompts');

        $prompt = collect($prompts)->firstWhere('name', 'find-outliers');
        $this->assertNotNull($prompt, 'find-outliers prompt should be listed');
        $this->assertStringContainsString('no accounts connected', $prompt['description']);
        $this->assertEqualsCanonicalizing(['niche', 'platform'], array_column($prompt['arguments'], 'name'));
        $this->assertFalse(collect($prompt['arguments'])->contains('required', true));
    }

    public function test_find_outliers_without_a_niche_uses_the_featured_feed(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $text = $this->rpc($key, 'prompts/get', ['name' => 'find-outliers'])
            ->assertOk()->json('result.messages.0.content.text');

        $this->assertStringContainsString('list_outliers with no query', $text);
        $this->assertStringNotContainsString('search_outliers', $text);
        $this->assertStringContainsString('generate_outlier_breakdown', $text);
        $this->assertStringContainsString('save_outlier', $text);
    }

    public function test_find_outliers_with_a_niche_searches_youtube_then_polls(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $text = $this->rpc($key, 'prompts/get', [
            'name' => 'find-outliers',
            'arguments' => ['niche' => 'personal finance'],
        ])->assertOk()->json('result.messages.0.content.text');

        $this->assertStringContainsString('search_outliers with query "personal finance"', $text);
        $this->assertStringContainsString('poll list_outliers with the same query', $text);
    }

    public function test_find_outliers_on_tiktok_skips_the_youtube_search(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $text = $this->rpc($key, 'prompts/get', [
            'name' => 'find-outliers',
            'arguments' => ['niche' => 'home fitness', 'platform' => 'TikTok'],
        ])->assertOk()->json('result.messages.0.content.text');

        $this->assertStringNotContainsString('search_outliers with query', $text);
        $this->assertStringContainsString('platform "tiktok"', $text);
    }
}
