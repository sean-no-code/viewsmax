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
 *
 * Inbox deliveries are marked published once TikTok reports
 * SEND_TO_USER_INBOX, but TikTok can still fail the draft afterwards. Recent
 * inbox targets are asked again too: a FAILED status is logged with TikTok's
 * response and shown on the target as a warning (meta.warning).
 */
class ResolveTikTokPostIds extends Command
{
    use ResolvesTargetAccount;

    protected $signature = 'posts:resolve-tiktok-ids {--hours=24 : Give up on targets published longer ago than this}';

    protected $description = 'Fill in the public TikTok post id (and URL) for published targets still waiting on moderation.';

    /** TikTok never issues a public id for these; polling them is pointless. */
    private const PRIVATE_LEVELS = ['SELF_ONLY', 'MUTUAL_FOLLOW_FRIENDS'];

    /** Inbox statuses after which nothing changes, so the target is not asked again. */
    private const INBOX_FINAL_STATUSES = ['FAILED', 'PUBLISH_COMPLETE'];

    public const INBOX_FAILED_WARNING = 'TikTok later reported that this inbox delivery failed';

    public function handle(TikTokPublishService $tiktok): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $targets = PostTarget::query()
            ->where('platform', 'tiktok')
            ->where('status', PostTarget::STATUS_PUBLISHED)
            ->whereNull('platform_post_id')
            ->where('published_at', '>=', now()->subHours($hours))
            ->whereHas('post.user', fn ($u) => $u->withAccess())
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

        $this->checkInboxDeliveries($tiktok, $hours);

        return self::SUCCESS;
    }

    /**
     * Ask TikTok again about recent inbox deliveries. A FAILED status is
     * logged (warning: best-effort monitoring) with TikTok's response and put
     * on the target as meta.warning; the target stays published, since what
     * reached the inbox is not ours to retry. A final status is stored so the
     * target is not asked again.
     */
    private function checkInboxDeliveries(TikTokPublishService $tiktok, int $hours): void
    {
        $targets = PostTarget::query()
            ->where('platform', 'tiktok')
            ->where('status', PostTarget::STATUS_PUBLISHED)
            ->where('published_at', '>=', now()->subHours($hours))
            ->whereHas('post.user', fn ($u) => $u->withAccess())
            ->with('post.user')
            ->get()
            ->filter(fn (PostTarget $t) => ! empty($t->meta['publish_id'])
                && ($t->meta['mode'] ?? 'direct') === 'inbox'
                && empty($t->meta['inbox_final_status']));

        $failed = 0;
        foreach ($targets as $target) {
            [$account] = $this->resolveTargetAccount($target, 'tiktok');
            if (! $account) {
                continue;
            }

            try {
                $status = $tiktok->fetchStatus($account, $target->meta['publish_id']);
            } catch (\Throwable $e) {
                Log::warning('posts:resolve-tiktok-ids could not fetch inbox status', ['post_target_id' => $target->id, 'error' => $e->getMessage()]);

                continue;
            }

            $state = $status['status'] ?? null;
            if (! in_array($state, self::INBOX_FINAL_STATUSES, true)) {
                continue; // still in the inbox, waiting for the creator
            }

            $meta = $target->meta;
            $meta['inbox_final_status'] = $state;
            if ($state === 'FAILED') {
                Log::warning('TikTok inbox delivery failed after reaching the inbox', [
                    'post_target_id' => $target->id,
                    'post_id' => $target->post_id,
                    'publish_id' => $meta['publish_id'],
                    'response' => $status,
                ]);
                $meta['last_status'] = $status;
                $meta['warning'] = TikTokPublishService::rejection(self::INBOX_FAILED_WARNING, $status['fail_reason'] ?? null)
                    .' Post it again if it is not in your TikTok app.';
                $failed++;
                $this->warn("INBOX FAILED: target {$target->id}");
            }
            $target->forceFill(['meta' => $meta])->save();
        }

        $this->info("TikTok inbox deliveries checked: {$targets->count()}, failed later {$failed}.");
    }
}
