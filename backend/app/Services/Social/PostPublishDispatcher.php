<?php

namespace App\Services\Social;

use App\Jobs\PublishToBlueskyJob;
use App\Jobs\PublishToFacebookJob;
use App\Jobs\PublishToInstagramJob;
use App\Jobs\PublishToLinkedInJob;
use App\Jobs\PublishToThreadsJob;
use App\Jobs\PublishToTikTokJob;
use App\Jobs\PublishToXJob;
use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;

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

        $target->update(['status' => PostTarget::STATUS_PUBLISHING]);
        $jobClass::dispatch($target->id);

        return true;
    }
}
