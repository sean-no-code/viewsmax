<?php

namespace App\Console\Commands;

use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use App\Services\Social\PostMetricSnapshotter;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;

/**
 * Snapshots per-post engagement daily so the Audience Growth "top posts" list
 * can rank by engagement (and derive a day-over-day delta). Gathers published
 * targets from BOTH posting stores plus every post already in the snapshot
 * table (the ones ImportRecentPostsJob pulled in from the platform on
 * connect), dedupes by (platform, remote_post_id), and hands each account's
 * posts to PostMetricSnapshotter, which fetches the metrics and upserts one
 * row per post per day.
 */
class RefreshPostMetrics extends Command
{
    protected $signature = 'posts:refresh-metrics';

    protected $description = 'Snapshot per-post engagement (likes/comments/shares/views) for audience-growth charts.';

    public function handle(SocialProviderManager $manager, PostMetricSnapshotter $snapshotter): int
    {
        $candidates = $this->gatherCandidates();

        $captured = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates->groupBy('social_account_id') as $accountId => $group) {
            // Accounts of users without access, or on a plan without analytics, are
            // skipped with their posts (no API spend for lapsed or below-Pro users).
            $account = SocialAccount::whereHas('user', fn ($u) => $u->withAccess()->withAnalytics())->find($accountId);

            if (! $account || $account->status !== SocialAccount::STATUS_CONNECTED
                || ! $manager->supports($account->platform) || ! $manager->supportsStats($account->platform)) {
                $skipped += $group->count();

                continue;
            }

            $result = $snapshotter->snapshot($account, $group->values());
            $captured += $result['captured'];
            $skipped += $result['skipped'];
            $failed += $result['failed'];
        }

        $this->info("Posts: {$candidates->count()}, captured {$captured}, skipped {$skipped}, failed {$failed}.");

        return ($failed > 0 && $captured === 0 && $skipped === 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Published targets from both stores, plus the latest snapshot of every
     * post already tracked (imported from the platform on connect), normalized
     * and deduped by (platform, remote_post_id) — target rows first so their
     * caption/url win. Only posts tied to a social account (so we have a token
     * to fetch with) are included.
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

        $latestPerPost = PostMetricSnapshot::query()
            ->selectRaw('max(id) as id')
            ->whereNotNull('social_account_id')
            ->groupBy('platform', 'remote_post_id');

        PostMetricSnapshot::query()
            ->whereIn('id', $latestPerPost)
            ->get()
            ->each(function (PostMetricSnapshot $row) use ($candidates) {
                $candidates->push([
                    'user_id' => $row->user_id,
                    'social_account_id' => $row->social_account_id,
                    'platform' => $row->platform,
                    'remote_post_id' => (string) $row->remote_post_id,
                    'url' => $row->url,
                    'caption' => (string) ($row->caption_excerpt ?? ''),
                    'published_at' => $row->published_at,
                    'thumbnail' => $row->thumbnail_url,
                ]);
            });

        return $candidates->unique(fn ($c) => $c['platform'].'|'.$c['remote_post_id'])->values();
    }
}
