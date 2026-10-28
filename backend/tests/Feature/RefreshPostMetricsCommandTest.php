<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * posts:refresh-metrics snapshots per-post engagement daily across BOTH posting
 * stores (Post/PostTarget + SocialPost/SocialPostTarget), keyed by
 * (platform, remote_post_id). engagement_total = likes+comments+shares+views.
 * Posts whose platform can't return metrics are skipped, not zeroed.
 */
class RefreshPostMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Analytics is the Pro tier upsell: metrics are only refreshed for a plan that has it.
        $this->user = $this->analyticsUser();
    }

    private function account(string $platform): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $this->user->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-1',
            'name' => ucfirst($platform),
            'access_token' => 'tok',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function legacyTarget(SocialAccount $account, string $remoteId, string $status = PostTarget::STATUS_PUBLISHED): PostTarget
    {
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'Legacy caption', 'status' => 'posted']);

        return PostTarget::create([
            'post_id' => $post->id,
            'platform' => $account->platform,
            'social_account_id' => $account->id,
            'status' => $status,
            'platform_post_id' => $remoteId,
            'published_at' => now()->subDay(),
        ]);
    }

    private function socialTarget(SocialAccount $account, string $remoteId): SocialPostTarget
    {
        $post = SocialPost::create(['user_id' => $this->user->id, 'content' => 'Social caption', 'status' => 'posted']);

        return SocialPostTarget::create([
            'social_post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'status' => SocialPostTarget::STATUS_PUBLISHED,
            'remote_post_id' => $remoteId,
            'remote_post_url' => 'https://ex/'.$remoteId,
            'published_at' => now()->subDay(),
        ]);
    }

    /** Bind a manager whose providers return metrics keyed by remote id. */
    private function fakeManager(array $metricsById): void
    {
        $provider = \Mockery::mock(\App\Services\Social\Contracts\SocialProviderInterface::class);
        $provider->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($a) => $a);
        $provider->shouldReceive('fetchPostMetrics')->andReturnUsing(function ($account, $ids) use ($metricsById) {
            $out = [];
            foreach ($ids as $id) {
                if (isset($metricsById[$id])) {
                    $out[$id] = $metricsById[$id];
                }
            }

            return $out;
        });

        $manager = \Mockery::mock(SocialProviderManager::class);
        $manager->shouldReceive('supports')->andReturn(true);
        $manager->shouldReceive('supportsStats')->andReturn(true);
        $manager->shouldReceive('for')->andReturn($provider);
        $this->app->instance(SocialProviderManager::class, $manager);
    }

    public function test_posts_known_only_from_an_earlier_import_keep_being_refreshed(): void
    {
        $yt = $this->account('youtube');
        // Pulled in by ImportRecentPostsJob on connect — no post target exists for it.
        PostMetricSnapshot::create([
            'user_id' => $this->user->id, 'social_account_id' => $yt->id, 'platform' => 'youtube',
            'remote_post_id' => 'imported1', 'url' => 'https://yt/imported1', 'caption_excerpt' => 'Imported',
            'thumbnail_url' => 'https://cdn/imported1.jpg',
            'published_at' => now()->subDays(10), 'likes' => 1, 'engagement_total' => 1,
            'snapshot_date' => now()->subDay()->toDateString(),
        ]);
        $this->fakeManager(['imported1' => ['likes' => 5, 'comments' => 0, 'shares' => 0, 'views' => 100]]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $today = PostMetricSnapshot::where('remote_post_id', 'imported1')->where('snapshot_date', now()->toDateString())->sole();
        $this->assertSame(105, $today->engagement_total);
        $this->assertSame('https://yt/imported1', $today->url);
        $this->assertSame('Imported', $today->caption_excerpt);
        $this->assertSame('https://cdn/imported1.jpg', $today->thumbnail_url); // carried forward when the metrics bring none
        $this->assertSame($yt->id, $today->social_account_id);
        $this->assertSame(2, PostMetricSnapshot::count());
    }

    public function test_a_fresh_thumbnail_from_the_metrics_replaces_the_stored_one(): void
    {
        $ig = $this->account('instagram');
        PostMetricSnapshot::create([
            'user_id' => $this->user->id, 'social_account_id' => $ig->id, 'platform' => 'instagram',
            'remote_post_id' => 'm1', 'thumbnail_url' => 'https://cdn/expired.jpg', 'snapshot_date' => now()->subDay()->toDateString(),
        ]);
        $this->fakeManager(['m1' => ['likes' => 1, 'comments' => 0, 'shares' => 0, 'views' => 0, 'thumbnail' => 'https://cdn/fresh.jpg']]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $today = PostMetricSnapshot::where('remote_post_id', 'm1')->where('snapshot_date', now()->toDateString())->sole();
        $this->assertSame('https://cdn/fresh.jpg', $today->thumbnail_url);
    }

    public function test_the_post_stores_caption_wins_over_an_earlier_snapshots(): void
    {
        $yt = $this->account('youtube');
        $this->socialTarget($yt, 'vid1');
        PostMetricSnapshot::create([
            'user_id' => $this->user->id, 'social_account_id' => $yt->id, 'platform' => 'youtube',
            'remote_post_id' => 'vid1', 'caption_excerpt' => 'Stale title', 'snapshot_date' => now()->subDay()->toDateString(),
        ]);
        $this->fakeManager(['vid1' => ['likes' => 1, 'comments' => 0, 'shares' => 0, 'views' => 0]]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $today = PostMetricSnapshot::where('remote_post_id', 'vid1')->where('snapshot_date', now()->toDateString())->sole();
        $this->assertSame('Social caption', $today->caption_excerpt);
    }

    public function test_snapshots_engagement_across_both_stores(): void
    {
        $x = $this->account('x');
        $yt = $this->account('youtube');
        $this->legacyTarget($x, 'tweet1');
        $this->socialTarget($yt, 'vid1');

        $this->fakeManager([
            'tweet1' => ['likes' => 10, 'comments' => 2, 'shares' => 3, 'views' => 0],
            'vid1' => ['likes' => 5, 'comments' => 1, 'shares' => 0, 'views' => 1000],
        ]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->assertSame(2, PostMetricSnapshot::count());
        $tweet = PostMetricSnapshot::where('remote_post_id', 'tweet1')->first();
        $this->assertSame(15, $tweet->engagement_total); // 10+2+3+0
        $this->assertSame('x', $tweet->platform);
        $this->assertSame($this->user->id, $tweet->user_id);
        $vid = PostMetricSnapshot::where('remote_post_id', 'vid1')->first();
        $this->assertSame(1006, $vid->engagement_total); // 5+1+0+1000
    }

    public function test_records_a_fetch_failure_on_the_account_and_clears_it_on_success(): void
    {
        $x = $this->account('x');
        $this->legacyTarget($x, 'tweet1');

        $provider = \Mockery::mock(\App\Services\Social\Contracts\SocialProviderInterface::class);
        $provider->shouldReceive('ensureFreshToken')->andReturnUsing(fn ($a) => $a);
        $provider->shouldReceive('fetchPostMetrics')->once()->andThrow(new \RuntimeException('X API plan does not allow tweet lookups'));
        $manager = \Mockery::mock(SocialProviderManager::class);
        $manager->shouldReceive('supports')->andReturn(true);
        $manager->shouldReceive('supportsStats')->andReturn(true);
        $manager->shouldReceive('for')->andReturn($provider);
        $this->app->instance(SocialProviderManager::class, $manager);

        $this->artisan('posts:refresh-metrics')->assertExitCode(1);
        $this->assertSame('X API plan does not allow tweet lookups', $x->fresh()->post_stats_error);
        $this->assertSame(0, PostMetricSnapshot::count());

        $this->fakeManager(['tweet1' => ['likes' => 4, 'comments' => 0, 'shares' => 0, 'views' => 0]]);
        $this->artisan('posts:refresh-metrics')->assertExitCode(0);
        $this->assertNull($x->fresh()->post_stats_error);
        $this->assertSame(1, PostMetricSnapshot::count());
    }

    public function test_skips_posts_with_no_metrics_returned(): void
    {
        $x = $this->account('x');
        $this->legacyTarget($x, 'tweet1');
        $this->fakeManager([]); // provider returns nothing

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->assertSame(0, PostMetricSnapshot::count());
    }

    public function test_dedupes_same_remote_post_across_stores(): void
    {
        $x = $this->account('x');
        $this->legacyTarget($x, 'dup1');
        $this->socialTarget($x, 'dup1');
        $this->fakeManager(['dup1' => ['likes' => 4, 'comments' => 0, 'shares' => 0, 'views' => 0]]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->assertSame(1, PostMetricSnapshot::count());
    }

    public function test_upserts_one_row_per_post_per_day(): void
    {
        $x = $this->account('x');
        $this->legacyTarget($x, 'tweet1');

        $this->fakeManager(['tweet1' => ['likes' => 4, 'comments' => 0, 'shares' => 0, 'views' => 0]]);
        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->fakeManager(['tweet1' => ['likes' => 9, 'comments' => 1, 'shares' => 0, 'views' => 0]]);
        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->assertSame(1, PostMetricSnapshot::count());
        $this->assertSame(10, PostMetricSnapshot::first()->engagement_total);
    }

    public function test_ignores_unpublished_and_accountless_targets(): void
    {
        $x = $this->account('x');
        $this->legacyTarget($x, 'pending1', PostTarget::STATUS_PENDING);
        $this->fakeManager(['pending1' => ['likes' => 4, 'comments' => 0, 'shares' => 0, 'views' => 0]]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->assertSame(0, PostMetricSnapshot::count());
    }

    public function test_posts_of_users_without_access_are_skipped(): void
    {
        $account = $this->account('x');
        $this->legacyTarget($account, 'lapsed1');
        // Lapsed means both: the free window closed and the plan that gave this
        // user analytics in the first place (setUp) is no longer live.
        $this->user->forceFill(['promo_expires_at' => now()->subDay()])->save();
        $this->user->plans()->updateExistingPivot(
            $this->user->plans()->pluck('plans.id')->all(),
            ['status' => 'cancelled'],
        );
        $this->fakeManager(['lapsed1' => ['likes' => 5, 'comments' => 0, 'shares' => 0, 'views' => 10]]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->assertSame(0, PostMetricSnapshot::count());
    }

    public function test_posts_of_users_on_a_plan_without_analytics_are_skipped(): void
    {
        // Analytics is the Pro tier upsell: a creator-tier user in good standing
        // keeps their posts but costs no provider calls.
        $account = $this->account('x');
        $this->legacyTarget($account, 'creator1');
        $this->user->plans()->detach();
        $this->user->plans()->attach($this->planNamed('creator')->id, ['status' => 'active', 'starts_at' => now()]);
        $this->assertSame('creator', $this->user->fresh()->activePlan()->name);
        $this->fakeManager(['creator1' => ['likes' => 5, 'comments' => 0, 'shares' => 0, 'views' => 10]]);

        $this->artisan('posts:refresh-metrics')->assertExitCode(0);

        $this->assertSame(0, PostMetricSnapshot::count());
    }
}
