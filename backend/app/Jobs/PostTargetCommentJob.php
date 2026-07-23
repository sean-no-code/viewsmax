<?php

namespace App\Jobs;

use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Models\PostTargetComment;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Posts ONE composed comment on ONE published target, then dispatches the next
 * comment's job with its delay — sequential chaining keeps reply order intact.
 *
 * tries = 1 on purpose: comment creation isn't idempotent, and a retry after a
 * mid-flight crash could double-post. A failed comment is recorded and the
 * chain continues (anchored to the last comment that actually made it).
 */
class PostTargetCommentJob implements ShouldQueue
{
    use Queueable, ResolvesTargetAccount;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public int $targetCommentId) {}

    public function handle(SocialProviderManager $manager): void
    {
        $row = PostTargetComment::with(['comment.post', 'target'])->find($this->targetCommentId);
        if (! $row || $row->status !== PostTargetComment::STATUS_PENDING) {
            return;
        }

        $target = $row->target;
        $comment = $row->comment;

        if (! $target || ! $comment
            || $target->status !== PostTarget::STATUS_PUBLISHED
            || ! $target->platform_post_id) {
            $row->forceFill([
                'status' => PostTargetComment::STATUS_SKIPPED,
                'error' => 'Target is not published.',
            ])->save();

            return;
        }

        $provider = $manager->supports($target->platform) ? $manager->for($target->platform) : null;
        if (! $provider instanceof SupportsComments) {
            $row->forceFill(['status' => PostTargetComment::STATUS_SKIPPED])->save();
            $this->dispatchNext($row);

            return;
        }

        [$account, $accountError] = $this->resolveTargetAccount($target, $target->platform);
        if (! $account) {
            $row->forceFill(['status' => PostTargetComment::STATUS_FAILED, 'error' => $accountError])->save();
            $this->dispatchNext($row);

            return;
        }

        try {
            $account = $provider->ensureFreshToken($account);
            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $row->forceFill([
                    'status' => PostTargetComment::STATUS_FAILED,
                    'error' => ucfirst($target->platform).' authorization expired — reconnect the account.',
                ])->save();
                $this->dispatchNext($row);

                return;
            }

            $row->forceFill(['status' => PostTargetComment::STATUS_POSTING])->save();

            // X threads: anchor the first comment to the LAST thread segment so
            // the reply continues the thread instead of forking off tweet 1.
            $anchor = $target->platform === 'x'
                ? (string) (collect(data_get($target->meta, 'x_thread.tweet_ids', []))->last() ?: $target->platform_post_id)
                : (string) $target->platform_post_id;

            // Chain onto the last comment that actually posted on this target.
            $previous = PostTargetComment::where('post_target_id', $target->id)
                ->where('status', PostTargetComment::STATUS_POSTED)
                ->whereNotNull('platform_comment_id')
                ->join('post_comments', 'post_comments.id', '=', 'post_target_comments.post_comment_id')
                ->where('post_comments.position', '<', $comment->position)
                ->orderByDesc('post_comments.position')
                ->value('platform_comment_id');

            $result = $provider->comment($account, $anchor, $comment->body, $previous);

            $row->forceFill($result->success ? [
                'status' => PostTargetComment::STATUS_POSTED,
                'platform_comment_id' => $result->remoteCommentId,
                'posted_at' => now(),
                'error' => null,
            ] : [
                'status' => PostTargetComment::STATUS_FAILED,
                'error' => $result->error ?: 'Comment failed.',
            ])->save();
        } catch (\Throwable $e) {
            Log::error('Post comment job error', [
                'post_target_comment_id' => $row->id,
                'platform' => $target->platform,
                'error' => $e->getMessage(),
            ]);
            $row->forceFill([
                'status' => PostTargetComment::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();
        }

        $this->dispatchNext($row);
    }

    /** Queue the next comment in this target's chain, delayed by its setting. */
    private function dispatchNext(PostTargetComment $row): void
    {
        $next = PostTargetComment::where('post_target_id', $row->post_target_id)
            ->where('status', PostTargetComment::STATUS_PENDING)
            ->join('post_comments', 'post_comments.id', '=', 'post_target_comments.post_comment_id')
            ->orderBy('post_comments.position')
            ->select('post_target_comments.*', 'post_comments.delay_seconds as next_delay')
            ->first();

        if (! $next) {
            return;
        }

        $job = self::dispatch($next->id);
        if ((int) $next->next_delay > 0) {
            $job->delay(now()->addSeconds((int) $next->next_delay));
        }
    }
}
