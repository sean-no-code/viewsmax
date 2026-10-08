<?php

namespace Tests\Feature;

use App\Mcp\NextSteps;
use App\Models\OutlierBreakdown;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Every outlier and post result tells the agent what to do next, and every
 * path leads to writing and scheduling a post (see App\Mcp\NextSteps).
 */
class McpNextStepsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function mcpKey(User $user): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/user/api-key/rotate', ['access' => 'full'])
            ->json('data.key');
    }

    private function rpc(string $key, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function tool(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->rpc($key, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
        $response->assertOk();
        $this->assertFalse($response->json('result.isError') ?? false, 'Tool errored: ' . json_encode($response->json('result')));

        return json_decode($response->json('result.content.0.text'), true);
    }

    private function seedOutlier(string $videoId = 'vid1'): OutlierVideo
    {
        $channel = OutlierChannel::create([
            'platform' => 'youtube', 'youtube_channel_id' => 'chan-' . $videoId, 'channel_name' => 'Creator',
            'subscriber_count' => 12000, 'average_views' => 2000, 'country' => 'US',
        ]);

        return OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $channel->id, 'youtube_video_id' => $videoId,
            'title' => 'Outlier ' . $videoId, 'thumbnail_url' => 'https://example.com/t.jpg',
            'views' => 100000, 'like_count' => 5000, 'comment_count' => 300,
            'outlier_score' => 50, 'published_at' => now()->subDays(3),
        ]);
    }

    public function test_search_tells_the_agent_to_poll_then_offer_a_breakdown(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $result = $this->tool($key, 'search_outliers', ['term' => 'gas station sushi']);

        $this->assertSame('queued', $result['status']);
        $this->assertSame(NextSteps::POLL_SEARCH, $result['next_steps']);
    }

    public function test_list_points_at_the_strongest_result_or_at_a_search(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $empty = $this->tool($key, 'list_outliers', ['query' => 'nothing-here']);
        $this->assertContains($empty['status'], ['queued', 'done']);
        $this->assertContains($empty['next_steps'], [NextSteps::SEARCH_RUNNING, NextSteps::NO_RESULTS]);

        $this->seedOutlier('vid1');
        $feed = $this->tool($key, 'list_outliers');
        $this->assertNotEmpty($feed['outliers']);
        $this->assertSame(NextSteps::PICK_ONE, $feed['next_steps']);
    }

    public function test_breakdown_leads_to_writing_and_scheduling_a_post(): void
    {
        $this->seedOutlier('brk1');
        $key = $this->mcpKey(User::factory()->create());

        $none = $this->tool($key, 'get_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'brk1']);
        $this->assertSame(NextSteps::BREAKDOWN_NONE, $none['next_steps']);

        $pending = $this->tool($key, 'generate_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'brk1']);
        $this->assertSame(NextSteps::BREAKDOWN_RUNNING, $pending['next_steps']);

        OutlierBreakdown::where('video_id', 'brk1')->update(['status' => OutlierBreakdown::STATUS_COMPLETED, 'payload' => ['hook' => 'x']]);
        $done = $this->tool($key, 'get_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'brk1']);
        $this->assertSame(NextSteps::BREAKDOWN_COMPLETED, $done['next_steps']);
        $this->assertStringContainsString('create_post', $done['next_steps']);
        $this->assertStringContainsString('"scheduled"', $done['next_steps']);
        $this->assertStringContainsString('Write it yourself', $done['next_steps']);
        $this->assertStringNotContainsString('save_outlier', $done['next_steps']);

        OutlierBreakdown::where('video_id', 'brk1')->update(['status' => OutlierBreakdown::STATUS_FAILED, 'error' => 'No transcript is available for this video.']);
        $failed = $this->tool($key, 'get_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'brk1']);
        $this->assertStringContainsString('no transcript', $failed['next_steps']);
        $this->assertStringContainsString('list_outliers', $failed['next_steps']);
    }

    public function test_video_results_point_at_the_breakdown(): void
    {
        $this->seedOutlier('dQw4w9WgXcQ');
        $key = $this->mcpKey(User::factory()->create());

        $ready = $this->tool($key, 'get_outlier', ['platform' => 'youtube', 'video_id' => 'dQw4w9WgXcQ']);
        $this->assertSame(NextSteps::READY_FOR_BREAKDOWN, $ready['next_steps']);

        $known = $this->tool($key, 'fetch_outlier', ['platform' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $this->assertFalse($known['queued']);
        $this->assertSame(NextSteps::READY_FOR_BREAKDOWN, $known['next_steps']);

        $saved = $this->tool($key, 'save_outlier', ['platform' => 'youtube', 'video_id' => 'dQw4w9WgXcQ']);
        $this->assertSame(NextSteps::SAVED, $saved['next_steps']);
    }

    public function test_a_draft_post_is_told_how_to_be_scheduled(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $draft = $this->tool($key, 'create_post', ['caption' => 'Hook first', 'platforms' => ['x'], 'status' => 'draft']);

        $this->assertSame('draft', $draft['publish_result']['state']);
        $this->assertSame(NextSteps::POST_DRAFT, $draft['next_steps']);
        $this->assertStringContainsString('update_post', $draft['next_steps']);
    }

    public function test_instructions_and_prompt_aim_at_a_scheduled_post(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $instructions = $this->rpc($key, 'initialize', [
            'protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1'],
        ])->json('result.instructions');
        $this->assertStringContainsString('goal of every outlier conversation is a scheduled post', $instructions);
        $this->assertStringContainsString('`next_steps`', $instructions);

        $prompt = $this->rpc($key, 'prompts/get', ['name' => 'find-outliers', 'arguments' => ['niche' => 'golf']])
            ->json('result.messages.0.content.text');
        $this->assertStringContainsString('search_outliers with term "golf"', $prompt);
        $this->assertStringContainsString('post script', $prompt);
        $this->assertStringContainsString('create_post with status "scheduled"', $prompt);
        $this->assertStringNotContainsString('save_outlier', $prompt);
    }
}
