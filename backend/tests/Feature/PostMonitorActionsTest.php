<?php

namespace Tests\Feature;

use App\Jobs\PublishToXJob;
use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostMonitorActionsTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Admin']));
        $token = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makePost(User $user, string $status, $scheduledAt = null): Post
    {
        return $user->posts()->create([
            'caption' => 'c', 'media' => [], 'status' => $status, 'scheduled_at' => $scheduledAt,
        ]);
    }

    public function test_index_filters_by_post_status_scheduled(): void
    {
        $headers = $this->adminHeaders();
        $owner = User::factory()->create();
        $sched = $this->makePost($owner, Post::STATUS_SCHEDULED, now()->addDay());
        $sched->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);
        $posted = $this->makePost($owner, Post::STATUS_POSTED);
        $posted->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHED]);

        $rows = $this->withHeaders($headers)->getJson('/api/admin/posts?post_status=scheduled')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($sched->id, $rows[0]['id']);
    }

    public function test_index_overdue_filter_returns_only_past_due_scheduled(): void
    {
        $headers = $this->adminHeaders();
        $owner = User::factory()->create();
        $overdue = $this->makePost($owner, Post::STATUS_SCHEDULED, now()->subHour());
        $future = $this->makePost($owner, Post::STATUS_SCHEDULED, now()->addDay());

        $ids = collect($this->withHeaders($headers)->getJson('/api/admin/posts?overdue=1')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($overdue->id));
        $this->assertFalse($ids->contains($future->id));
    }

    public function test_index_filters_by_platform(): void
    {
        $headers = $this->adminHeaders();
        $owner = User::factory()->create();
        $a = $this->makePost($owner, Post::STATUS_POSTED);
        $a->targets()->create(['platform' => 'tiktok', 'status' => PostTarget::STATUS_PUBLISHED]);
        $b = $this->makePost($owner, Post::STATUS_POSTED);
        $b->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHED]);

        $ids = collect($this->withHeaders($headers)->getJson('/api/admin/posts?platform=tiktok')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($a->id));
        $this->assertFalse($ids->contains($b->id));
    }

    public function test_reconcile_scan_reports_counts_without_mutating(): void
    {
        Queue::fake();
        $headers = $this->adminHeaders();
        $owner = User::factory()->create();

        // Stuck: publishing target on a posted post, untouched > 15 min.
        $posted = $this->makePost($owner, Post::STATUS_POSTED);
        $t = $posted->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHING]);
        PostTarget::where('id', $t->id)->update(['updated_at' => now()->subMinutes(20)]);

        // Overdue scheduled (not a stuck target — post isn't posted).
        $this->makePost($owner, Post::STATUS_SCHEDULED, now()->subHour());

        $data = $this->withHeaders($headers)->postJson('/api/admin/posts/reconcile')->assertOk()->json('data');

        $this->assertSame(1, $data['stuck_targets']);
        $this->assertSame(1, $data['requeueable']);
        $this->assertSame(0, $data['already_on_platform']);
        $this->assertSame(1, $data['overdue_scheduled']);
        // Read-only: nothing dispatched, status unchanged.
        Queue::assertNothingPushed();
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $t->refresh()->status);
    }

    public function test_requeue_redispatches_stuck_never_posted_target(): void
    {
        Queue::fake();
        $headers = $this->adminHeaders();
        $post = $this->makePost(User::factory()->create(), Post::STATUS_POSTED);
        $t = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHING]);
        // Stuck for 20 min → past the in-flight window → safe to re-dispatch.
        PostTarget::where('id', $t->id)->update(['updated_at' => now()->subMinutes(20)]);

        $data = $this->withHeaders($headers)->postJson("/api/admin/posts/{$post->id}/requeue")->assertOk()->json('data');

        $this->assertSame(1, $data['requeued']);
        Queue::assertPushed(PublishToXJob::class, 1);
    }

    public function test_requeue_does_not_redispatch_in_flight_publishing_target(): void
    {
        Queue::fake();
        $headers = $this->adminHeaders();
        $post = $this->makePost(User::factory()->create(), Post::STATUS_POSTED);
        // Just started publishing (updated_at = now) → job may still be running →
        // must NOT be re-dispatched (would double-post).
        $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHING]);

        $data = $this->withHeaders($headers)->postJson("/api/admin/posts/{$post->id}/requeue")->assertOk()->json('data');

        $this->assertSame(0, $data['requeued']);
        $this->assertSame(1, $data['skipped']);
        Queue::assertNothingPushed();
    }

    public function test_requeue_redispatches_failed_target_immediately(): void
    {
        Queue::fake();
        $headers = $this->adminHeaders();
        $post = $this->makePost(User::factory()->create(), Post::STATUS_POSTED);
        // Failed is terminal (no job in flight) → safe to retry even if just updated.
        $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_FAILED, 'error' => 'boom']);

        $data = $this->withHeaders($headers)->postJson("/api/admin/posts/{$post->id}/requeue")->assertOk()->json('data');

        $this->assertSame(1, $data['requeued']);
        Queue::assertPushed(PublishToXJob::class, 1);
    }

    public function test_requeue_heals_already_posted_target_without_redispatch(): void
    {
        Queue::fake();
        $headers = $this->adminHeaders();
        $post = $this->makePost(User::factory()->create(), Post::STATUS_POSTED);
        $t = $post->targets()->create(['platform' => 'youtube', 'status' => PostTarget::STATUS_PUBLISHING, 'platform_post_id' => 'vid123']);

        $data = $this->withHeaders($headers)->postJson("/api/admin/posts/{$post->id}/requeue")->assertOk()->json('data');

        $this->assertSame(1, $data['healed']);
        $this->assertSame(0, $data['requeued']);
        Queue::assertNotPushed(PublishYouTubeJob::class);
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $t->refresh()->status);
    }

    public function test_requeue_promotes_overdue_scheduled_post_then_dispatches(): void
    {
        Queue::fake();
        $headers = $this->adminHeaders();
        $post = $this->makePost(User::factory()->create(), Post::STATUS_SCHEDULED, now()->subHour());
        $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);

        $data = $this->withHeaders($headers)->postJson("/api/admin/posts/{$post->id}/requeue")->assertOk()->json('data');

        $this->assertSame(Post::STATUS_POSTED, $post->refresh()->status);
        $this->assertSame(Post::STATUS_POSTED, $data['status']);
        $this->assertSame(1, $data['requeued']);
        Queue::assertPushed(PublishToXJob::class);
    }

    public function test_reconcile_and_requeue_require_admin(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $post = $this->makePost($user, Post::STATUS_POSTED);

        $this->withHeaders($headers)->postJson('/api/admin/posts/reconcile')->assertForbidden();
        $this->withHeaders($headers)->postJson("/api/admin/posts/{$post->id}/requeue")->assertForbidden();
    }
}
