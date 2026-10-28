<?php

namespace Tests\Feature;

use App\Jobs\ImportChannelVideosJob;
use App\Jobs\ImportRecentPostsJob;
use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\StripeService;
use App\Support\AnalyticsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Upgrading into a plan with analytics backfills the imports the user missed
 * on the lower tier. The user_plans pivot fires no model events (two of the
 * plan-change paths are raw DB::table updates), so every path that writes it
 * calls AnalyticsAccess::backfill() — which must be a no-op below Pro and run
 * once however many times it is called.
 */
class AnalyticsBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function channel(User $user): Channel
    {
        return Channel::create([
            'user_id' => $user->id,
            'youtube_channel_id' => 'UC'.Str::random(8),
            'channel_name' => 'My Channel',
            'youtube_access_token' => 'yt-oauth-token',
        ]);
    }

    private function account(User $user, string $platform = 'x', string $status = SocialAccount::STATUS_CONNECTED): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-'.Str::random(5),
            'name' => ucfirst($platform),
            'access_token' => 'tok',
            'status' => $status,
        ]);
    }

    public function test_queues_one_job_per_channel_and_connected_account(): void
    {
        Queue::fake();
        $user = User::factory()->withPlan('active', $this->planNamed('pro'))->create();
        $a = $this->channel($user);
        $b = $this->channel($user);
        $account = $this->account($user);
        $this->account($user, 'threads', SocialAccount::STATUS_NEEDS_REAUTH); // not connected

        AnalyticsAccess::backfill($user);

        Queue::assertPushed(ImportChannelVideosJob::class, 2);
        Queue::assertPushed(ImportChannelVideosJob::class, fn ($job) => $job->channelId === $a->id);
        Queue::assertPushed(ImportChannelVideosJob::class, fn ($job) => $job->channelId === $b->id);
        Queue::assertPushed(ImportRecentPostsJob::class, 1);
        Queue::assertPushed(ImportRecentPostsJob::class, fn ($job) => $job->socialAccountId === $account->id);
    }

    public function test_is_a_no_op_below_pro(): void
    {
        Queue::fake();
        $user = User::factory()->withPlan('active', $this->planNamed('creator'))->create();
        $this->channel($user);
        $this->account($user);

        AnalyticsAccess::backfill($user);

        Queue::assertNothingPushed();
    }

    public function test_repeated_calls_queue_the_backfill_once(): void
    {
        Queue::fake();
        $user = User::factory()->withPlan('active', $this->planNamed('agency'))->create();
        $this->channel($user);

        AnalyticsAccess::backfill($user);
        AnalyticsAccess::backfill($user);
        AnalyticsAccess::backfill($user);

        Queue::assertPushed(ImportChannelVideosJob::class, 1);
    }

    public function test_changing_plan_into_pro_backfills(): void
    {
        Queue::fake();
        $user = User::factory()->withPlan('active', $this->planNamed('creator'))->create();
        $channel = $this->channel($user);
        $pro = $this->planNamed('pro');

        $this->mock(StripeService::class, fn ($m) => $m->shouldReceive('changeSubscriptionPrice')
            ->once()
            ->andReturn(['status' => 'active']));

        $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ])->postJson('/api/user-plans/change-plan', ['price_id' => $pro->stripe_price_id])
            ->assertOk();

        $this->assertSame('pro', $user->fresh()->activePlan()->name);
        Queue::assertPushed(ImportChannelVideosJob::class, fn ($job) => $job->channelId === $channel->id);
    }

    public function test_changing_plan_down_to_creator_backfills_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->withPlan('active', $this->planNamed('pro'))->create();
        $this->channel($user);
        $creator = $this->planNamed('creator');

        $this->mock(StripeService::class, fn ($m) => $m->shouldReceive('changeSubscriptionPrice')
            ->once()
            ->andReturn(['status' => 'active']));

        $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ])->postJson('/api/user-plans/change-plan', ['price_id' => $creator->stripe_price_id])
            ->assertOk();

        Queue::assertNothingPushed();
    }
}
