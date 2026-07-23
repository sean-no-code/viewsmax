<?php

namespace Tests\Feature;

use App\Jobs\SendPostFailureEmailJob;
use App\Mail\PostFailedMail;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Users are emailed when a post fails to publish. One debounced email per
 * failure episode — a multi-platform post failing everywhere produces a single
 * message — and a manual retry opens a new episode so a re-failure re-notifies.
 * A per-user settings toggle (on by default) can silence the emails.
 */
class PostFailureEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function makePostWithTargets(array $platforms = ['x', 'linkedin']): Post
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'Big launch',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);

        foreach ($platforms as $platform) {
            $post->targets()->create([
                'platform' => $platform,
                'status' => PostTarget::STATUS_PUBLISHING,
            ]);
        }

        return $post;
    }

    private function failTarget(PostTarget $target, string $error = 'boom'): void
    {
        $target->forceFill([
            'status' => PostTarget::STATUS_FAILED,
            'error' => $error,
        ])->save();
    }

    public function test_first_failure_schedules_a_single_debounced_email_job(): void
    {
        Queue::fake([SendPostFailureEmailJob::class]);
        $post = $this->makePostWithTargets();

        foreach ($post->targets as $target) {
            $this->failTarget($target);
        }

        Queue::assertPushed(SendPostFailureEmailJob::class, 1);
        Queue::assertPushed(SendPostFailureEmailJob::class, function (SendPostFailureEmailJob $job) {
            return $job->delay !== null && $job->delay->diffInSeconds(now()) <= 180;
        });
        $this->assertNotNull($post->fresh()->failure_notified_at);
    }

    public function test_email_lists_every_failed_platform_once(): void
    {
        Mail::fake();
        Queue::fake(); // tests use the sync driver; keep hook dispatches from running inline
        $post = $this->makePostWithTargets(['x', 'linkedin']);
        foreach ($post->targets as $target) {
            $this->failTarget($target, "{$target->platform} exploded");
        }

        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertSent(PostFailedMail::class, 1);
        Mail::assertSent(PostFailedMail::class, function (PostFailedMail $mail) {
            $platforms = $mail->failedTargets->pluck('platform')->sort()->values()->all();

            return $mail->hasTo($this->user->email)
                && $platforms === ['linkedin', 'x'];
        });
    }

    public function test_no_email_when_user_disabled_notifications(): void
    {
        Mail::fake();
        Queue::fake();
        $this->user->forceFill(['notify_post_failures' => false])->save();
        $post = $this->makePostWithTargets(['x']);
        $this->failTarget($post->targets->first());

        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_no_email_when_all_targets_recovered_before_send(): void
    {
        Mail::fake();
        Queue::fake();
        $post = $this->makePostWithTargets(['x']);
        $target = $post->targets->first();
        $this->failTarget($target);
        // Recovered (e.g. manually retried and published) inside the debounce window.
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'error' => null])->save();

        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_second_failure_in_same_episode_does_not_requeue(): void
    {
        Queue::fake([SendPostFailureEmailJob::class]);
        $post = $this->makePostWithTargets(['x', 'linkedin', 'threads']);
        $targets = $post->targets;

        $this->failTarget($targets[0]);
        $this->failTarget($targets[1]);
        $this->failTarget($targets[2]);

        Queue::assertPushed(SendPostFailureEmailJob::class, 1);
    }

    public function test_manual_retry_opens_a_new_episode(): void
    {
        Queue::fake();
        $post = $this->makePostWithTargets(['x']);
        $target = $post->targets->first();
        $this->failTarget($target);
        $this->assertNotNull($post->fresh()->failure_notified_at);

        $this->withHeaders($this->auth())
            ->postJson("/api/posts/{$post->id}/targets/{$target->id}/retry")
            ->assertOk();

        $this->assertNull($post->fresh()->failure_notified_at);

        // The retried publish fails again → a fresh notification is scheduled
        // (one push per episode: the original failure plus this one).
        $this->failTarget($target->fresh());
        Queue::assertPushed(SendPostFailureEmailJob::class, 2);
    }

    public function test_settings_endpoint_reads_and_updates_the_toggle(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/user/settings')
            ->assertOk()
            ->assertJsonPath('data.notify_post_failures', true);

        $this->withHeaders($this->auth())
            ->patchJson('/api/user/settings', ['notify_post_failures' => false])
            ->assertOk()
            ->assertJsonPath('data.notify_post_failures', false);

        $this->assertFalse($this->user->fresh()->notify_post_failures);
    }

    public function test_settings_endpoint_requires_auth(): void
    {
        $this->getJson('/api/user/settings')->assertUnauthorized();
    }
}
