<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Social\PostPublishDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile publish targets orphaned by a worker/scheduler outage: a post was
 * flipped to `posted` but one or more of its targets never left `pending`/
 * `publishing` (the queue never ran the job). Dry-run by default.
 *
 *   posts:reconcile-stuck              # report only (safe)
 *   posts:reconcile-stuck --requeue    # re-dispatch ONLY targets with no
 *                                       # platform_post_id (never posted)
 *   posts:reconcile-stuck --fail       # mark stuck targets failed (clean state)
 *
 * --requeue deliberately SKIPS any target that already has a platform_post_id,
 * because re-dispatching one that already reached the platform would double-post.
 */
class ReconcileStuckPosts extends Command
{
    protected $signature = 'posts:reconcile-stuck {--requeue} {--fail} {--minutes=15}';

    protected $description = 'Report or repair publish targets stuck in pending/publishing on a posted post.';

    public function handle(): int
    {
        if ($this->option('requeue') && $this->option('fail')) {
            $this->error('Choose only one of --requeue or --fail.');

            return self::FAILURE;
        }

        $minutes = max(0, (int) $this->option('minutes'));

        // Stuck = target still pending/publishing on a POSTED post, untouched for
        // at least --minutes (so genuinely in-flight jobs are left alone).
        $stuck = PostTarget::stuck($minutes)->orderBy('post_id')->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck targets found.');

            return self::SUCCESS;
        }

        $requeued = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($stuck as $target) {
            $alreadyOnPlatform = ! is_null($target->platform_post_id);
            $label = "post {$target->post_id} target {$target->id} [{$target->platform}] {$target->status} platform_post_id=".($target->platform_post_id ?? '-');

            if ($this->option('requeue')) {
                if ($alreadyOnPlatform) {
                    // Has a platform id → it already posted. Re-dispatching would
                    // double-post; just heal the status instead.
                    $target->update(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => $target->published_at ?? now()]);
                    $skipped++;
                    $this->warn("HEALED (already on platform, marked published): {$label}");

                    continue;
                }

                if (PostPublishDispatcher::dispatchTarget($target)) {
                    $requeued++;
                    $this->info("REQUEUED: {$label}");
                } else {
                    $skipped++;
                    $this->warn("SKIP (no publisher for platform): {$label}");
                }
            } elseif ($this->option('fail')) {
                $target->update([
                    'status' => PostTarget::STATUS_FAILED,
                    'error' => $target->error ?: 'Reconciled: publishing never completed (worker outage).',
                ]);
                $failed++;
                $this->warn("FAILED: {$label}");
            } else {
                $this->line("WOULD FIX: {$label}".($alreadyOnPlatform ? '  (already on platform → would heal to published)' : '  (never posted → would requeue)'));
            }
        }

        $mode = $this->option('requeue') ? 'requeue' : ($this->option('fail') ? 'fail' : 'dry-run');
        Log::info('posts:reconcile-stuck ran', compact('mode', 'requeued', 'failed', 'skipped') + ['stuck' => $stuck->count()]);
        $this->info("Done ({$mode}): {$stuck->count()} stuck, {$requeued} requeued, {$failed} failed, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
