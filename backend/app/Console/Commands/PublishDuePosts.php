<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\Social\PostPublishDispatcher;
use App\Support\UserAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PublishDuePosts extends Command
{
    protected $signature = 'posts:publish-due';

    protected $description = 'Dispatch publish jobs for scheduled posts whose time has arrived.';

    /** Cache key holding the last time this command ran; read by GET /api/health. */
    public const HEARTBEAT_KEY = 'scheduler:publish-due:last_run_at';

    /** A heartbeat older than this means the scheduler is not running. */
    public const HEARTBEAT_STALE_MINUTES = 5;

    public function handle(): int
    {
        // Written on every tick, including empty ones, so the health endpoint
        // can tell "scheduler not running" from "nothing was due" — an empty
        // run logs nothing, so the log alone can't.
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDays(7));

        $due = Post::with('user')
            ->where('status', Post::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $dispatched = 0;
        $drafted = 0;
        $skipped = 0;
        foreach ($due as $post) {
            try {
                // A lapsed trial / cancelled plan / deleted owner: never publish on
                // their behalf. The post goes back to drafts for them to re-schedule.
                $denial = $post->user ? UserAccess::denial($post->user) : null;
                if (! $post->user || $denial) {
                    $post->revertToDraft($denial?->code ?? 'user_missing');
                    $drafted++;
                    $this->warn("Post {$post->id} returned to draft: owner has no access.");

                    continue;
                }

                // Claim the post with one conditional update. The copy in $due may
                // be stale: the user can "Post now" (already dispatched),
                // reschedule or move it to drafts between the query above and
                // this post's turn. If the row is no longer scheduled and due,
                // the claim changes nothing and the post is skipped.
                if (! $post->claimForPublishing()) {
                    $skipped++;
                    $this->line("Post {$post->id} skipped: changed since it was loaded.");

                    continue;
                }

                PostPublishDispatcher::dispatch($post);

                $dispatched++;
                $this->info("Dispatched publishing for post {$post->id}.");
            } catch (\Throwable $e) {
                // One bad post must never abort the batch (that would leave every
                // later due post unpublished) or let an exception wedge the
                // scheduler's overlap lock. Log it and keep going.
                Log::error('posts:publish-due failed to dispatch a post', [
                    'post_id' => $post->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Post {$post->id} failed: {$e->getMessage()}");
            }
        }

        // Observable even though the scheduler runs this with output to /dev/null.
        Log::info('posts:publish-due ran', ['due' => $due->count(), 'dispatched' => $dispatched, 'drafted_no_access' => $drafted, 'skipped_changed' => $skipped]);

        return self::SUCCESS;
    }
}
