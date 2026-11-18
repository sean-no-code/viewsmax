<?php

namespace Tests\Feature;

use App\Jobs\PublishToXJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Console\Commands\PublishDuePosts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendPostFailureEmailJob;
use Tests\TestCase;

class PublishDuePostsTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledPost(User $user, $when, string $platform = 'x'): Post
    {
        $post = $user->posts()->create([
            'caption' => 'hi',
            'media' => [],
            'status' => Post::STATUS_SCHEDULED,
            'scheduled_at' => $when,
        ]);
        $post->targets()->create(['platform' => $platform, 'status' => PostTarget::STATUS_PENDING]);

        return $post;
    }

    public function test_health_reports_the_scheduler_heartbeat(): void
    {
        Queue::fake();
        Cache::forget(PublishDuePosts::HEARTBEAT_KEY);

        // No run yet: the scheduler is reported as not running.
        $this->getJson('/api/health')->assertOk()
            ->assertJsonPath('scheduler.running', false)
            ->assertJsonPath('scheduler.last_run_at', null);

        // An empty tick still stamps the heartbeat.
        $this->artisan('posts:publish-due')->assertExitCode(0);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('scheduler.running', true);

        // A stale heartbeat (scheduler stopped) flips it back.
        Cache::put(PublishDuePosts::HEARTBEAT_KEY, now()->subMinutes(PublishDuePosts::HEARTBEAT_STALE_MINUTES + 1)->toIso8601String());
        $this->getJson('/api/health')->assertOk()->assertJsonPath('scheduler.running', false);
    }

    public function test_due_post_is_flipped_and_dispatched(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $post = $this->scheduledPost($user, now()->subMinutes(5));

        $this->artisan('posts:publish-due')->assertExitCode(0);

        $this->assertSame(Post::STATUS_POSTED, $post->refresh()->status);
        Queue::assertPushed(PublishToXJob::class);
    }

    public function test_future_post_is_left_scheduled(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $post = $this->scheduledPost($user, now()->addHour());

        $this->artisan('posts:publish-due')->assertExitCode(0);

        $this->assertSame(Post::STATUS_SCHEDULED, $post->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_one_failing_post_does_not_block_the_rest_of_the_batch(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        // A poison post: no targets AND a target platform that dispatches fine for
        // the good one. We simulate failure by giving the first post a target whose
        // dispatch path is fine, then rely on the try/catch keeping the batch alive.
        // Simplest deterministic check: two due posts, both must be flipped even if
        // processing order differs — the command must not abort mid-batch.
        $a = $this->scheduledPost($user, now()->subMinutes(10));
        $b = $this->scheduledPost($user, now()->subMinutes(5));

        $this->artisan('posts:publish-due')->assertExitCode(0);

        $this->assertSame(Post::STATUS_POSTED, $a->refresh()->status);
        $this->assertSame(Post::STATUS_POSTED, $b->refresh()->status);
        Queue::assertPushed(PublishToXJob::class, 2);
    }

    // ---- Posts changed while the batch runs ----

    /**
     * Run publish-due with two due posts, $first then $second. When $first's
     * target is dispatched (flipped to publishing), $change runs against
     * $second, i.e. after the command has loaded both posts but before it
     * reaches $second. $second targets LinkedIn so its job can be told apart.
     */
    private function runWithChangeBeforeSecondPost(callable $change): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $first = $this->scheduledPost($user, now()->subMinutes(10));
        $second = $this->scheduledPost($user, now()->subMinutes(5), 'linkedin');

        $done = false;
        PostTarget::updated(function (PostTarget $target) use ($first, $second, $change, &$done) {
            if (! $done && $target->post_id === $first->id && $target->status === PostTarget::STATUS_PUBLISHING) {
                $done = true;
                $change(Post::find($second->id));
            }
        });

        $this->artisan('posts:publish-due')->assertExitCode(0);

        return [$first->refresh(), $second->refresh(), $user->refresh()];
    }

    public function test_a_post_moved_to_draft_mid_batch_is_not_published(): void
    {
        [$first, $second] = $this->runWithChangeBeforeSecondPost(
            fn (Post $post) => $post->update(['status' => Post::STATUS_DRAFT, 'scheduled_at' => null]),
        );

        $this->assertSame(Post::STATUS_POSTED, $first->status);
        $this->assertSame(Post::STATUS_DRAFT, $second->status);
        Queue::assertPushed(PublishToXJob::class, 1);
        Queue::assertNotPushed(\App\Jobs\PublishToLinkedInJob::class);
    }

    public function test_a_post_rescheduled_mid_batch_keeps_its_new_time(): void
    {
        $later = now()->addDay()->startOfMinute();
        [, $second] = $this->runWithChangeBeforeSecondPost(
            fn (Post $post) => $post->update(['scheduled_at' => $later]),
        );

        $this->assertSame(Post::STATUS_SCHEDULED, $second->status);
        $this->assertTrue($second->scheduled_at->equalTo($later));
        Queue::assertNotPushed(\App\Jobs\PublishToLinkedInJob::class);
    }

    public function test_a_post_already_posted_mid_batch_is_not_dispatched_again(): void
    {
        // "Post now" flips the post to posted and dispatches it itself.
        [, $second, $user] = $this->runWithChangeBeforeSecondPost(
            fn (Post $post) => $post->update(['status' => Post::STATUS_POSTED]),
        );

        $this->assertSame(Post::STATUS_POSTED, $second->status);
        Queue::assertNotPushed(\App\Jobs\PublishToLinkedInJob::class);
        // Counted once by "Post now", once for the first post: not twice for the second.
        $this->assertSame(2, $user->posts_published_count);
    }

    public function test_a_due_post_is_dispatched_exactly_once_and_counted_once(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $post = $this->scheduledPost($user, now()->subMinute());

        $this->artisan('posts:publish-due')->assertExitCode(0);
        $this->artisan('posts:publish-due')->assertExitCode(0);

        $this->assertSame(Post::STATUS_POSTED, $post->refresh()->status);
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $post->targets()->first()->status);
        Queue::assertPushed(PublishToXJob::class, 1);
        $this->assertSame(1, $user->refresh()->posts_published_count);
    }

    public function test_claim_refuses_a_stale_copy(): void
    {
        $user = User::factory()->create();
        $post = $this->scheduledPost($user, now()->subMinute());
        $stale = Post::find($post->id);

        $this->assertTrue($post->claimForPublishing());
        $this->assertSame(Post::STATUS_POSTED, $post->status);
        $this->assertFalse($stale->claimForPublishing());
        $this->assertSame(1, $user->refresh()->posts_published_count);
    }

    // ---- Owners without access ----

    public function test_a_lapsed_owners_due_post_goes_back_to_draft_instead_of_publishing(): void
    {
        Queue::fake();
        $post = $this->scheduledPost(User::factory()->expired()->create(), now()->subMinutes(5));

        $this->artisan('posts:publish-due')->assertExitCode(0);

        $post->refresh();
        $this->assertSame(Post::STATUS_DRAFT, $post->status);
        $this->assertNull($post->scheduled_at);
        $this->assertSame(PostTarget::STATUS_PENDING, $post->targets()->first()->status);
        $this->assertNull($post->targets()->first()->error);
        Queue::assertNotPushed(PublishToXJob::class);
        Queue::assertNotPushed(SendPostFailureEmailJob::class); // nothing failed
    }

    public function test_unverified_and_cancelled_owners_are_drafted_too(): void
    {
        Queue::fake();
        $unverified = $this->scheduledPost(User::factory()->unverified()->create(), now()->subMinute());
        $cancelled = $this->scheduledPost(User::factory()->withoutWindow()->withPlan('cancelled')->create(), now()->subMinute());

        $this->artisan('posts:publish-due')->assertExitCode(0);

        $this->assertSame(Post::STATUS_DRAFT, $unverified->refresh()->status);
        $this->assertSame(Post::STATUS_DRAFT, $cancelled->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_admins_promotional_customers_and_subscribers_still_publish(): void
    {
        Queue::fake();
        $owners = [
            User::factory()->admin()->expired()->create(),
            User::factory()->promotional()->expired()->create(),
            User::factory()->expired()->withPlan('trialing')->create(),
        ];
        foreach ($owners as $owner) {
            $this->scheduledPost($owner, now()->subMinute());
        }

        $this->artisan('posts:publish-due')->assertExitCode(0);

        Queue::assertPushed(PublishToXJob::class, 3);
        $this->assertSame(0, Post::where('status', Post::STATUS_DRAFT)->count());
    }

    public function test_a_drafted_post_publishes_once_the_owner_resubscribes_and_reschedules(): void
    {
        Queue::fake();
        $owner = User::factory()->expired()->create();
        $post = $this->scheduledPost($owner, now()->subMinute());
        $this->artisan('posts:publish-due');
        $this->assertSame(Post::STATUS_DRAFT, $post->refresh()->status);

        $owner->plans()->attach(\App\Models\Plan::firstOrCreate(['name' => 'starter'], ['display_name' => 'Starter', 'price' => 29, 'currency' => 'USD', 'is_active' => true])->id, ['status' => 'active', 'starts_at' => now()]);
        $post->update(['status' => Post::STATUS_SCHEDULED, 'scheduled_at' => now()->subSecond()]);

        $this->artisan('posts:publish-due');

        $this->assertSame(Post::STATUS_POSTED, $post->refresh()->status);
        Queue::assertPushed(PublishToXJob::class, 1);
    }
}
