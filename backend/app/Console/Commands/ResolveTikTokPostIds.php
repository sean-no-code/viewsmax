<?php

namespace App\Console\Commands;

use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Jobs\PublishToTikTokJob;
use App\Models\PostTarget;
use App\Services\TikTokPublishService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TikTok reports PUBLISH_COMPLETE before moderation has assigned the public
 * post id, so a target can be published with platform_post_id = null — and
 * posts:refresh-metrics only looks at targets that have an id, so those
 * posts would never get metrics or an open-post link. Re-query status/fetch
 * for such targets (bounded to the last --hours) and persist the id + URL
 * once TikTok hands them out.
 */
class ResolveTikTokPostIds extends Command
{
    use ResolvesTargetAccount;

    protected $signature = 'posts:resolve-tiktok-ids {--hours=24 : Give up on targets published longer ago than this}';

    protected $description = 'Fill in the public TikTok post id (and URL) for published targets still waiting on moderation.';

    /** TikTok never issues a public id for these; polling them is pointless. */
    private const PRIVATE_LEVELS = ['SELF_ONLY', 'MUTUAL_FOLLOW_FRIENDS'];

    public function handle(TikTokPublishService $tiktok): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $targets = PostTarget::query()
            ->where('platform', 'tiktok')
            ->where('status', PostTarget::STATUS_PUBLISHED)
            ->whereNull('platform_post_id')
            ->where('published_at', '>=', now()->subHours($hours))
            ->with('post.user')
            ->get()
            ->filter(fn (PostTarget $t) => ! empty($t->meta['publish_id'])
                && ($t->meta['mode'] ?? 'direct') !== 'inbox' // the creator still has to post it from the app
                && ! in_array($t->options['privacy_level'] ?? null, self::PRIVATE_LEVELS, true));

        $resolved = 0;
        $waiting = 0;
        $failed = 0;

        foreach ($targets as $target) {
            [$account] = $this->resolveTargetAccount($target, 'tiktok');
            if (! $account) {
                $failed++;

                continue;
            }

            try {
                $status = $tiktok->fetchStatus($account, $target->meta['publish_id']);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('posts:resolve-tiktok-ids could not fetch status', ['post_target_id' => $target->id, 'error' => $e->getMessage()]);

                continue;
            }

            $postId = PublishToTikTokJob::publicPostId($status);
            if (! $postId) {
                $waiting++;

                continue;
            }

            $meta = $target->meta;
            $meta['url'] = TikTokPublishService::postUrl($account, $meta['publish_id'], $postId);
            $target->forceFill(['platform_post_id' => $postId, 'meta' => $meta])->save();
            $resolved++;
            $this->info("RESOLVED: target {$target->id} -> {$postId}");
        }

        $this->info("TikTok targets awaiting an id: {$targets->count()}, resolved {$resolved}, still waiting {$waiting}, failed {$failed}.");

        return self::SUCCESS;
    }
}
