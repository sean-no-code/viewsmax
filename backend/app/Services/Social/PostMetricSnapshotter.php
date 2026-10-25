<?php

namespace App\Services\Social;

use App\Models\PostMetricSnapshot;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes today's engagement snapshot for a set of posts on one account:
 * fetches metrics through the account's provider and upserts one
 * post_metric_snapshots row per post per day. Shared by the daily
 * posts:refresh-metrics run and the on-connect ImportRecentPostsJob so the
 * numbers come from one code path. A fetch failure is written to
 * social_accounts.post_stats_error (cleared on the next success) so the
 * Analytics page can show it; posts the platform returns nothing for are
 * skipped, not zeroed.
 */
class PostMetricSnapshotter
{
    public function __construct(private SocialProviderManager $manager) {}

    /**
     * @param  Collection<int, array{user_id:int, social_account_id:int, platform:string, remote_post_id:string, url:?string, caption:string, published_at:mixed, thumbnail?:?string}>  $candidates
     * @return array{captured:int, skipped:int, failed:int}
     */
    public function snapshot(SocialAccount $account, Collection $candidates): array
    {
        $captured = 0;
        $skipped = 0;

        try {
            $provider = $this->manager->for($account->platform);
            $account = $provider->ensureFreshToken($account);

            // Chunk to the smallest per-platform cap so no ids get dropped.
            $metrics = [];
            foreach (array_chunk($candidates->pluck('remote_post_id')->all(), 20) as $chunk) {
                $metrics += $provider->fetchPostMetrics($account, $chunk);
            }

            foreach ($candidates as $c) {
                $m = $metrics[$c['remote_post_id']] ?? null;
                if ($m === null) {
                    $skipped++;

                    continue;
                }

                $likes = (int) ($m['likes'] ?? 0);
                $comments = (int) ($m['comments'] ?? 0);
                $shares = (int) ($m['shares'] ?? 0);
                $views = (int) ($m['views'] ?? 0);

                PostMetricSnapshot::updateOrCreate(
                    [
                        'platform' => $c['platform'],
                        'remote_post_id' => $c['remote_post_id'],
                        'snapshot_date' => now()->toDateString(),
                    ],
                    [
                        'user_id' => $c['user_id'],
                        'social_account_id' => $c['social_account_id'],
                        'url' => $c['url'],
                        // A fresh link from the metrics wins; otherwise keep what the candidate carried.
                        'thumbnail_url' => $m['thumbnail'] ?? $c['thumbnail'] ?? null,
                        'caption_excerpt' => Str::limit(trim((string) $c['caption']), 200, ''),
                        'published_at' => $c['published_at'],
                        'likes' => $likes,
                        'comments' => $comments,
                        'shares' => $shares,
                        'views' => $views,
                        'engagement_total' => $likes + $comments + $shares + $views,
                    ],
                );
                $captured++;
            }
            $this->recordError($account, null);
        } catch (\Throwable $e) {
            $this->recordError($account, $e->getMessage());
            Log::warning('Post metrics refresh failed', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return ['captured' => $captured, 'skipped' => $skipped, 'failed' => $candidates->count() - $captured - $skipped];
        }

        return ['captured' => $captured, 'skipped' => $skipped, 'failed' => 0];
    }

    /** Persist (or clear) why the last run could not fetch post metrics for the account. */
    public function recordError(SocialAccount $account, ?string $message): void
    {
        $message = $message === null ? null : Str::limit($message, 1000, '…');
        if ($account->post_stats_error !== $message) {
            $account->forceFill(['post_stats_error' => $message])->save();
        }
    }
}
