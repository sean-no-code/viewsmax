<?php

namespace App\Jobs;

use App\Mail\PostFailedMail;
use App\Models\Post;
use App\Models\PostFailureNotification;
use App\Models\PostTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the publish-failure emails for a post's current failure episode:
 * one to the post's owner (unless they opted out) and, when
 * mail.post_failure_admin is set, a separate ops copy that goes out
 * regardless of the owner's setting. Dispatched with a short delay by
 * PostFailureNotifier so a multi-platform post that fails everywhere within
 * the window produces one email per recipient listing every failed platform.
 *
 * Every outcome — sent, skipped (and why), failed — is written to
 * post_failure_notifications. Each recipient is handled on its own: one
 * failing never blocks the other, a queue retry re-sends only what wasn't
 * sent, and the first send error is rethrown so the retry happens.
 */
class SendPostFailureEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $postId)
    {
    }

    public function handle(): void
    {
        $post = Post::with('user', 'targets')->find($this->postId);

        if (! $post || ! $post->user) {
            return; // post or owner deleted since the failure: nothing to tell anyone
        }

        // Only platforms still failed at send time — anything retried and
        // recovered inside the debounce window shouldn't alarm anyone.
        $failed = $post->targets
            ->where('status', PostTarget::STATUS_FAILED)
            ->values();
        $snapshot = $failed->map(fn (PostTarget $t) => [
            'platform' => $t->platform,
            'social_account_id' => $t->social_account_id,
            'error' => $t->error,
        ])->all();

        $admin = trim((string) config('mail.post_failure_admin'));

        if ($failed->isEmpty()) {
            $this->record($post, PostFailureNotification::TYPE_CUSTOMER, $post->user->email, PostFailureNotification::STATUS_SKIPPED, PostFailureNotification::REASON_RECOVERED);
            if ($admin !== '') {
                $this->record($post, PostFailureNotification::TYPE_ADMIN, $admin, PostFailureNotification::STATUS_SKIPPED, PostFailureNotification::REASON_RECOVERED);
            }

            return;
        }

        $firstError = null;

        // 1. The owner.
        $customerNotified = false;
        if (! $post->user->notify_post_failures) {
            $this->record($post, PostFailureNotification::TYPE_CUSTOMER, $post->user->email, PostFailureNotification::STATUS_SKIPPED, PostFailureNotification::REASON_OPTED_OUT, $snapshot);
        } else {
            $customerNotified = $this->send($post, PostFailureNotification::TYPE_CUSTOMER, $post->user->email, new PostFailedMail($post, $failed), $snapshot, $firstError);
        }

        // 2. The ops copy, independent of the owner's setting.
        if ($admin === '') {
            $this->record($post, PostFailureNotification::TYPE_ADMIN, null, PostFailureNotification::STATUS_SKIPPED, PostFailureNotification::REASON_NO_ADMIN_ADDRESS, $snapshot);
        } else {
            $this->send($post, PostFailureNotification::TYPE_ADMIN, $admin, new PostFailedMail($post, $failed, forAdmin: true, customerNotified: $customerNotified), $snapshot, $firstError);
        }

        if ($firstError) {
            throw $firstError; // let the queue retry; already-sent recipients are skipped above
        }
    }

    /**
     * Send to one recipient and record the outcome. Returns true when the email
     * is out (now or on an earlier attempt of this episode).
     */
    private function send(Post $post, string $type, string $email, PostFailedMail $mail, array $snapshot, ?Throwable &$firstError): bool
    {
        if ($this->alreadySent($post, $type)) {
            return true;
        }

        try {
            Mail::to($email)->send($mail);
            $this->record($post, $type, $email, PostFailureNotification::STATUS_SENT, null, $snapshot);

            return true;
        } catch (Throwable $e) {
            $this->record($post, $type, $email, PostFailureNotification::STATUS_FAILED, $e->getMessage(), $snapshot);
            $firstError ??= $e;

            return false;
        }
    }

    /** A `sent` row for this recipient since the episode opened (failure_notified_at). */
    private function alreadySent(Post $post, string $type): bool
    {
        return $post->failureNotifications()
            ->where('recipient_type', $type)
            ->where('status', PostFailureNotification::STATUS_SENT)
            ->when($post->failure_notified_at, fn ($q, $since) => $q->where('created_at', '>=', $since))
            ->exists();
    }

    private function record(Post $post, string $type, ?string $email, string $status, ?string $reason = null, array $snapshot = []): void
    {
        PostFailureNotification::record($post, $type, $email, $status, $reason, $snapshot);
    }
}
