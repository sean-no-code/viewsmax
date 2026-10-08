<?php

namespace App\Http\Controllers;

use App\Models\AudienceSnapshot;
use App\Models\PostMetricSnapshot;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * @group Analytics
 *
 * Profile / Post performance: read-only analytics over the daily snapshot
 * tables (audience_snapshots, post_metric_snapshots) that audience:refresh and
 * posts:refresh-metrics fill. One call returns everything the two pages need
 * for a date range, including the previous period so the UI can show deltas.
 */
class AnalyticsPerformanceController extends Controller
{
    /** Longest range a client may ask for (the response carries twice this many days). */
    private const MAX_DAYS = 400;

    /**
     * Accounts with their stats status and per-day series, plus the posts
     * published in the range.
     *
     * `days` is 2×L entries ending on `to` (previous period first). Per account,
     * `followers[i]` is the follower count on days[i] (forward-filled from the
     * last snapshot, null before the first one) and `engagement.{likes,comments,
     * shares,views}[i]` is how much that metric grew across the account's posts
     * on that day.
     */
    public function index(Request $request, SocialProviderManager $manager): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $to = $request->filled('to') ? Carbon::createFromFormat('Y-m-d', $request->input('to'))->startOfDay() : Carbon::today();
        $from = $request->filled('from') ? Carbon::createFromFormat('Y-m-d', $request->input('from'))->startOfDay() : (clone $to)->subDays(27);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $length = $from->diffInDays($to) + 1;
        if ($length > self::MAX_DAYS) {
            abort(422, 'Date range too long (max '.self::MAX_DAYS.' days).');
        }

        // Previous period + current period, as Y-m-d strings.
        $start = (clone $from)->subDays($length);
        $days = [];
        for ($d = clone $start; $d->lte($to); $d->addDay()) {
            $days[] = $d->toDateString();
        }
        $index = array_flip($days);

        $accounts = SocialAccount::where('user_id', Auth::id())
            ->orderBy('platform')
            ->orderBy('name')
            ->get();
        $accountIds = $accounts->pluck('id')->all();

        $followers = $this->followerSeries($accountIds, $days);
        [$engagement, $posts] = $this->postSeries($accountIds, $days, $index, $from->toDateString(), $to->toDateString());

        $out = $accounts->map(fn (SocialAccount $a) => [
            'id' => $a->id,
            'platform' => $a->platform,
            'name' => $a->name,
            'username' => $a->username,
            'avatar_url' => $a->avatar_url,
            'profile_url' => $a->profile_url,
            'status' => $a->status,
            'last_error' => $a->last_error,
            'supported' => $manager->supportsStats($a->platform),
            'follower_stats_error' => $a->follower_stats_error,
            'post_stats_error' => $a->post_stats_error,
            'followers' => $followers[$a->id] ?? array_fill(0, count($days), null),
            'engagement' => $engagement[$a->id] ?? self::emptyEngagement(count($days)),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'accounts' => $out,
                'posts' => $posts,
            ],
        ]);
    }

    /**
     * Forward-filled follower count per account per day. The last snapshot before
     * the window seeds the series so a range with no fresh rows still has a level.
     *
     * @param  array<int, int>  $accountIds
     * @param  array<int, string>  $days
     * @return array<int, array<int, int|null>>
     */
    private function followerSeries(array $accountIds, array $days): array
    {
        if (! $accountIds) {
            return [];
        }
        $first = $days[0];
        $last = $days[count($days) - 1];

        $rows = AudienceSnapshot::whereIn('social_account_id', $accountIds)
            ->whereBetween('snapshot_date', [$first, $last])
            ->orderBy('snapshot_date')
            ->get(['social_account_id', 'snapshot_date', 'follower_count'])
            ->groupBy('social_account_id');

        $seeds = AudienceSnapshot::whereIn('social_account_id', $accountIds)
            ->where('snapshot_date', '<', $first)
            ->orderByDesc('snapshot_date')
            ->get(['social_account_id', 'snapshot_date', 'follower_count'])
            ->unique('social_account_id')
            ->keyBy('social_account_id');

        $out = [];
        foreach ($accountIds as $id) {
            $byDate = ($rows[$id] ?? collect())->keyBy('snapshot_date');
            $level = isset($seeds[$id]) ? (int) $seeds[$id]->follower_count : null;
            $series = [];
            foreach ($days as $day) {
                if (isset($byDate[$day])) {
                    $level = (int) $byDate[$day]->follower_count;
                }
                $series[] = $level;
            }
            $out[$id] = $series;
        }

        return $out;
    }

    /**
     * Per-account per-day engagement deltas plus the posts published in
     * [$from, $to] with their latest metrics.
     *
     * Snapshots are cumulative totals per post, so a day's engagement is the
     * growth since the previous snapshot (clamped at 0 — platforms do lose likes).
     * A post's first-ever snapshot counts in full only when it was published
     * within the two days before it; otherwise it is a baseline, so switching
     * tracking on for an old post doesn't fake a spike.
     *
     * @param  array<int, int>  $accountIds
     * @param  array<int, string>  $days
     * @param  array<string, int>  $index  day => position in $days
     * @return array{0: array<int, array<string, array<int, int>>>, 1: array<int, array<string, mixed>>}
     */
    private function postSeries(array $accountIds, array $days, array $index, string $from, string $to): array
    {
        if (! $accountIds) {
            return [[], []];
        }
        $n = count($days);
        $metrics = ['likes', 'comments', 'shares', 'views'];

        $rows = PostMetricSnapshot::where('user_id', Auth::id())
            ->whereIn('social_account_id', $accountIds)
            ->where('snapshot_date', '<=', $to)
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy(fn (PostMetricSnapshot $r) => $r->platform.'|'.$r->remote_post_id);

        $engagement = [];
        $posts = [];
        foreach ($rows as $key => $series) {
            /** @var Collection<int, PostMetricSnapshot> $series */
            $latest = $series->last();
            $accountId = (int) $latest->social_account_id;
            $engagement[$accountId] ??= self::emptyEngagement($n);

            $prev = null;
            foreach ($series as $row) {
                $day = $row->snapshot_date;
                if ($prev === null) {
                    $recent = $row->published_at !== null
                        && $row->published_at->toDateString() >= Carbon::parse($day)->subDays(2)->toDateString();
                    $delta = $recent ? $row : null;
                } else {
                    $delta = $row;
                }
                if ($delta !== null && isset($index[$day])) {
                    foreach ($metrics as $m) {
                        $engagement[$accountId][$m][$index[$day]] += max(0, (int) $row->{$m} - ($prev ? (int) $prev->{$m} : 0));
                    }
                }
                $prev = $row;
            }

            $published = $latest->published_at ?? Carbon::parse($series->first()->snapshot_date);
            $publishedDay = $published->toDateString();
            if ($publishedDay >= $from && $publishedDay <= $to) {
                $posts[] = [
                    'id' => $key,
                    'account_id' => $accountId,
                    'platform' => $latest->platform,
                    'remote_post_id' => $latest->remote_post_id,
                    'url' => $latest->url,
                    'caption' => $latest->caption_excerpt,
                    'published_at' => $published->toIso8601String(),
                    'likes' => (int) $latest->likes,
                    'comments' => (int) $latest->comments,
                    'shares' => (int) $latest->shares,
                    'views' => (int) $latest->views,
                ];
            }
        }

        usort($posts, fn ($a, $b) => strcmp($b['published_at'], $a['published_at']));

        return [$engagement, $posts];
    }

    /** @return array<string, array<int, int>> */
    private static function emptyEngagement(int $n): array
    {
        return [
            'likes' => array_fill(0, $n, 0),
            'comments' => array_fill(0, $n, 0),
            'shares' => array_fill(0, $n, 0),
            'views' => array_fill(0, $n, 0),
        ];
    }
}
