<?php

namespace Tests\Feature;

use App\Jobs\PublishToXJob;
use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileStuckPostsTest extends TestCase
{
    use RefreshDatabase;

    private function postedPost(): Post
    {
        return User::factory()->create()->posts()->create([
            'caption' => 'x',
            'media' => [],
            'status' => Post::STATUS_POSTED,
            'scheduled_at' => now()->subHour(),
        ]);
    }

    public function test_dry_run_reports_but_changes_nothing(): void
    {
        Queue::fake();
        $post = $this->postedPost();
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHING]);

        $this->artisan('posts:reconcile-stuck --minutes=0')->assertExitCode(0);

        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_requeue_redispatches_never_posted_targets_and_heals_already_posted_ones(): void
    {
        Queue::fake();
        $post = $this->postedPost();
        // Never posted (no platform_post_id) → should be re-dispatched.
        $x = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHING]);
        // Already on the platform → must NOT be re-dispatched (double-post), just healed.
        $yt = $post->targets()->create(['platform' => 'youtube', 'status' => PostTarget::STATUS_PUBLISHING, 'platform_post_id' => 'vid123']);

        $this->artisan('posts:reconcile-stuck --requeue --minutes=0')->assertExitCode(0);

        Queue::assertPushed(PublishToXJob::class, 1);
        Queue::assertNotPushed(PublishYouTubeJob::class);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $yt->refresh()->status);
        $this->assertNotNull($yt->published_at);
    }

    public function test_fail_marks_stuck_targets_failed(): void
    {
        Queue::fake();
        $post = $this->postedPost();
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);

        $this->artisan('posts:reconcile-stuck --fail --minutes=0')->assertExitCode(0);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertNotEmpty($target->error);
        // No publish job may be re-dispatched; the failed transition does
        // schedule the user's failure-notification email, which is expected.
        Queue::assertNotPushed(PublishToXJob::class);
        Queue::assertPushed(\App\Jobs\SendPostFailureEmailJob::class, 1);
    }

    public function test_in_flight_targets_within_window_are_left_alone(): void
    {
        Queue::fake();
        $post = $this->postedPost();
        // Just updated (default 15-min window) → still in-flight, must be skipped.
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHING]);

        $this->artisan('posts:reconcile-stuck --requeue')->assertExitCode(0);

        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_requeue_and_fail_together_is_rejected(): void
    {
        $this->artisan('posts:reconcile-stuck --requeue --fail')->assertExitCode(1);
    }
}
