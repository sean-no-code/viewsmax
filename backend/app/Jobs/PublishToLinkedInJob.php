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
 * Publish a single post's LinkedIn target.
 *
 * LinkedIn tokens live in the newer SocialAccount store (connected via
 * /api/social/linkedin), so this job bridges the legacy Post/PostTarget flow to
 * the fully-featured LinkedInProvider (OAuth refresh + member-context publishing
 * with optional image upload). Like X this is a single synchronous API call, so
 * there is no polling loop.
 */
class PublishToLinkedInJob implements ShouldQueue
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
        if (! $target || $target->platform !== 'linkedin') {
            return;
        }
        if (in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            return;
        }

        $post = $target->post;
        [$account, $accountError] = $this->resolveTargetAccount($target, 'linkedin');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        try {
            $provider = $manager->for('linkedin');
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->markFailed($target, 'LinkedIn authorization expired — reconnect the account.');

                return;
            }

            // Adapt the legacy Post into the SocialPost shape LinkedInProvider
            // reads (content + media array). Transient — never persisted.
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

            $this->markFailed($target, $result->error ?: 'LinkedIn publish failed.');
        } catch (\Throwable $e) {
            Log::error('LinkedIn publish job error', [
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

    /**
     * Called when retries are exhausted.
     */
    public function failed(?\Throwable $e): void
    {
        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            $this->markFailed($target, $e?->getMessage() ?: 'LinkedIn publishing failed.');
        }
    }
}
