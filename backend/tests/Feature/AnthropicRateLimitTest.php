<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeThumbnailJob;
use App\Jobs\AnalyzeTitleJob;
use App\Jobs\GenerateOutlierBreakdownJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\UpdateScriptJob;
use App\Models\Channel;
use App\Models\OutlierBreakdown;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\Script;
use App\Models\TitleScore;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every action that spends Anthropic tokens shares one per-user hourly budget
 * (services.anthropic.user_calls_per_hour), checked where the work is queued
 * so REST and MCP (which calls the same controllers) draw from the same pool.
 * Cache hits and ordinary edits never count.
 */
class AnthropicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.user_calls_per_hour' => 2]);
        $this->user = User::factory()->create();
        // Credit and plan gating are covered elsewhere; this is about the AI budget.
        $this->withoutMiddleware([\App\Http\Middleware\CheckCredits::class, \App\Http\Middleware\RestrictFreePlan::class]);
        // So is the daily breakdown allowance (OutlierBreakdownQuotaTest).
        config(['services.youtube.quota.per_user.breakdowns.free' => null]);
    }

    private function headers(): array
    {
        $token = $this->user->createToken('mobile-app')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function outlier(string $videoId): void
    {
        $channel = OutlierChannel::firstOrCreate(
            ['platform' => 'youtube', 'youtube_channel_id' => 'UC123'],
            ['channel_name' => 'kai', 'subscriber_count' => 1, 'average_views' => 1],
        );
        OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $channel->id, 'youtube_video_id' => $videoId,
            'title' => 'T', 'views' => 10, 'outlier_score' => 50, 'published_at' => now(),
        ]);
    }

    private function generateBreakdown(string $videoId)
    {
        return $this->withHeaders($this->headers())->postJson("/api/outliers/youtube/{$videoId}/breakdown");
    }

    public function test_breakdowns_stop_at_the_budget_and_leave_no_orphan_row(): void
    {
        Queue::fake();
        foreach (['v1', 'v2', 'v3'] as $id) {
            $this->outlier($id);
        }

        $this->generateBreakdown('v1')->assertOk();
        $this->generateBreakdown('v2')->assertOk();
        $this->generateBreakdown('v3')
            ->assertStatus(429)
            ->assertJsonPath('code', 'ai_rate_limited')
            ->assertHeader('Retry-After');

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 2);
        $this->assertNull(OutlierBreakdown::where('video_id', 'v3')->first(), 'a refused request must not leave a pending row behind');
    }

    public function test_an_existing_breakdown_is_served_without_spending_budget(): void
    {
        Queue::fake();
        $this->outlier('done');
        OutlierBreakdown::create(['platform' => 'youtube', 'video_id' => 'done', 'status' => OutlierBreakdown::STATUS_COMPLETED, 'payload' => ['hook' => 'x']]);
        config(['services.anthropic.user_calls_per_hour' => 1]);

        $this->generateBreakdown('done')->assertOk();
        $this->generateBreakdown('done')->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_mcp_breakdown_tool_reports_the_limit_as_a_tool_error(): void
    {
        Queue::fake();
        config(['services.anthropic.user_calls_per_hour' => 1]);
        $this->outlier('m1');
        $this->outlier('m2');
        $key = $this->withHeaders($this->headers())->postJson('/api/user/api-key/rotate')->json('data.key');

        $call = fn (string $id) => $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                'name' => 'generate_outlier_breakdown', 'arguments' => ['platform' => 'youtube', 'video_id' => $id],
            ]]);

        $call('m1')->assertOk()->assertJsonPath('result.isError', false);
        $second = $call('m2')->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('AI limit', $second->json('result.content.0.text'));
    }

    public function test_script_regenerate_is_limited_but_a_plain_edit_is_not(): void
    {
        Queue::fake();
        config(['services.anthropic.user_calls_per_hour' => 1]);
        $script = Script::create(['user_id' => $this->user->id, 'title' => 'S', 'prompt' => 'p', 'status' => 'completed', 'text' => 'hi']);

        $this->withHeaders($this->headers())->putJson("/api/scripts/{$script->id}", ['regenerate' => true])->assertOk();
        $this->withHeaders($this->headers())->putJson("/api/scripts/{$script->id}", ['regenerate' => true])->assertStatus(429);
        $this->withHeaders($this->headers())->putJson("/api/scripts/{$script->id}", ['refine' => true, 'prompt' => 'shorter'])->assertStatus(429);
        $this->withHeaders($this->headers())->putJson("/api/scripts/{$script->id}", ['title' => 'Renamed'])->assertOk();

        Queue::assertPushed(GenerateScriptJob::class, 1);
        Queue::assertNotPushed(UpdateScriptJob::class);
    }

    public function test_script_generate_is_limited(): void
    {
        Queue::fake();
        config(['services.anthropic.user_calls_per_hour' => 1]);
        $script = Script::create(['user_id' => $this->user->id, 'title' => 'S', 'prompt' => 'p', 'status' => 'draft']);

        $this->withHeaders($this->headers())->postJson("/api/scripts/{$script->id}/generate")->assertOk();
        $this->withHeaders($this->headers())->postJson("/api/scripts/{$script->id}/generate")->assertStatus(429);

        Queue::assertPushed(GenerateScriptJob::class, 1);
    }

    public function test_video_review_is_limited_only_when_it_would_queue_analysis(): void
    {
        Bus::fake();
        config(['services.anthropic.user_calls_per_hour' => 1]);
        $channel = Channel::create(['user_id' => $this->user->id, 'youtube_channel_id' => 'chan-1', 'channel_name' => 'Mine']);
        $first = Video::create(['channel_id' => $channel->id, 'title' => 'One', 'youtube_video_id' => 'yt1']);
        $second = Video::create(['channel_id' => $channel->id, 'title' => 'Two', 'youtube_video_id' => 'yt2']);

        $this->withHeaders($this->headers())->postJson("/api/videos/{$first->id}/review")->assertSuccessful();
        $this->withHeaders($this->headers())->postJson("/api/videos/{$second->id}/review")->assertStatus(429);
        $this->assertNull(TitleScore::where('video_id', $second->id)->first(), 'a refused review must not leave a pending score');

        // Already queued for the first video: just reports status, no budget needed.
        $this->withHeaders($this->headers())->postJson("/api/videos/{$first->id}/review")->assertSuccessful();
    }

    public function test_zero_turns_the_limit_off(): void
    {
        Queue::fake();
        config(['services.anthropic.user_calls_per_hour' => 0]);
        foreach (['z1', 'z2', 'z3'] as $id) {
            $this->outlier($id);
            $this->generateBreakdown($id)->assertOk();
        }

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 3);
    }

    public function test_budgets_are_per_user(): void
    {
        Queue::fake();
        config(['services.anthropic.user_calls_per_hour' => 1]);
        $this->outlier('p1');
        $this->outlier('p2');

        $this->generateBreakdown('p1')->assertOk();
        $this->user = User::factory()->create();
        $this->generateBreakdown('p2')->assertOk();
    }
}
