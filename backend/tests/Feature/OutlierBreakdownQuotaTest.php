<?php

namespace Tests\Feature;

use App\Jobs\GenerateOutlierBreakdownJob;
use App\Models\OutlierBreakdown;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Outlier breakdowns are Gemini video analyses — money, not YouTube quota —
 * rationed per user per day by the same gate (BREAKDOWNS_PER_USER_FREE/PAID).
 * Opening a breakdown that already exists is always free.
 */
class OutlierBreakdownQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.youtube.quota.per_user.breakdowns' => ['free' => 2, 'paid' => 20, 'admin' => null]]);
        $channel = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UC1', 'channel_name' => 'c', 'subscriber_count' => 1, 'average_views' => 1]);
        foreach (['v1', 'v2', 'v3'] as $id) {
            OutlierVideo::create(['platform' => 'youtube', 'channel_id' => $channel->id, 'youtube_video_id' => $id, 'title' => $id, 'views' => 1, 'outlier_score' => 50, 'published_at' => now()]);
        }
    }

    private function request(User $user, string $videoId): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->postJson("/api/outliers/youtube/{$videoId}/breakdown");
    }

    public function test_a_free_user_gets_two_new_breakdowns_a_day_then_a_plan_aware_429(): void
    {
        $user = User::factory()->create();

        $this->request($user, 'v1')->assertOk();
        $this->request($user, 'v2')->assertOk();
        $this->request($user, 'v3')
            ->assertStatus(429)
            ->assertJsonPath('code', 'quota_paused')
            ->assertJsonPath('scope', 'user')
            ->assertJsonPath('upgrade', true)
            ->assertJsonPath('message', "You've used today's 2 outlier breakdowns on the Free plan. They reset at midnight Pacific time. Upgrade to get higher limits.");

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 2);
        $this->assertNull(OutlierBreakdown::where('video_id', 'v3')->first(), 'no orphaned pending row');
    }

    public function test_opening_an_existing_breakdown_never_counts(): void
    {
        $user = User::factory()->create();
        OutlierBreakdown::create(['platform' => 'youtube', 'video_id' => 'v1', 'status' => OutlierBreakdown::STATUS_COMPLETED, 'payload' => ['hook' => 'x']]);

        foreach (range(1, 5) as $i) {
            $this->request($user, 'v1')->assertOk()->assertJsonPath('data.status', 'completed');
        }
        $this->request($user, 'v2')->assertOk();
        $this->request($user, 'v3')->assertOk();

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 2);
    }

    public function test_paid_users_get_more_and_no_upsell(): void
    {
        config(['services.youtube.quota.per_user.breakdowns.paid' => 1]);
        $pro = Plan::firstOrCreate(['name' => 'pro'], ['display_name' => 'Pro', 'price' => 79, 'currency' => 'USD', 'is_active' => true, 'stripe_price_id' => 'price_pro']);
        $user = User::factory()->withPlan('active', $pro)->create();

        $this->request($user, 'v1')->assertOk();
        $this->request($user, 'v2')
            ->assertStatus(429)
            ->assertJsonPath('upgrade', false)
            ->assertJsonPath('message', "You've used today's 1 outlier breakdowns on the Pro plan. They reset at midnight Pacific time.");
    }

    public function test_admins_are_unlimited(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['v1', 'v2', 'v3'] as $id) {
            $this->request($admin, $id)->assertOk();
        }
        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 3);
    }

    public function test_the_mcp_tool_reports_the_limit_without_the_upsell(): void
    {
        $user = User::factory()->create();
        $login = $user->createToken('mobile-app')->plainTextToken;
        $key = $this->withHeaders(['Authorization' => 'Bearer '.$login])->postJson('/api/user/api-key/rotate')->json('data.key');
        $call = fn (string $id) => $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'generate_outlier_breakdown', 'arguments' => ['platform' => 'youtube', 'video_id' => $id]]]);

        $call('v1');
        $call('v2');
        $response = $call('v3')->assertOk();

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame("You've used today's 2 outlier breakdowns on the Free plan. They reset at midnight Pacific time.", $response->json('result.content.0.text'));
    }
}
