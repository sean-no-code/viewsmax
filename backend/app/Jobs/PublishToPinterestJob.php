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
 * Publish a single post's Pinterest target.
 *
 * Bridges the Post/PostTarget flow to PinterestProvider. The target's options
 * carry the Pinterest-only fields, exactly as the user entered them:
 * board_ids (account id → board) or board_id, title, link, alt_text and
 * cover_url (the cover image a video Pin needs). Video Pins wait for Pinterest to process the
 * upload, so this job allows a generous timeout.
 */
class PublishToPinterestJob implements ShouldQueue
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
        if (! $target || $target->platform !== 'pinterest') {
            return;
        }
        if (in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            return;
        }

        $post = $target->post;
        [$account, $accountError] = $this->resolveTargetAccount($target, 'pinterest');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        try {
            $provider = $manager->for('pinterest');
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->markFailed($target, 'Pinterest authorization expired — reconnect the account.');

                return;
            }

            // Adapt the legacy Post into the SocialPost shape PinterestProvider
            // reads (content + media array). Transient — never persisted.
            $options = $target->options ?? [];
            $socialPost = new SocialPost([
                'content' => $target->caption_override ?: ($post->caption ?? ''),
                'media' => $post->media ?? [],
                'link' => $options['link'] ?? null,
            ]);
            // A board belongs to one account: board_ids maps each picked
            // account to its board; board_id serves a single account.
            $socialPost->board_id = $options['board_ids'][(string) $account->id] ?? $options['board_id'] ?? null;
            $socialPost->title = $options['title'] ?? null;
            $socialPost->alt_text = $options['alt_text'] ?? null;
            $socialPost->cover_url = $options['cover_url'] ?? null;

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

            $this->markFailed($target, $result->error ?: 'Pinterest publish failed.');
        } catch (\Throwable $e) {
            Log::error('Pinterest publish job error', [
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
            $this->markFailed($target, $e?->getMessage() ?: 'Pinterest publishing failed.');
        }
    }
}
