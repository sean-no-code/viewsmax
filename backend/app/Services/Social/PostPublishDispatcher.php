<?php

namespace App\Services\Social;

use App\Jobs\PublishToBlueskyJob;
use App\Jobs\PublishToFacebookJob;
use App\Jobs\PublishToInstagramJob;
use App\Jobs\PublishToLinkedInJob;
use App\Jobs\PublishToPinterestJob;
use App\Jobs\PublishToThreadsJob;
use App\Jobs\PublishToTikTokJob;
use App\Jobs\PublishToXJob;
use App\Jobs\PublishYouTubeJob;
use App\Contracts\Quota\QuotaGate;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Quota\QuotaMessage;
use App\Support\Quota\Tier;
use Illuminate\Support\Facades\Log;

/**
 * Single place that maps a post's targets to their publish jobs. Used by both
 * the immediate "Post now" path (PostController) and the scheduled promoter
 * (PublishDuePosts command) so adding a platform only touches this map.
 */
class PostPublishDispatcher
{
    /**
     * platform key => publish job class. Platforms not listed here are created
     * as targets but have no automated publisher yet.
     */
    protected const JOBS = [
        'tiktok' => PublishToTikTokJob::class,
        'youtube' => PublishYouTubeJob::class,
        'x' => PublishToXJob::class,
        'linkedin' => PublishToLinkedInJob::class,
        'threads' => PublishToThreadsJob::class,
        'instagram' => PublishToInstagramJob::class,
        'facebook' => PublishToFacebookJob::class,
        'bluesky' => PublishToBlueskyJob::class,
        'pinterest' => PublishToPinterestJob::class,
    ];

    /**
     * Platforms that have an automated publisher (the JOBS map keys).
     */
    public static function platforms(): array
    {
        return array_keys(self::JOBS);
    }

    public static function dispatch(Post $post): void
    {
        foreach ($post->targets()->get() as $target) {
            self::dispatchTarget($target);
        }
    }

    /**
     * Dispatch a single target's publish job. Returns false when the platform has
     * no automated publisher. Kept public so the reconcile command can safely
     * re-queue an individual stuck target without touching its siblings (which
     * may already be published — re-dispatching those would double-post).
     */
    public static function dispatchTarget(PostTarget $target): bool
    {
        $jobClass = self::JOBS[$target->platform] ?? null;
        if ($jobClass === null) {
            return false;
        }

        // videos.insert is 1600 units and capped per day project-wide. When the
        // budget is gone the target stays pending (with the reset time in its
        // meta) and posts:reconcile-stuck --requeue brings it back here every
        // 15 minutes until the gate lets it through. Its siblings on other
        // platforms are not held up.
        if ($target->platform === 'youtube') {
            $gate = app(QuotaGate::class);
            $owner = $target->post?->user;
            $meta = $target->meta ?? [];
            // A target counts against its owner once: reconcile --requeue may
            // dispatch the same one again, and that's not a second upload.
            $counted = ! empty($meta['quota_user_counted']);

            $decision = $gate->allows(QuotaGate::VIDEOS_INSERT, Tier::of($owner), 1, $counted ? null : $owner?->id);
            if (! $decision->allowed) {
                $target->update(['meta' => [
                    'quota_paused_until' => $decision->resetsAt->format(DATE_ATOM),
                    'quota_message' => QuotaMessage::for($decision, $owner),
                ] + $meta]);
                Log::warning('YouTube publish deferred: upload budget used up', ['post_target_id' => $target->id] + $decision->toArray());

                return true;
            }

            if (! $counted && $owner) {
                $gate->recordUserFetch($owner->id, QuotaGate::VIDEOS_INSERT);
                unset($meta['quota_paused_until'], $meta['quota_message']);
                $target->meta = ['quota_user_counted' => true] + $meta;
            } elseif (isset($meta['quota_paused_until'])) {
                unset($meta['quota_paused_until'], $meta['quota_message']);
                $target->meta = $meta;
            }
        }

        $target->update(['status' => PostTarget::STATUS_PUBLISHING]);
        $jobClass::dispatch($target->id);

        return true;
    }
}
