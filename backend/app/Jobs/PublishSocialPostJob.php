<?php

namespace App\Jobs;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\UserAccess;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes a single post to a single connected account. One job per target so
 * a failure (or retry) on one platform never blocks the others.
 *
 * Double-publish guard: the create call's outcome can be lost (worker killed,
 * timeout, exception after the platform accepted the post) and LinkedIn /
 * Bluesky give no way to ask whether it went out. So the target is stamped
 * "attempted" in its `response` column BEFORE publish(); a later attempt that
 * finds the stamp stops instead of creating the post again. Only a manual
 * retry (SocialPostController::retry) clears it.
 */
class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    /** `response` key stamped before the create call; see the class docblock. */
    public const ATTEMPT_MARKER = 'publish_attempted_at';

    public int $tries = 3;

    public int $timeout = 900; // up to 15 min (video uploads can be slow)

    public int $backoff = 30;

    public function __construct(public int $targetId) {}

    public function handle(SocialProviderManager $manager): void
    {
        $target = SocialPostTarget::with(['post', 'account'])->find($this->targetId);

        if (! $target || $target->status === SocialPostTarget::STATUS_PUBLISHED) {
            return;
        }

        $account = $target->account;
        $post = $target->post;

        if (! $account || ! $post) {
            $target->update([
                'status' => SocialPostTarget::STATUS_FAILED,
                'error' => 'Account or post no longer exists.',
            ]);

            return;
        }

        // Owner lost access since scheduling: back to drafts, nothing attempted.
        $owner = $post->user ?? $account->user;
        if (! $owner || ! UserAccess::allowsBackgroundWork($owner)) {
            $target->update(['status' => SocialPostTarget::STATUS_PENDING, 'error' => null]);
            $post->update(['status' => SocialPost::STATUS_DRAFT, 'scheduled_at' => null]);
            Log::info('Social post returned to draft: owner has no access', ['social_post_id' => $post->id, 'user_id' => $post->user_id]);

            return;
        }

        if (data_get($target->response, self::ATTEMPT_MARKER)) {
            $label = config("social.platforms.{$target->platform}.label", ucfirst($target->platform));
            $this->failTarget($target, "This post may have already been published to {$label}; check the account before retrying.");
            $post->syncStatusFromTargets();

            return;
        }

        $target->update(['status' => SocialPostTarget::STATUS_PUBLISHING]);

        try {
            $provider = $manager->for($target->platform);

            // Refresh the token if the platform supports it.
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->failTarget($target, 'Account needs to be reconnected (token expired).');

                return;
            }

            $target->update(['response' => array_merge($target->response ?? [], [self::ATTEMPT_MARKER => now()->toIso8601String()])]);

            $result = $provider->publish($account, $post);

            if ($result->success) {
                $target->update([
                    'status' => SocialPostTarget::STATUS_PUBLISHED,
                    'remote_post_id' => $result->remotePostId,
                    'remote_post_url' => $result->remotePostUrl,
                    'response' => $result->response,
                    'error' => null,
                    'published_at' => now(),
                ]);

                $account->update(['last_synced_at' => now()]);
            } else {
                $this->failTarget($target, $result->error ?? 'Unknown publishing error.', $result->response);
            }
        } catch (TransientPublishException $e) {
            // The platform answered 429/5xx, so nothing was created: drop the
            // guard and let the queue retry the create.
            $target->response = self::withoutMarker($target->response);
            $this->failTarget($target, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            Log::error('PublishSocialPostJob failed', [
                'target_id' => $target->id,
                'platform' => $target->platform,
                'error' => $e->getMessage(),
            ]);

            // Surface the error but let the queue retry up to $tries.
            $this->failTarget($target, $e->getMessage());

            throw $e;
        } finally {
            $post->syncStatusFromTargets();
        }
    }

    /**
     * Mark the job permanently failed (after retries are exhausted).
     */
    public function failed(Throwable $exception): void
    {
        $target = SocialPostTarget::find($this->targetId);
        if ($target) {
            $this->failTarget($target, $exception->getMessage());
            $target->post?->syncStatusFromTargets();
        }
    }

    protected function failTarget(SocialPostTarget $target, string $error, array $response = []): void
    {
        $target->update([
            'status' => SocialPostTarget::STATUS_FAILED,
            'error' => mb_substr($error, 0, 1000),
            'response' => $response ?: $target->response,
        ]);
    }

    /**
     * The target's response without the double-publish guard.
     */
    public static function withoutMarker(?array $response): ?array
    {
        unset($response[self::ATTEMPT_MARKER]);

        return $response ?: null;
    }
}
