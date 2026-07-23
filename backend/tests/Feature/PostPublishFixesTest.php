<?php

namespace Tests\Feature;

use App\Jobs\PublishToTikTokJob;
use App\Jobs\PublishToXJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the "failed post" fixes: every failure is logged, a failed platform
 * can be retried on its own, and TikTok accepts photo slideshows.
 */
class PostPublishFixesTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makePost(User $user, string $status = Post::STATUS_POSTED, array $media = []): Post
    {
        return $user->posts()->create([
            'caption' => 'c', 'media' => $media, 'status' => $status,
        ]);
    }

    /** Issue: an admin was never made aware that a post failed. */
    public function test_a_target_failing_is_written_to_the_error_log(): void
    {
        Log::spy();
        Queue::fake(); // keep the failure-email job from running inline under the Log spy
        $post = $this->makePost(User::factory()->create());
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);

        $target->forceFill(['status' => PostTarget::STATUS_FAILED, 'error' => 'boom'])->save();

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => $message === 'Post publish failed'
                && ($context['platform'] ?? null) === 'x'
                && ($context['error'] ?? null) === 'boom')
            ->once();
    }

    public function test_retry_requeues_only_the_failed_target(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $post = $this->makePost($user);
        $failed = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_FAILED, 'error' => 'boom']);
        // A sibling that already published must never be touched (no double-post).
        $published = $post->targets()->create(['platform' => 'tiktok', 'status' => PostTarget::STATUS_PUBLISHED, 'platform_post_id' => 'p1']);

        $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/posts/{$post->id}/targets/{$failed->id}/retry")
            ->assertOk();

        $failed->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $failed->status);
        $this->assertNull($failed->error);
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $published->refresh()->status);
        Queue::assertPushed(PublishToXJob::class, 1);
        Queue::assertNotPushed(PublishToTikTokJob::class);
    }

    public function test_only_a_failed_target_can_be_retried(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $post = $this->makePost($user);
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PUBLISHED]);

        $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/posts/{$post->id}/targets/{$target->id}/retry")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_retry_is_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $post = $this->makePost($owner);
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_FAILED, 'error' => 'boom']);

        $intruder = User::factory()->create();
        $this->withHeaders($this->authHeaders($intruder))
            ->postJson("/api/posts/{$post->id}/targets/{$target->id}/retry")
            ->assertNotFound();
    }

    /** Issue: posting a slideshow (images only) to TikTok was rejected. */
    public function test_tiktok_accepts_a_photo_slideshow(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/posts', [
                'caption' => 'slides',
                'media' => [
                    ['type' => 'image', 'url' => 'https://cdn.example/1.jpg'],
                    ['type' => 'image', 'url' => 'https://cdn.example/2.jpg'],
                ],
                'status' => 'posted',
                'platforms' => ['tiktok'],
                // TikTok now requires an explicitly chosen privacy level to publish.
                'options' => ['tiktok' => ['privacy_level' => 'PUBLIC_TO_EVERYONE']],
            ])
            ->assertCreated();
    }

    public function test_tiktok_still_rejects_a_post_with_no_media(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/posts', [
                'caption' => 'no media',
                'status' => 'posted',
                'platforms' => ['tiktok'],
            ])
            ->assertStatus(422);
    }
}
