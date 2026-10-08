<?php

namespace Tests\Feature;

use App\Jobs\SendPostFailureEmailJob;
use App\Mail\PostFailedMail;
use App\Models\Post;
use App\Models\PostFailureNotification;
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
                && ! $mail->forAdmin
                && $platforms === ['linkedin', 'x'];
        });
    }

    public function test_customer_email_shows_each_platforms_failure_reason(): void
    {
        $post = $this->makePostWithTargets(['x', 'youtube']);
        $this->failTarget($post->targets[0], 'x exploded');
        $this->failTarget($post->targets[1], 'Reconnect YouTube from the Connections page to grant video upload permission, then try again.');

        $html = (new PostFailedMail($post, $post->targets()->get()))->render();

        $this->assertStringContainsString('x exploded', $html);
        $this->assertStringContainsString('grant video upload permission', $html);
        $this->assertStringContainsString('Open post history', $html);
        $this->assertStringNotContainsString('ADMIN_EMAIL', $html);
    }

    public function test_admin_copy_goes_to_the_configured_address_with_reasons_and_customer_details(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.post_failure_admin' => ' ops@example.com ']); // trimmed
        $post = $this->makePostWithTargets(['x', 'linkedin']);
        foreach ($post->targets as $target) {
            $this->failTarget($target, "{$target->platform} exploded");
        }

        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertSent(PostFailedMail::class, 2);
        Mail::assertSent(PostFailedMail::class, fn (PostFailedMail $m) => $m->hasTo($this->user->email) && ! $m->forAdmin);
        Mail::assertSent(PostFailedMail::class, fn (PostFailedMail $m) => $m->hasTo('ops@example.com') && $m->forAdmin && $m->customerNotified === true);

        $admin = new PostFailedMail($post->fresh('user'), $post->targets()->get(), forAdmin: true, customerNotified: true);
        $html = $admin->render();
        $this->assertStringContainsString('x exploded', $html);
        $this->assertStringContainsString('linkedin exploded', $html);
        $this->assertStringContainsString($this->user->email, $html);
        $this->assertStringContainsString("Post:</strong> #{$post->id}", $html);
        $this->assertStringContainsString('has been sent the matching failure email', $html);
        $this->assertStringContainsString('ADMIN_EMAIL', $html);
        $this->assertStringContainsString("Post #{$post->id} by {$this->user->email} failed to publish to X, Linkedin", $admin->envelope()->subject);

        $this->assertSame(
            [['customer', 'sent', null], ['admin', 'sent', null]],
            $this->rows($post)
        );
    }

    public function test_admin_copy_is_sent_even_when_the_customer_opted_out(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.post_failure_admin' => 'ops@example.com']);
        $this->user->forceFill(['notify_post_failures' => false])->save();
        $post = $this->makePostWithTargets(['x']);
        $this->failTarget($post->targets->first(), 'x exploded');

        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertSent(PostFailedMail::class, 1);
        Mail::assertSent(PostFailedMail::class, fn (PostFailedMail $m) => $m->hasTo('ops@example.com') && $m->forAdmin && $m->customerNotified === false);
        $this->assertSame(
            [['customer', 'skipped', 'opted_out'], ['admin', 'sent', null]],
            $this->rows($post)
        );
        $this->assertStringContainsString('was not emailed', (new PostFailedMail($post->fresh('user'), $post->targets()->get(), forAdmin: true, customerNotified: false))->render());
    }

    public function test_without_an_admin_address_only_the_customer_is_emailed_and_the_skip_is_recorded(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.post_failure_admin' => null]);
        $post = $this->makePostWithTargets(['x']);
        $this->failTarget($post->targets->first(), 'x exploded');

        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertSent(PostFailedMail::class, 1);
        $this->assertSame(
            [['customer', 'sent', null], ['admin', 'skipped', 'no_admin_address']],
            $this->rows($post)
        );
        $row = PostFailureNotification::where('recipient_type', 'customer')->sole();
        $this->assertSame($this->user->email, $row->recipient_email);
        $this->assertSame([['platform' => 'x', 'social_account_id' => null, 'error' => 'x exploded']], $row->failures);
    }

    public function test_recovered_episode_records_skips_for_both_recipients(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.post_failure_admin' => 'ops@example.com']);
        $post = $this->makePostWithTargets(['x']);
        $target = $post->targets->first();
        $this->failTarget($target);
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'error' => null])->save();

        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame(
            [['customer', 'skipped', 'recovered'], ['admin', 'skipped', 'recovered']],
            $this->rows($post)
        );
    }

    public function test_a_queue_retry_does_not_resend_within_the_same_episode(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.post_failure_admin' => 'ops@example.com']);
        $post = $this->makePostWithTargets(['x']);
        $this->failTarget($post->targets->first(), 'x exploded');

        (new SendPostFailureEmailJob($post->id))->handle();
        (new SendPostFailureEmailJob($post->id))->handle();

        Mail::assertSent(PostFailedMail::class, 2); // one per recipient, not per attempt
        $this->assertCount(2, PostFailureNotification::where('status', 'sent')->get());
    }

    public function test_the_recorded_error_survives_a_retry_clearing_the_target(): void
    {
        Mail::fake();
        Queue::fake();
        $post = $this->makePostWithTargets(['x']);
        $target = $post->targets->first();
        $this->failTarget($target, 'x exploded');
        (new SendPostFailureEmailJob($post->id))->handle();

        $this->withHeaders($this->auth())
            ->postJson("/api/posts/{$post->id}/targets/{$target->id}/retry")
            ->assertOk();

        $this->assertNull($target->fresh()->error);
        $this->assertSame('x exploded', $post->failureNotifications()->where('recipient_type', 'customer')->sole()->failures[0]['error']);
    }

    public function test_one_recipient_failing_to_send_does_not_block_the_other(): void
    {
        Queue::fake();
        config(['mail.post_failure_admin' => 'ops@example.com']);
        $post = $this->makePostWithTargets(['x']);
        $this->failTarget($post->targets->first(), 'x exploded');

        // The customer's send blows up; the admin's goes through.
        $sentTo = [];
        Mail::shouldReceive('to')->twice()->andReturnUsing(function (string $to) use (&$sentTo) {
            if ($to === $this->user->email) {
                throw new \RuntimeException('smtp down');
            }
            $pending = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
            $pending->shouldReceive('send')->once()->andReturnUsing(function () use (&$sentTo, $to) { $sentTo[] = $to; return null; });

            return $pending;
        });

        try {
            (new SendPostFailureEmailJob($post->id))->handle();
            $this->fail('expected the send error to be rethrown for a queue retry');
        } catch (\RuntimeException $e) {
            $this->assertSame('smtp down', $e->getMessage());
        }

        $this->assertSame(['ops@example.com'], $sentTo);
        $this->assertSame(
            [['customer', 'failed', 'smtp down'], ['admin', 'sent', null]],
            $this->rows($post)
        );
    }

    /** @return array<int, array{0: string, 1: string, 2: ?string}> rows as [type, status, reason], oldest first */
    private function rows(Post $post): array
    {
        return PostFailureNotification::where('post_id', $post->id)->orderBy('id')->get()
            ->map(fn (PostFailureNotification $n) => [$n->recipient_type, $n->status, $n->reason])
            ->all();
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
