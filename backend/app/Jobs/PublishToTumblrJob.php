<?php

namespace App\Jobs;

use App\Exceptions\TransientPublishException;
use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Providers\TumblrProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publish a single post's Tumblr target (one blog).
 *
 * Bridges the Post/PostTarget flow to TumblrProvider. The only Tumblr option
 * is `tags` (a list of strings), sent only when the user typed some. Media is
 * uploaded inside the create call, so a video post gets a generous timeout.
 * Rate limits, upstream errors and "an earlier video is still transcoding"
 * come back as TransientPublishException and are retried by the queue with a
 * widening backoff.
 */
class PublishToTumblrJob implements ShouldQueue
{
    use Queueable, ResolvesTargetAccount;

    public $timeout = 900;

    public $tries = 3;

    /** Seconds before the 2nd and 3rd attempts: transcoding and hourly limits need minutes. */
    public $backoff = [60, 300];

    public function __construct(public int $postTargetId) {}

    public function handle(SocialProviderManager $manager): void
    {
        /** @var PostTarget|null $target */
        $target = PostTarget::with('post.user')->find($this->postTargetId);
        if (! $target || $target->platform !== 'tumblr') {
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

        // A target with no blog picked would otherwise go to the newest
        // connected blog: never guess when the user has more than one.
        if (! $target->social_account_id
            && $post?->user?->socialAccounts()->where('platform', 'tumblr')->where('status', SocialAccount::STATUS_CONNECTED)->count() > 1) {
            $this->markFailed($target, 'Choose which Tumblr blog to post to — you have more than one connected.');

            return;
        }

        [$account, $accountError] = $this->resolveTargetAccount($target, 'tumblr');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        try {
            $provider = $manager->for('tumblr');
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->markFailed($target, 'Tumblr authorization expired — reconnect Tumblr.');

                return;
            }

            // Adapt the legacy Post into the SocialPost shape TumblrProvider
            // reads (content + media array). Transient — never persisted.
            $socialPost = new SocialPost([
                'content' => $target->caption_override ?: ($post->caption ?? ''),
                'media' => $post->media ?? [],
            ]);
            $socialPost->tags = $target->options['tags'] ?? [];

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

            $this->markFailed($target, $result->error ?: 'Tumblr publish failed.');
        } catch (TransientPublishException $e) {
            throw $e; // the queue retries with $tries / $backoff
        } catch (\Throwable $e) {
            Log::error('Tumblr publish job error', [
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
        Log::error('Tumblr publish gave up after retries', [
            'post_target_id' => $this->postTargetId,
            'error' => $e?->getMessage(),
        ]);

        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            $this->markFailed($target, $e?->getCode() === 429
                ? TumblrProvider::RATE_LIMIT_MESSAGE
                : ($e?->getMessage() ?: 'Tumblr publishing failed.'));
        }
    }
}
