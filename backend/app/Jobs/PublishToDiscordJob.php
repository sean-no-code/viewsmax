<?php

namespace App\Jobs;

use App\Exceptions\TransientPublishException;
use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Discord\RateLimitedException;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publish a single post's Discord target: one message to the connected
 * channel's webhook (DiscordProvider). A rate limit comes back when Discord's
 * retry_after says; an outage or network error is retried with $backoff.
 */
class PublishToDiscordJob implements ShouldQueue
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
        if (! $target || $target->platform !== 'discord') {
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

        // Each Discord account is a channel: a target with no account must not
        // land in whichever channel was connected last (one may have been
        // added after the post was scheduled).
        if (! $target->social_account_id
            && $post->user->socialAccounts()->where('platform', 'discord')->count() > 1) {
            $this->markFailed($target, 'Several Discord channels are connected. Edit the post and choose which Discord channel to post to.');

            return;
        }

        [$account, $accountError] = $this->resolveTargetAccount($target, 'discord');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        // A webhook Discord reported deleted is never called again.
        if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
            $this->markFailed($target, $account->last_error ?: 'This Discord channel needs reconnecting.');

            return;
        }

        try {
            $provider = $manager->for('discord');

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

            $this->markFailed($target, $result->error ?: 'Discord publish failed.');
        } catch (RateLimitedException $e) {
            // Nothing was posted. Come back when Discord says, while attempts last.
            if ($this->attempts() >= $this->tries) {
                $this->markFailed($target, 'Discord kept rate-limiting this post. Try again later.');

                return;
            }
            $this->release((int) ceil($e->retryAfter) + 1);
        } catch (TransientPublishException $e) {
            throw $e; // queue retries with $tries / $backoff
        } catch (\Throwable $e) {
            report($e);
            $this->markFailed($target, 'Discord publishing failed unexpectedly. Try again, or reconnect the channel.');
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
            $this->markFailed($target, $e instanceof TransientPublishException
                ? $e->getMessage()
                : 'Discord publishing failed.');
        }
    }
}
