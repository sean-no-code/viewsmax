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
 * Publish a single post's Slack target to the channel of its account (each
 * connected Slack account is one workspace channel). Rate limits and Slack
 * outages are retried by the queue, after Slack's own Retry-After when it
 * gives one.
 */
class PublishToSlackJob implements ShouldQueue
{
    use Queueable, ResolvesTargetAccount;

    public $timeout = 300;

    public $tries = 3;

    public $backoff = 30;

    public function __construct(public int $postTargetId) {}

    public function handle(SocialProviderManager $manager): void
    {
        /** @var PostTarget|null $target */
        $target = PostTarget::with('post.user')->find($this->postTargetId);
        if (! $target || $target->platform !== 'slack') {
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

        // A Slack account is a channel. A target that names none may only fall
        // back to the user's account when there is exactly one channel to
        // mean; with several, the channel is never picked for the user.
        if (! $target->social_account_id
            && $post?->user?->socialAccounts()->where('platform', 'slack')->where('status', SocialAccount::STATUS_CONNECTED)->count() > 1) {
            $this->markFailed($target, 'Several Slack channels are connected. Choose which channel to post to, then retry.');

            return;
        }

        [$account, $accountError] = $this->resolveTargetAccount($target, 'slack');
        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        try {
            $provider = $manager->for('slack');
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->markFailed($target, 'Slack authorization expired — reconnect the Slack account.');

                return;
            }

            // Adapt the Post into the SocialPost shape the provider reads.
            // Transient — never persisted.
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

            $this->markFailed($target, $result->error ?: 'Slack publish failed.');
        } catch (TransientPublishException $e) {
            // Nothing was posted. Wait as long as Slack asked when a retry is
            // left; otherwise let the queue's backoff (or failed()) take it.
            if ($e->retryAfter !== null && $this->job && $this->attempts() < $this->tries) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Slack publish job error', [
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
            $this->markFailed($target, $e?->getMessage() ?: 'Slack publishing failed.');
        }
    }
}
