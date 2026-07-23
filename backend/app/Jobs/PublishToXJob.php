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
 * Publish a single post's X (Twitter) target.
 *
 * X tokens live in the newer SocialAccount store (connected via /api/social/x),
 * so this job bridges the legacy Post/PostTarget flow to the fully-featured
 * XProvider (OAuth refresh + tweet/media publishing). Unlike TikTok this is a
 * single synchronous API call, so there is no polling loop.
 */
class PublishToXJob implements ShouldQueue
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
        if (! $target || $target->platform !== 'x') {
            return;
        }
        if (in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            return;
        }

        $post = $target->post;
        [$account, $accountError] = $this->resolveTargetAccount($target, 'x');

        if (! $account) {
            $this->markFailed($target, $accountError);

            return;
        }

        // X can't take video through this pipeline — fail before any API traffic.
        $hasVideo = collect($post->media ?? [])->contains(fn ($m) => ($m['type'] ?? null) === 'video');
        if ($hasVideo) {
            $this->markFailed($target, "X can't post video from here yet — use images for X, or remove it from this post.");

            return;
        }

        try {
            $provider = $manager->for('x');
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->markFailed($target, 'X authorization expired — reconnect the account.');

                return;
            }

            // Adapt the legacy Post into the SocialPost shape XProvider reads
            // (content + media array). Transient — never persisted to social_posts.
            $socialPost = new SocialPost([
                'content' => $target->caption_override ?: ($post->caption ?? ''),
                'media' => $post->media ?? [],
            ]);

            // Thread resume state: tweet ids that already went out on an earlier
            // attempt. The provider persists progress after EVERY tweet so a
            // retry (queue or manual) never re-posts published segments.
            $alreadyPosted = data_get($target->meta, 'x_thread.tweet_ids', []);
            $persistProgress = function (array $tweetIds) use ($target) {
                $target->forceFill([
                    'meta' => array_merge($target->meta ?? [], ['x_thread' => array_merge(
                        data_get($target->meta, 'x_thread', []),
                        ['tweet_ids' => $tweetIds],
                    )]),
                ])->save();
            };

            $result = $provider->publishThread($account, $socialPost, $alreadyPosted, $persistProgress);

            if ($result->success) {
                $target->forceFill([
                    'status' => PostTarget::STATUS_PUBLISHED,
                    'platform_post_id' => $result->remotePostId,
                    'published_at' => now(),
                    'error' => null,
                    'meta' => array_merge($target->meta ?? [], array_filter([
                        'url' => $result->remotePostUrl,
                        'x_thread' => count($result->response['tweet_ids'] ?? []) > 0
                            ? ['tweet_ids' => $result->response['tweet_ids']]
                            : null,
                    ])),
                ])->save();

                return;
            }

            // Partial thread: some tweets are live. Record how far we got so a
            // retry resumes instead of double-posting.
            if (! empty($result->response['partial'])) {
                $target->forceFill([
                    'meta' => array_merge($target->meta ?? [], ['x_thread' => [
                        'tweet_ids' => $result->response['tweet_ids'] ?? [],
                        'partial' => true,
                    ]]),
                ])->save();
            }

            $this->markFailed($target, $result->error ?: 'X publish failed.');
        } catch (TransientPublishException $e) {
            // Progress is already persisted — rethrow so the queue retries and
            // the next attempt resumes mid-thread.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('X publish job error', [
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
            $this->markFailed($target, $e?->getMessage() ?: 'X publishing failed.');
        }
    }
}
