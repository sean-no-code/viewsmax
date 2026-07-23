<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes a single post to a single connected account. One job per target so
 * a failure (or retry) on one platform never blocks the others.
 */
class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

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

        $target->update(['status' => SocialPostTarget::STATUS_PUBLISHING]);

        try {
            $provider = $manager->for($target->platform);

            // Refresh the token if the platform supports it.
            $account = $provider->ensureFreshToken($account);

            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $this->failTarget($target, 'Account needs to be reconnected (token expired).');

                return;
            }

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
}
