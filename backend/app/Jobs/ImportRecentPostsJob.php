<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\Social\PostMetricSnapshotter;
use App\Services\Social\SocialProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pulls a just-connected account's latest posts from the platform — whether
 * or not they were published through the app — and writes a day-0 engagement
 * snapshot for each, so Post Performance isn't empty until the user posts via
 * ViewsMax. posts:refresh-metrics keeps those posts refreshed from then on.
 * Queued from the connect flows (SocialConnect, the YouTube OAuth mirror) and
 * from posts:import-recent for accounts connected before this existed.
 * Carries only the account id so no OAuth token sits in the queue payload.
 * Idempotent: a rerun the same day updates the rows rather than adding more.
 */
class ImportRecentPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How many of the account's latest posts to pull in. */
    public const LIMIT = 10;

    public $timeout = 120;

    public $tries = 2;

    public $backoff = 60;

    public function __construct(public int $socialAccountId) {}

    public function handle(SocialProviderManager $manager, PostMetricSnapshotter $snapshotter): void
    {
        $account = SocialAccount::find($this->socialAccountId);
        if (! $account || $account->status !== SocialAccount::STATUS_CONNECTED
            || ! $manager->supports($account->platform) || ! $manager->supportsStats($account->platform)) {
            return;
        }

        try {
            $posts = $manager->for($account->platform)->fetchRecentPosts($account, self::LIMIT);
        } catch (\Throwable $e) {
            $snapshotter->recordError($account, $e->getMessage());
            Log::channel('post_import')->warning('Recent posts listing failed', [
                'social_account_id' => $account->id, 'platform' => $account->platform, 'error' => $e->getMessage(),
            ]);

            throw $e; // let the queue retry
        }

        $candidates = collect($posts)->map(fn (array $p) => [
            'user_id' => $account->user_id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'remote_post_id' => (string) $p['remote_post_id'],
            'url' => $p['url'] ?? null,
            'caption' => (string) ($p['caption'] ?? ''),
            'published_at' => $p['published_at'] ?? null,
        ]);

        $result = $snapshotter->snapshot($account, $candidates);

        Log::channel('post_import')->info('Imported recent posts', [
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'found' => $candidates->count(),
        ] + $result);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('post_import')->error('Recent posts import gave up', [
            'social_account_id' => $this->socialAccountId,
            'error' => $e->getMessage(),
        ]);
    }
}
