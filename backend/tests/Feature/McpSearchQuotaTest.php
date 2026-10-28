<?php

namespace Tests\Feature;

use App\Contracts\Quota\QuotaDecision;
use App\Contracts\Quota\QuotaGate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * search_outliers over MCP when the YouTube quota gate says no: an isError
 * tool result at HTTP 200 (never an HTTP error — see CLAUDE.md), naming the
 * plan and the reset but, unlike the web app, never promoting an upgrade.
 */
class McpSearchQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function mcpKey(User $user): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$login])
            ->postJson('/api/user/api-key/rotate')
            ->json('data.key');
    }

    private function searchOutliers(string $key, string $term): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_outliers', 'arguments' => ['term' => $term]]]);
    }

    private function denyAs(string $scope): void
    {
        $this->app->instance(QuotaGate::class, new class($scope) implements QuotaGate {
            public function __construct(private string $scope) {}

            public function allows(string $resource, string $tier, int $calls = 1, ?int $userId = null): QuotaDecision
            {
                $resetsAt = new \DateTimeImmutable('2026-10-29 07:00:00', new \DateTimeZone('UTC'));

                return $this->scope === 'user'
                    ? QuotaDecision::denyUser($resource, $tier, 2, 2, $resetsAt)
                    : QuotaDecision::deny($resource, $tier, 48, 48, $resetsAt);
            }

            public function recordUserFetch(int $userId, string $resource): void {}
        });
    }

    public function test_a_free_user_over_their_allowance_gets_an_error_result_without_the_upsell(): void
    {
        $this->denyAs('user');
        $response = $this->searchOutliers($this->mcpKey(User::factory()->create()), 'ai agents')->assertOk();

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = $response->json('result.content.0.text');
        $this->assertSame("You've used today's 2 fresh YouTube searches on the Free plan. They reset at midnight Pacific time.", $text);
        $this->assertStringNotContainsString('Upgrade', $text);
    }

    public function test_the_shared_budget_running_out_is_explained_without_the_upsell(): void
    {
        $this->denyAs('project');
        $response = $this->searchOutliers($this->mcpKey(User::factory()->create()), 'ai agents')->assertOk();

        $this->assertTrue((bool) $response->json('result.isError'));
        $text = $response->json('result.content.0.text');
        $this->assertStringContainsString('YouTube search budget for today is used up', $text);
        $this->assertStringNotContainsString('Upgrade', $text);
    }
}
