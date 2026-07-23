<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\Social\PostPublishDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishDuePosts extends Command
{
    protected $signature = 'posts:publish-due';

    protected $description = 'Dispatch publish jobs for scheduled posts whose time has arrived.';

    public function handle(): int
    {
        $due = Post::where('status', Post::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($due as $post) {
            try {
                // Flip status first so a subsequent tick can't re-dispatch this post.
                $post->update(['status' => Post::STATUS_POSTED]);

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
        Log::info('posts:publish-due ran', ['due' => $due->count(), 'dispatched' => $dispatched]);

        return self::SUCCESS;
    }
}
