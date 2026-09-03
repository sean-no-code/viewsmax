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
        $this->user = User::factory()->create();
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
        $manager->shouldReceive('for')->andReturn($provider);
        $this->app->instance(SocialProviderManager::class, $manager);
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
}
