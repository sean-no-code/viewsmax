<?php

namespace App\Services\Social;

use App\Jobs\PostTargetCommentJob;
use App\Models\PostTarget;
use App\Models\PostTargetComment;
use App\Services\Social\Contracts\SupportsComments;

/**
 * Starts a target's comment chain the moment it publishes. Materializes one
 * delivery row per composed comment (skipped upfront on platforms without a
 * comment API) and queues ONLY the first comment's job — each job dispatches
 * the next with its delay, so ordering survives delays and worker restarts.
 */
class CommentDispatcher
{
    public static function onTargetPublished(PostTarget $target): void
    {
        $post = $target->post;
        if (! $post) {
            return;
        }

        $comments = $post->comments()->orderBy('position')->get();
        if ($comments->isEmpty()) {
            return;
        }

        $manager = app(SocialProviderManager::class);
        $supports = $manager->supports($target->platform)
            && $manager->for($target->platform) instanceof SupportsComments;

        $firstRow = null;
        foreach ($comments as $comment) {
            $row = PostTargetComment::firstOrCreate(
                ['post_comment_id' => $comment->id, 'post_target_id' => $target->id],
                ['status' => $supports ? PostTargetComment::STATUS_PENDING : PostTargetComment::STATUS_SKIPPED]
            );
            $firstRow ??= $row;
        }

        if (! $supports || ! $firstRow || $firstRow->status !== PostTargetComment::STATUS_PENDING) {
            return;
        }

        $delay = (int) $comments->first()->delay_seconds;
        $job = PostTargetCommentJob::dispatch($firstRow->id);
        if ($delay > 0) {
            $job->delay(now()->addSeconds($delay));
        }
    }
}
