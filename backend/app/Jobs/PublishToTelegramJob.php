<?php

namespace App\Jobs;

use App\Exceptions\TransientPublishException;
use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialProviderManager;
use App\Services\Social\Telegram\TelegramBotApi;
use App\Services\Social\Telegram\TelegramRateLimited;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Publish a single post's Telegram target (one connected channel or group)
 * through TelegramProvider.
 *
 * Telegram has no per-account token, so there is nothing to refresh; an
 * account flagged needs_reauth (the bot was removed or lost its posting
 * right) fails fast until the user reconnects it. Flood control (429) puts
 * the job back on the queue for exactly Telegram's retry_after; other
 * transient errors (5xx, network) retry with $backoff; a definite refusal
 * marks the target failed.
 */
class PublishToTelegramJob implements ShouldQueue
{
    use Queueable, ResolvesTargetAccount;

    // Uploading up to ten 50 MB files takes a while.
    public $timeout = 600;

    public $tries = 3;

    public $backoff = 20;

    public function __construct(public int $postTargetId) {}

    public function handle(SocialProviderManager $manager): void
    {
        /** @var PostTarget|null $target */
        $target = PostTarget::with('post.user')->find($this->postTargetId);
        if (! $target || $target->platform !== 'telegram') {
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

        [$account, $accountError] = $this->resolveTargetAccount($target, 'telegram');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
            $this->markFailed($target, $account->last_error ?: 'The ViewsMax bot can no longer post in this Telegram chat — reconnect it.');

            return;
        }

        try {
            $provider = $manager->for('telegram');

            $result = $provider->publish($account, new SocialPost([
                'content' => $target->caption_override ?: ($post->caption ?? ''),
                'media' => $post->media ?? [],
            ]));

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

            $this->markFailed($target, $result->error ?: 'Telegram publish failed.');
        } catch (TelegramRateLimited $e) {
            // Nothing is live yet. Wait as long as Telegram asked; on the last
            // attempt fail with the readable reason instead of "attempted too
            // many times".
            if ($this->attempts() >= $this->tries) {
                $this->markFailed($target, 'Telegram kept rate-limiting the bot, so nothing was posted. Retry in a few minutes.');

                return;
            }
            $this->release($e->retryAfter);
        } catch (TransientPublishException $e) {
            throw $e; // nothing is live; the queue retries with $tries / $backoff
        } catch (\Throwable $e) {
            $message = TelegramBotApi::redact($e->getMessage());
            Log::error('Telegram publish job error', [
                'post_target_id' => $target->id,
                'error' => $message,
            ]);
            $this->markFailed($target, $message);
        }
    }

    private function markFailed(PostTarget $target, string $message): void
    {
        $target->forceFill([
            'status' => PostTarget::STATUS_FAILED,
            'error' => TelegramBotApi::redact($message),
        ])->save();
    }

    public function failed(?\Throwable $e): void
    {
        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            $this->markFailed($target, $e?->getMessage() ?: 'Telegram publishing failed.');
        }
    }
}
