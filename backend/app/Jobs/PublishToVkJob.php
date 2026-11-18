<?php

namespace App\Jobs;

use App\Exceptions\TransientPublishException;
use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publish a single post's VK target to the account's own wall.
 *
 * Bridges the Post/PostTarget flow to VkProvider. Videos are uploaded to VK
 * before the wall post, so the timeout is generous. A VK rate limit or
 * server error is retried by the queue (TransientPublishException).
 */
class PublishToVkJob implements ShouldQueue
{
    use Queueable, ResolvesTargetAccount;

    public $timeout = 900;

    public $tries = 3;

    public $backoff = 60;

    public function __construct(public int $postTargetId) {}

    public function handle(SocialProviderManager $manager): void
    {
        /** @var PostTarget|null $target */
        $target = PostTarget::with('post.user')->find($this->postTargetId);
        if (! $target || $target->platform !== 'vk') {
            return;
        }
        if (in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            return;
        }

        $post = $target->post;
        if ($denial = $this->accessDenialFor($target)) {
            $target->post?->revertToDraft($denial->code);

            return;
        }

        [$account, $accountError] = $this->resolveTargetAccount($target, 'vk');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        try {
            $provider = $manager->for('vk');
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->markFailed($target, 'VK authorization expired — reconnect the account.');

                return;
            }

            // Adapt the legacy Post into the SocialPost shape VkProvider reads
            // (content + media array). Transient — never persisted.
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

            $this->markFailed($target, $result->error ?: 'VK publish failed.');
        } catch (TransientPublishException $e) {
            throw $e; // the queue retries with $tries / $backoff
        } catch (\Throwable $e) {
            Log::error('VK publish job error', [
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
        Log::error('VK publish job gave up', ['post_target_id' => $this->postTargetId, 'error' => $e?->getMessage()]);

        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            $this->markFailed($target, $e?->getMessage() ?: 'VK publishing failed.');
        }
    }
}
