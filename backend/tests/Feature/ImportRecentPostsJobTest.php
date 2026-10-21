<?php

namespace Tests\Feature;

use App\Jobs\ImportRecentPostsJob;
use App\Models\PostMetricSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Connecting a channel queues ImportRecentPostsJob, which pulls the account's
 * latest posts from the platform (not just the ones published through the
 * app) and writes a day-0 engagement snapshot for each, so Post Performance
 * has something to show straight away. posts:import-recent does the same for
 * accounts that were connected before this existed.
 */
class ImportRecentPostsJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function account(string $platform, string $status = SocialAccount::STATUS_CONNECTED): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $this->user->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-1',
            'name' => ucfirst($platform),
            'access_token' => 'tok',
            'status' => $status,
        ]);
    }

    /**
     * Bind a manager whose provider lists $posts and returns $metricsById.
     *
     * @return MockInterface the provider mock, for extra expectations
     */
    private function fakeManager(array $posts, array $metricsById, bool $supportsStats = true): MockInterface
    {
        $provider = \Mockery::mock(SocialProviderInterface::class);
        $provider->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($a) => $a);
        $provider->shouldReceive('fetchRecentPosts')->andReturn($posts)->byDefault();
        $provider->shouldReceive('fetchPostMetrics')->andReturnUsing(function ($account, $ids) use ($metricsById) {
            return array_intersect_key($metricsById, array_flip($ids));
        });

        $manager = \Mockery::mock(SocialProviderManager::class);
        $manager->shouldReceive('supports')->andReturn(true);
        $manager->shouldReceive('supportsStats')->andReturn($supportsStats);
        $manager->shouldReceive('for')->andReturn($provider);
        $this->app->instance(SocialProviderManager::class, $manager);

        return $provider;
    }

    public function test_imports_the_accounts_recent_posts_as_day_zero_snapshots(): void
    {
        $yt = $this->account('youtube');
        $this->fakeManager([
            ['remote_post_id' => 'vid1', 'url' => 'https://yt/vid1', 'caption' => 'First video', 'published_at' => Carbon::parse('2026-10-01T10:00:00Z'), 'thumbnail' => 'https://cdn/listed.jpg'],
            ['remote_post_id' => 'vid2', 'url' => 'https://yt/vid2', 'caption' => 'Second video', 'published_at' => null, 'thumbnail' => 'https://cdn/vid2.jpg'],
        ], [
            'vid1' => ['likes' => 5, 'comments' => 1, 'shares' => 0, 'views' => 1000, 'thumbnail' => 'https://cdn/fresh.jpg'],
            'vid2' => ['likes' => 1, 'comments' => 0, 'shares' => 0, 'views' => 10],
        ]);

        ImportRecentPostsJob::dispatchSync($yt->id);

        $this->assertSame(2, PostMetricSnapshot::count());
        $vid1 = PostMetricSnapshot::where('remote_post_id', 'vid1')->sole();
        $this->assertSame('youtube', $vid1->platform);
        $this->assertSame($this->user->id, $vid1->user_id);
        $this->assertSame($yt->id, $vid1->social_account_id);
        $this->assertSame('https://yt/vid1', $vid1->url);
        $this->assertSame('First video', $vid1->caption_excerpt);
        $this->assertSame('2026-10-01T10:00:00+00:00', $vid1->published_at->toIso8601String());
        $this->assertSame(now()->toDateString(), $vid1->snapshot_date);
        $this->assertSame(1006, $vid1->engagement_total);
        $this->assertSame('https://cdn/fresh.jpg', $vid1->thumbnail_url); // the metrics' link beats the listing's
        $vid2 = PostMetricSnapshot::where('remote_post_id', 'vid2')->sole();
        $this->assertNull($vid2->published_at);
        $this->assertSame('https://cdn/vid2.jpg', $vid2->thumbnail_url); // listing's link when the metrics carry none
        $this->assertNull($yt->fresh()->post_stats_error);
    }

    public function test_posts_the_platform_returns_no_metrics_for_are_skipped_not_zeroed(): void
    {
        $x = $this->account('x');
        $this->fakeManager([
            ['remote_post_id' => 't1', 'url' => 'https://x.com/jo/status/t1', 'caption' => 'one', 'published_at' => null],
            ['remote_post_id' => 't2', 'url' => 'https://x.com/jo/status/t2', 'caption' => 'two', 'published_at' => null],
        ], ['t1' => ['likes' => 3, 'comments' => 0, 'shares' => 0, 'views' => 0]]);

        ImportRecentPostsJob::dispatchSync($x->id);

        $this->assertSame(['t1'], PostMetricSnapshot::pluck('remote_post_id')->all());
    }

    public function test_re_running_the_same_day_updates_rather_than_duplicates(): void
    {
        $yt = $this->account('youtube');
        $this->fakeManager(
            [['remote_post_id' => 'vid1', 'url' => 'https://yt/vid1', 'caption' => 'First', 'published_at' => null]],
            ['vid1' => ['likes' => 5, 'comments' => 0, 'shares' => 0, 'views' => 100]],
        );

        ImportRecentPostsJob::dispatchSync($yt->id);
        ImportRecentPostsJob::dispatchSync($yt->id);

        $this->assertSame(1, PostMetricSnapshot::count());
    }

    public function test_platforms_without_stats_support_are_left_alone(): void
    {
        $li = $this->account('linkedin');
        $provider = $this->fakeManager([], [], supportsStats: false);
        $provider->shouldNotReceive('fetchRecentPosts');

        ImportRecentPostsJob::dispatchSync($li->id);

        $this->assertSame(0, PostMetricSnapshot::count());
    }

    public function test_accounts_that_are_not_connected_are_left_alone(): void
    {
        $yt = $this->account('youtube', SocialAccount::STATUS_NEEDS_REAUTH);
        $provider = $this->fakeManager([], []);
        $provider->shouldNotReceive('fetchRecentPosts');

        ImportRecentPostsJob::dispatchSync($yt->id);
        ImportRecentPostsJob::dispatchSync($yt->id + 999); // gone

        $this->assertSame(0, PostMetricSnapshot::count());
    }

    public function test_a_listing_failure_is_recorded_on_the_account_and_retried(): void
    {
        $yt = $this->account('youtube');
        $provider = $this->fakeManager([], []);
        $provider->shouldReceive('fetchRecentPosts')->andThrow(new \RuntimeException('quota exceeded'));

        try {
            ImportRecentPostsJob::dispatchSync($yt->id);
            $this->fail('expected the listing failure to propagate so the queue retries it');
        } catch (\RuntimeException $e) {
            $this->assertSame('quota exceeded', $e->getMessage());
        }

        $this->assertSame('quota exceeded', $yt->fresh()->post_stats_error);
        $this->assertSame(0, PostMetricSnapshot::count());
    }

    public function test_the_backfill_command_queues_one_job_per_supported_connected_account(): void
    {
        Queue::fake();
        $yt = $this->account('youtube');
        $this->account('linkedin');                                  // no stats support
        $this->account('x', SocialAccount::STATUS_NEEDS_REAUTH);     // not connected

        $this->artisan('posts:import-recent')->assertExitCode(0);

        Queue::assertPushed(ImportRecentPostsJob::class, 1);
        Queue::assertPushed(ImportRecentPostsJob::class, fn ($job) => $job->socialAccountId === $yt->id);
    }

    public function test_the_backfill_command_can_target_one_account(): void
    {
        Queue::fake();
        $this->account('youtube');
        $x = $this->account('x');

        $this->artisan('posts:import-recent', ['--account' => $x->id])->assertExitCode(0);

        Queue::assertPushed(ImportRecentPostsJob::class, 1);
        Queue::assertPushed(ImportRecentPostsJob::class, fn ($job) => $job->socialAccountId === $x->id);
    }
}
