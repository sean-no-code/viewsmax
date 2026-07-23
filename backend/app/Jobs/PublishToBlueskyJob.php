<?php

namespace App\Jobs;

use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publish a single post's Bluesky target.
 *
 * Bluesky session JWTs live about an hour, so every run refreshes the session
 * via ensureFreshToken before posting. Text + up to 4 images; the provider has
 * no video path yet, so video posts fail fast with a clear note.
 */
class PublishToBlueskyJob implements ShouldQueue
{
    use Queueable, ResolvesTargetAccount;

    public $timeout = 120;

    public $tries = 3;

    public $backoff = 20;

    public function __construct(public int $postTargetId) {}

    public function handle(SocialProviderManager $manager): void
    {
        /** @var PostTarget|null $target */
        $target = PostTarget::with('post.user')->find($this->postTargetId);
        if (! $target || $target->platform !== 'bluesky') {
            return;
        }
        if (in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            return;
        }

        $post = $target->post;
        [$account, $accountError] = $this->resolveTargetAccount($target, 'bluesky');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        // Fail fast before any API traffic — the provider only embeds images.
        $hasVideo = collect($post->media ?? [])->contains(fn ($m) => ($m['type'] ?? null) === 'video');
        if ($hasVideo) {
            $this->markFailed($target, "Bluesky can't post video yet — use images for Bluesky, or remove it from this post.");

            return;
        }

        try {
            $provider = $manager->for('bluesky');
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->markFailed($target, 'Bluesky session expired — reconnect the account.');

                return;
            }

            $socialPost = new SocialPost([
                'content' => $target->caption_override ?: ($post->caption ?? ''),
                'media' => $post->media ?? [],
            ]);

            $result = $provider->publish($account, $socialPost);

            if ($result->success) {
                $target->forceFill([
                    'status' => PostTarget::STATUS_PUBLISHED,
                    'platform_post_id' => $result->remotePostId,
                    'published_at' => now(),
                    'error' => null,
                    'meta' => array_merge($target->meta ?? [], array_filter([
                        'url' => $result->remotePostUrl,
                    ])),
                ])->save();

                return;
            }

            $this->markFailed($target, $result->error ?: 'Bluesky publish failed.');
        } catch (\Throwable $e) {
            Log::error('Bluesky publish job error', [
                'post_target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($target, $e->getMessage());
        }
    }

    private function markFailed(PostTarget $target, string $message): void
    {
        $target->forceFill([
            'status' => PostTarget::STATUS_FAILED,
            'error' => $message,
        ])->save();
    }

    public function failed(?\Throwable $e): void
    {
        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            $this->markFailed($target, $e?->getMessage() ?: 'Bluesky publishing failed.');
        }
    }
}
