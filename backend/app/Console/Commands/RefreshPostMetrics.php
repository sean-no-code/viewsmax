<?php

namespace App\Console\Commands;

use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Snapshots per-post engagement daily so the Audience Growth "top posts" list
 * can rank by engagement (and derive a day-over-day delta). Gathers published
 * targets from BOTH posting stores, dedupes by (platform, remote_post_id),
 * fetches metrics per account via the provider, and upserts one row per post
 * per day. Posts whose platform can't return metrics are skipped, not zeroed.
 */
class RefreshPostMetrics extends Command
{
    protected $signature = 'posts:refresh-metrics';

    protected $description = 'Snapshot per-post engagement (likes/comments/shares/views) for audience-growth charts.';

    public function handle(SocialProviderManager $manager): int
    {
        $candidates = $this->gatherCandidates();

        $captured = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates->groupBy('social_account_id') as $accountId => $group) {
            $account = SocialAccount::find($accountId);

            if (! $account || $account->status !== SocialAccount::STATUS_CONNECTED || ! $manager->supports($account->platform)) {
                $skipped += $group->count();

                continue;
            }

            try {
                $provider = $manager->for($account->platform);
                $account = $provider->ensureFreshToken($account);

                // Chunk to the smallest per-platform cap so no ids get dropped.
                $metrics = [];
                foreach (array_chunk($group->pluck('remote_post_id')->all(), 20) as $chunk) {
                    $metrics += $provider->fetchPostMetrics($account, $chunk);
                }

                foreach ($group as $c) {
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
            } catch (\Throwable $e) {
                $failed += $group->count();
                Log::warning('Post metrics refresh failed', [
                    'social_account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Posts: {$candidates->count()}, captured {$captured}, skipped {$skipped}, failed {$failed}.");

        return ($failed > 0 && $captured === 0 && $skipped === 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Published targets from both stores, normalized and deduped by
     * (platform, remote_post_id). Only targets tied to a social account (so we
     * have a token to fetch with) are included.
     *
     * @return \Illuminate\Support\Collection<int, array>
     */
    private function gatherCandidates(): \Illuminate\Support\Collection
    {
        $candidates = collect();

        PostTarget::query()
            ->where('status', PostTarget::STATUS_PUBLISHED)
            ->whereNotNull('platform_post_id')
            ->whereNotNull('social_account_id')
            ->with('post:id,user_id,caption')
            ->get()
            ->each(function (PostTarget $t) use ($candidates) {
                if (! $t->post) {
                    return;
                }
                $candidates->push([
                    'user_id' => $t->post->user_id,
                    'social_account_id' => $t->social_account_id,
                    'platform' => $t->platform,
                    'remote_post_id' => (string) $t->platform_post_id,
                    'url' => data_get($t->meta, 'url'),
                    'caption' => (string) ($t->post->caption ?? ''),
                    'published_at' => $t->published_at,
                ]);
            });

        SocialPostTarget::query()
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->whereNotNull('remote_post_id')
            ->whereNotNull('social_account_id')
            ->with('post:id,user_id,content')
            ->get()
            ->each(function (SocialPostTarget $t) use ($candidates) {
                if (! $t->post) {
                    return;
                }
                $candidates->push([
                    'user_id' => $t->post->user_id,
                    'social_account_id' => $t->social_account_id,
                    'platform' => $t->platform,
                    'remote_post_id' => (string) $t->remote_post_id,
                    'url' => $t->remote_post_url,
                    'caption' => (string) ($t->post->content ?? ''),
                    'published_at' => $t->published_at,
                ]);
            });

        return $candidates->unique(fn ($c) => $c['platform'].'|'.$c['remote_post_id'])->values();
    }
}
