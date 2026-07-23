<?php

namespace App\Jobs;

use App\Mail\PostFailedMail;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the (single) publish-failure email for a post's current failure
 * episode. Dispatched with a short delay by PostFailureNotifier so a
 * multi-platform post that fails everywhere within the window produces one
 * email listing every failed platform, not one email per platform.
 */
class SendPostFailureEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $postId)
    {
    }

    public function handle(): void
    {
        $post = Post::with('user', 'targets')->find($this->postId);

        if (! $post || ! $post->user || ! $post->user->notify_post_failures) {
            return;
        }

        // Only platforms still failed at send time — anything retried and
        // recovered inside the debounce window shouldn't alarm the user.
        $failed = $post->targets
            ->where('status', PostTarget::STATUS_FAILED)
            ->values();

        if ($failed->isEmpty()) {
            return;
        }

        Mail::to($post->user->email)->send(new PostFailedMail($post, $failed));
    }
}
