<?php

namespace App\Http\Controllers;

use App\Models\AudienceSnapshot;
use App\Models\PostMetricSnapshot;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @group Audience Growth
 *
 * Read-only analytics over the daily snapshot tables (audience_snapshots,
 * post_metric_snapshots) populated by audience:refresh / posts:refresh-metrics.
 * Revenue Growth reuses TrackingEventController@getTimeseries and isn't here.
 */
class AnalyticsGrowthController extends Controller
{
    /**
     * Per-platform follower series over the date range, one entry per connected
     * account: current count, day-over-day delta across the range, and points.
     */
    public function audience(Request $request)
    {
        [$from, $to] = $this->range($request);

        $accounts = SocialAccount::where('user_id', Auth::id())
            ->orderBy('platform')
            ->get();

        $platforms = $accounts->map(function (SocialAccount $account) use ($from, $to) {
            $points = AudienceSnapshot::where('social_account_id', $account->id)
                ->whereBetween('snapshot_date', [$from, $to])
                ->orderBy('snapshot_date')
                ->get(['snapshot_date', 'follower_count'])
                ->map(fn ($s) => ['date' => $s->snapshot_date, 'followers' => (int) $s->follower_count])
                ->values();

            $current = $points->isNotEmpty() ? (int) $points->last()['followers'] : null;
            $delta = $points->count() > 1 ? $current - (int) $points->first()['followers'] : 0;

            return [
                'platform' => $account->platform,
                'account_id' => $account->id,
                'account_name' => $account->name,
                'supported' => $this->supportsFollowers($account->platform),
                'current' => $current,
                'delta' => $delta,
                'points' => $points->all(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => ['platforms' => $platforms],
        ]);
    }

    /**
     * Posts ranked by engagement (total, latest snapshot in range) with a
     * day-over-day delta. Optional ?platform= filter.
     */
    public function posts(Request $request)
    {
        [$from, $to] = $this->range($request);

        $series = PostMetricSnapshot::where('user_id', Auth::id())
            ->when($request->filled('platform'), fn ($q) => $q->where('platform', $request->input('platform')))
            ->whereBetween('snapshot_date', [$from, $to])
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy(fn ($r) => $r->platform.'|'.$r->remote_post_id);

        $posts = $series->map(function ($rows) {
            $latest = $rows->last();
            $prev = $rows->count() > 1 ? $rows->slice(-2, 1)->first() : null;

            return [
                'platform' => $latest->platform,
                'remote_post_id' => $latest->remote_post_id,
                'url' => $latest->url,
                'caption' => $latest->caption_excerpt,
                'published_at' => optional($latest->published_at)->toIso8601String(),
                'likes' => (int) $latest->likes,
                'comments' => (int) $latest->comments,
                'shares' => (int) $latest->shares,
                'views' => (int) $latest->views,
                'engagement_total' => (int) $latest->engagement_total,
                'engagement_delta' => $prev ? (int) $latest->engagement_total - (int) $prev->engagement_total : 0,
            ];
        })
            ->sortByDesc('engagement_total')
            ->take(50)
            ->values();

        return response()->json([
            'success' => true,
            'data' => ['posts' => $posts],
        ]);
    }

    /**
     * Parsed [from, to] as 'Y-m-d' strings (defaults to the last 28 days) so
     * whereBetween on the plain-string snapshot_date column behaves the same on
     * SQLite/Postgres.
     *
     * @return array{0:string, 1:string}
     */
    private function range(Request $request): array
    {
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : Carbon::now();
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : (clone $to)->subDays(27);

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * Whether the platform can supply a follower count under the current config
     * — drives the "not supported" state in the UI. Gated platforms flip on when
     * their scope/flag lands (see config/social).
     */
    private function supportsFollowers(string $platform): bool
    {
        return match ($platform) {
            'youtube', 'x' => true,
            'instagram' => (bool) config('social.platforms.instagram.reach_enabled'),
            'tiktok' => (bool) config('social.platforms.tiktok.stats_enabled'),
            default => false,
        };
    }
}
