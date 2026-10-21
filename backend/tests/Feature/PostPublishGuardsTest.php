<?php

namespace Tests\Feature;

use App\Jobs\PublishToLinkedInJob;
use App\Jobs\PublishToXJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Save-time guards on POST/PUT /api/posts that the MCP tools already had:
 * a past scheduled_at, an unknown or switched-off platform, and re-saving a
 * post that has already gone live somewhere (which must never publish again).
 */
class PostPublishGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function createPost(array $payload)
    {
        return $this->withHeaders($this->auth())->postJson('/api/posts', $payload);
    }

    private function updatePost(Post $post, array $payload)
    {
        return $this->withHeaders($this->auth())->putJson("/api/posts/{$post->id}", $payload);
    }

    // ── past scheduled_at ─────────────────────────────────────────────────

    public function test_scheduling_in_the_past_is_rejected(): void
    {
        $this->createPost([
            'caption' => 'hi', 'platforms' => ['x'], 'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5)->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);

        $this->assertSame(0, Post::count());
    }

    public function test_a_time_within_the_grace_window_is_accepted(): void
    {
        // Clock skew / the scheduler's one-minute tick: "a few seconds ago" is now.
        $this->createPost([
            'caption' => 'hi', 'platforms' => ['x'], 'status' => 'scheduled',
            'scheduled_at' => now()->subSeconds(20)->toIso8601String(),
        ])->assertStatus(201);
    }

    public function test_past_time_is_rejected_whatever_offset_it_is_sent_in(): void
    {
        $this->createPost([
            'caption' => 'hi', 'platforms' => ['x'], 'status' => 'scheduled',
            'scheduled_at' => now()->subHours(2)->setTimezone('+03:00')->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_drafts_may_carry_a_past_time(): void
    {
        $this->createPost([
            'caption' => 'hi', 'platforms' => ['x'], 'status' => 'draft',
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(201);
    }

    public function test_update_rejects_moving_to_the_past_but_leaves_an_overdue_caption_edit_alone(): void
    {
        $post = $this->user->posts()->create([
            'caption' => 'hi', 'media' => [], 'status' => Post::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour(),
        ]);
        $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);

        $this->updatePost($post, ['scheduled_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);

        // The scheduler is about to pick this one up; editing its text is fine.
        $post->forceFill(['scheduled_at' => now()->subMinutes(3)])->save();
        $this->updatePost($post, ['caption' => 'edited'])->assertOk();

        // But a draft can't be promoted into an already-passed slot.
        $draft = $this->user->posts()->create([
            'caption' => 'hi', 'media' => [], 'status' => Post::STATUS_DRAFT, 'scheduled_at' => now()->subHour(),
        ]);
        $draft->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);
        $this->updatePost($draft, ['status' => 'scheduled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    // ── platform validation ───────────────────────────────────────────────

    public function test_unknown_platform_is_rejected(): void
    {
        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['google_business'], 'status' => 'draft']);

        $response->assertStatus(422);
        $this->assertStringContainsString('google_business', $response->json('message'));
        $this->assertStringContainsString('Supported platforms', $response->json('message'));
        $this->assertSame(0, Post::count());
    }

    public function test_unknown_platform_in_targets_is_rejected(): void
    {
        $this->createPost(['caption' => 'hi', 'targets' => [['platform' => 'pinterest']], 'status' => 'draft'])
            ->assertStatus(422);
    }

    public function test_switched_off_platform_is_rejected_with_its_label(): void
    {
        config(['social.platforms.linkedin.enabled' => false]);

        $response = $this->createPost(['caption' => 'hi', 'platforms' => ['x', 'linkedin'], 'status' => 'draft']);

        $response->assertStatus(422);
        $this->assertStringContainsString('LinkedIn', $response->json('message'));
        $this->assertSame(0, Post::count());
    }

    public function test_every_dispatcher_platform_is_accepted_when_enabled(): void
    {
        $this->createPost([
            'caption' => 'hi', 'status' => 'draft',
            'platforms' => ['tiktok', 'youtube', 'x', 'linkedin', 'threads', 'instagram', 'facebook', 'bluesky'],
        ])->assertStatus(201);
    }

    public function test_update_validates_newly_added_platforms(): void
    {
        $post = $this->user->posts()->create(['caption' => 'hi', 'media' => [], 'status' => Post::STATUS_DRAFT]);
        $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);

        $this->updatePost($post, ['platforms' => ['x', 'myspace']])->assertStatus(422);
        $this->assertSame(['x'], $post->fresh()->targets->pluck('platform')->all());
    }

    // ── republish guard ───────────────────────────────────────────────────

    private function livePost(): Post
    {
        $post = $this->user->posts()->create(['caption' => 'Live', 'media' => [], 'status' => Post::STATUS_POSTED]);
        $post->targets()->create([
            'platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHED, 'platform_post_id' => 'tw1',
        ]);
        $post->targets()->create([
            'platform' => 'linkedin', 'status' => PostTarget::STATUS_FAILED, 'error' => 'boom',
        ]);

        return $post;
    }

    public function test_a_post_with_a_published_target_cannot_go_back_to_scheduled_or_draft(): void
    {
        $post = $this->livePost();

        $response = $this->updatePost($post, ['status' => 'scheduled', 'scheduled_at' => now()->addHour()->toIso8601String()]);
        $response->assertStatus(422);
        $this->assertStringContainsString('already been published to X', $response->json('message'));

        $this->updatePost($post, ['status' => 'draft'])->assertStatus(422);

        $this->assertSame(Post::STATUS_POSTED, $post->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_resaving_a_posted_post_never_redispatches_a_published_target(): void
    {
        $post = $this->livePost();

        $this->updatePost($post, ['caption' => 'Edited', 'status' => 'posted', 'platforms' => ['x', 'linkedin']])
            ->assertOk();

        $x = $post->targets()->where('platform', 'x')->sole();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $x->status);
        $this->assertSame('tw1', $x->platform_post_id);
        Queue::assertNotPushed(PublishToXJob::class);
        Queue::assertNotPushed(PublishToLinkedInJob::class);
    }

    public function test_dropping_a_published_platform_from_the_targets_keeps_its_record(): void
    {
        $post = $this->livePost();

        // Leaving X out of the list must not delete the live target — a later
        // re-add would otherwise create a fresh pending target that publishes again.
        $this->updatePost($post, ['platforms' => ['linkedin']])->assertOk();

        $this->assertEqualsCanonicalizing(['x', 'linkedin'], $post->fresh()->targets->pluck('platform')->all());
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $post->targets()->where('platform', 'x')->sole()->status);

        $this->updatePost($post, ['platforms' => ['x', 'linkedin']])->assertOk();
        $this->assertSame(1, $post->targets()->where('platform', 'x')->count());
    }

    public function test_publishing_dispatches_only_targets_that_are_not_live(): void
    {
        // A post whose status lost the race (e.g. an old row) but already has a
        // live X target: flipping it to posted must not re-queue X.
        $post = $this->user->posts()->create(['caption' => 'Live', 'media' => [], 'status' => Post::STATUS_DRAFT]);
        $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHED, 'platform_post_id' => 'tw1']);
        $post->targets()->create(['platform' => 'linkedin', 'status' => PostTarget::STATUS_PENDING]);

        // The status guard refuses the move while X is live...
        $this->updatePost($post, ['status' => 'scheduled', 'scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertStatus(422);

        // ...and even when the post goes to posted, only LinkedIn is queued.
        $this->updatePost($post, ['status' => 'posted'])->assertOk();
        Queue::assertNotPushed(PublishToXJob::class);
        Queue::assertPushed(PublishToLinkedInJob::class, 1);
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $post->targets()->where('platform', 'x')->sole()->status);
    }

    public function test_retrying_a_failed_target_on_a_live_post_still_works(): void
    {
        $post = $this->livePost();
        $failed = $post->targets()->where('platform', 'linkedin')->sole();

        $this->withHeaders($this->auth())
            ->postJson("/api/posts/{$post->id}/targets/{$failed->id}/retry")
            ->assertOk();

        Queue::assertPushed(PublishToLinkedInJob::class, 1);
        Queue::assertNotPushed(PublishToXJob::class);
    }
}
