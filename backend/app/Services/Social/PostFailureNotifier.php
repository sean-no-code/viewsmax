<?php

namespace App\Services\Social;

use App\Jobs\SendPostFailureEmailJob;
use App\Models\Post;
use App\Models\PostTarget;

/**
 * Schedules the user-facing publish-failure email. One notification per
 * failure episode: the first target to fail claims the episode atomically
 * (posts.failure_notified_at), later failures on the same post join the
 * already-scheduled email. A manual retry clears the stamp, opening a new
 * episode so a repeat failure notifies again.
 */
class PostFailureNotifier
{
    /** Debounce window so sibling-platform failures land in the same email. */
    public const DEBOUNCE_MINUTES = 2;

    public static function onTargetFailed(PostTarget $target): void
    {
        // Atomic claim — with parallel queue workers two targets can fail at
        // the same moment; only one may schedule the email.
        $claimed = Post::whereKey($target->post_id)
            ->whereNull('failure_notified_at')
            ->update(['failure_notified_at' => now()]);

        if (! $claimed) {
            return;
        }

        SendPostFailureEmailJob::dispatch($target->post_id)
            ->delay(now()->addMinutes(self::DEBOUNCE_MINUTES));
    }
}
