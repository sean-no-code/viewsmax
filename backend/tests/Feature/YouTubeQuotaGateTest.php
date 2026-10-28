<?php

namespace Tests\Feature;

use App\Contracts\Quota\QuotaDecision;
use App\Contracts\Quota\QuotaGate;
use App\Jobs\ProcessYouTubeSearchTermJob;
use App\Jobs\PublishToXJob;
use App\Jobs\PublishYouTubeJob;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SearchTerm;
use App\Models\TermsDataFetch;
use App\Models\User;
use App\Services\Social\PostPublishDispatcher;
use App\Services\YouTubeSearchService;
use App\Support\Quota\CacheQuotaGate;
use App\Support\Quota\Tier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The quota seams: the only places the app asks "may I spend YouTube quota?".
 * The gate itself is a contract (App\Contracts\Quota\QuotaGate) with a
 * no-op default, so the app works unchanged without the quota package; these
 * tests drive the seams with a stub gate and check the app's side of the deal.
 */
class YouTubeQuotaGateTest extends TestCase
{
    use RefreshDatabase;

    /** A gate that answers from a script and remembers what it was asked. */
    private function stubGate(bool $allowed, string $scope = QuotaDecision::SCOPE_PROJECT): object
    {
        $gate = new class($allowed, $scope) implements QuotaGate {
            public array $asked = [];

            public array $recorded = [];

            public function __construct(private bool $allowed, private string $scope) {}

            public function allows(string $resource, string $tier, int $calls = 1, ?int $userId = null): QuotaDecision
            {
                $this->asked[] = compact('resource', 'tier', 'calls', 'userId');
                $resetsAt = new \DateTimeImmutable('2026-10-29 07:00:00', new \DateTimeZone('UTC'));

                if ($this->allowed) {
                    return QuotaDecision::allow($resource, $tier, used: 10, cap: 48, resetsAt: $resetsAt);
                }

                return $this->scope === QuotaDecision::SCOPE_USER
                    ? QuotaDecision::denyUser($resource, $tier, used: 2, cap: 2, resetsAt: $resetsAt)
                    : QuotaDecision::deny($resource, $tier, used: 48, cap: 48, resetsAt: $resetsAt);
            }

            public function recordUserFetch(int $userId, string $resource): void
            {
                $this->recorded[] = compact('userId', 'resource');
            }
        };
        $this->app->instance(QuotaGate::class, $gate);

        return $gate;
    }

    private function searchAs(User $user, string $term)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->postJson('/api/outliers/search', ['term' => $term]);
    }

    public function test_the_app_binds_the_cache_gate(): void
    {
        $this->assertInstanceOf(CacheQuotaGate::class, app(QuotaGate::class));
    }

    public function test_a_search_is_queued_when_the_gate_allows(): void
    {
        Queue::fake();
        $this->stubGate(true);

        $this->searchAs(User::factory()->create(), 'ai agents')
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(ProcessYouTubeSearchTermJob::class);
    }

    public function test_a_search_is_refused_not_queued_when_the_shared_budget_is_gone(): void
    {
        Queue::fake();
        $this->stubGate(false);

        $this->searchAs(User::factory()->create(), 'ai agents')
            ->assertStatus(429)
            ->assertJsonPath('code', 'quota_paused')
            ->assertJsonPath('status', 'quota_paused')
            ->assertJsonPath('scope', 'project')
            ->assertJsonPath('plan', 'Free')
            ->assertJsonPath('upgrade', true)
            ->assertJsonPath('resets_at', '2026-10-29T07:00:00+00:00')
            ->assertJsonPath('message', "ViewsMax's YouTube search budget for today is used up — showing results from the library; fresh YouTube searches resume at midnight Pacific time. Upgrade for priority access.");

        Queue::assertNothingPushed();
        // The term is still remembered (the library search still serves it) but no fetch is pending.
        $this->assertNotNull(SearchTerm::where('term', 'ai agents')->first());
        $this->assertNull(TermsDataFetch::first());
    }

    public function test_a_free_user_over_their_own_allowance_is_told_their_plan_and_offered_an_upgrade(): void
    {
        Queue::fake();
        $this->stubGate(false, QuotaDecision::SCOPE_USER);

        $this->searchAs(User::factory()->create(), 'ai agents')
            ->assertStatus(429)
            ->assertJsonPath('scope', 'user')
            ->assertJsonPath('reason', 'user_limit')
            ->assertJsonPath('cap', 2)
            ->assertJsonPath('upgrade', true)
            ->assertJsonPath('message', "You've used today's 2 fresh YouTube searches on the Free plan. They reset at midnight Pacific time. Upgrade to get higher limits.");
    }

    public function test_a_paid_user_over_their_allowance_is_told_their_plan_without_an_upsell(): void
    {
        Queue::fake();
        $this->stubGate(false, QuotaDecision::SCOPE_USER);
        $pro = Plan::firstOrCreate(['name' => 'pro'], ['display_name' => 'Pro', 'price' => 79, 'currency' => 'USD', 'is_active' => true, 'stripe_price_id' => 'price_pro']);
        $user = User::factory()->withPlan('active', $pro)->create();

        $this->searchAs($user, 'ai agents')
            ->assertStatus(429)
            ->assertJsonPath('plan', 'Pro')
            ->assertJsonPath('upgrade', false)
            ->assertJsonPath('message', "You've used today's 2 fresh YouTube searches on the Pro plan. They reset at midnight Pacific time.");
    }

    public function test_the_gate_is_asked_for_what_one_term_costs_and_who_is_asking(): void
    {
        Queue::fake();
        config(['services.youtube.search_pages' => 1]); // 2 duration buckets × 1 page
        $gate = $this->stubGate(true);
        $admin = User::factory()->admin()->create();

        $this->searchAs($admin, 'ai agents');

        $this->assertSame([['resource' => 'search_list', 'tier' => 'admin', 'calls' => 2, 'userId' => $admin->id]], $gate->asked);
        $this->assertSame([['userId' => $admin->id, 'resource' => 'search_list']], $gate->recorded, 'a dispatched fetch counts against the user');
    }

    public function test_a_cached_search_neither_asks_the_gate_nor_counts_against_the_user(): void
    {
        Queue::fake();
        config(['services.youtube.fetch_period_days' => 30]);
        $gate = $this->stubGate(true);
        $term = SearchTerm::create(['term' => 'ai agents']);
        TermsDataFetch::create(['term_id' => $term->id, 'status' => TermsDataFetch::STATUS_DONE, 'fetched_at' => now()->subDay()]);

        $this->searchAs(User::factory()->create(), 'ai agents')->assertOk()->assertJsonPath('status', 'queued');

        Queue::assertNothingPushed();
        $this->assertSame([], $gate->asked);
        $this->assertSame([], $gate->recorded);
    }

    public function test_the_job_itself_does_not_spend_when_the_gate_denies(): void
    {
        // Sub-term jobs are dispatched by the job, not by search(), so the spend
        // point has its own check. A denied term goes back to queued, which
        // shouldFetch() treats as "fetch next time someone asks".
        Http::fake();
        $this->stubGate(false);
        $user = User::factory()->create();
        $term = SearchTerm::create(['term' => 'ai agents']);

        app(YouTubeSearchService::class)->fetchAndProcess('ai agents', $term, $user);

        Http::assertNothingSent();
        $this->assertSame(TermsDataFetch::STATUS_QUEUED, $term->refresh()->termsDataFetch?->status);
    }

    public function test_tier_mapping(): void
    {
        $this->assertSame('free', Tier::of(null));
        $this->assertSame('free', Tier::of(User::factory()->create()), 'free window only');
        $this->assertSame('free', Tier::of(User::factory()->withPlan('cancelled')->create()));
        $this->assertSame('paid', Tier::of(User::factory()->withPlan('active')->create()));
        $this->assertSame('paid', Tier::of(User::factory()->withPlan('trialing')->create()));
        $this->assertSame('paid', Tier::of(User::factory()->promotional()->create()), 'comped customers are not rationed as free');
        $this->assertSame('admin', Tier::of(User::factory()->admin()->create()));
    }

    private function postWithTarget(string $platform): PostTarget
    {
        $post = Post::create(['user_id' => User::factory()->create()->id, 'caption' => 'hi', 'status' => Post::STATUS_POSTED]);

        return PostTarget::create(['post_id' => $post->id, 'platform' => $platform, 'status' => PostTarget::STATUS_PENDING]);
    }

    public function test_a_youtube_publish_is_deferred_when_the_gate_denies(): void
    {
        Queue::fake();
        $gate = $this->stubGate(false);
        $target = $this->postWithTarget('youtube');

        $this->assertTrue(PostPublishDispatcher::dispatchTarget($target));

        Queue::assertNotPushed(PublishYouTubeJob::class);
        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PENDING, $target->status, 'left pending for posts:reconcile-stuck --requeue to retry');
        $this->assertSame('2026-10-29T07:00:00+00:00', $target->meta['quota_paused_until']);
        $this->assertSame('videos_insert', $gate->asked[0]['resource']);
        $this->assertSame($target->post->user_id, $gate->asked[0]['userId'], 'the owner\'s own allowance is checked');
        $this->assertStringContainsString('YouTube upload budget', $target->meta['quota_message']);
    }

    public function test_a_youtube_publish_is_dispatched_when_the_gate_allows(): void
    {
        Queue::fake();
        $gate = $this->stubGate(true);
        $target = $this->postWithTarget('youtube');

        PostPublishDispatcher::dispatchTarget($target);

        Queue::assertPushed(PublishYouTubeJob::class);
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->refresh()->status);
        $this->assertSame([['userId' => $target->post->user_id, 'resource' => 'videos_insert']], $gate->recorded);
    }

    public function test_a_requeued_youtube_target_is_not_counted_against_the_owner_twice(): void
    {
        // posts:reconcile-stuck --requeue re-dispatches a stuck target: same upload, not a second one.
        Queue::fake();
        $gate = $this->stubGate(true);
        $target = $this->postWithTarget('youtube');

        PostPublishDispatcher::dispatchTarget($target);
        PostPublishDispatcher::dispatchTarget($target->refresh());

        $this->assertCount(1, $gate->recorded);
        $this->assertNull($gate->asked[1]['userId'], 'second time only the shared cap is checked');
    }

    public function test_an_owner_over_their_upload_allowance_is_told_on_the_target(): void
    {
        Queue::fake();
        $this->stubGate(false, QuotaDecision::SCOPE_USER);
        $target = $this->postWithTarget('youtube');

        PostPublishDispatcher::dispatchTarget($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PENDING, $target->status);
        $this->assertSame("You've used today's 2 YouTube uploads on the Free plan. They reset at midnight Pacific time. Upgrade to get higher limits.", $target->meta['quota_message']);
    }

    public function test_other_platforms_are_never_gated(): void
    {
        Queue::fake();
        $gate = $this->stubGate(false);
        $target = $this->postWithTarget('x');

        PostPublishDispatcher::dispatchTarget($target);

        Queue::assertPushed(PublishToXJob::class);
        $this->assertSame([], $gate->asked);
    }
}
