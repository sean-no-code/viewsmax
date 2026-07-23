<?php

namespace App\Http\Controllers;

use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingPageView;
use App\Services\TrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Video;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\TrackingLink;
use Carbon\Carbon;

/**
 * @group Offers
 *
 * Offers are products/campaigns being promoted (stored as tracking events).
 * Each offer has an offer URL and conversion value, owns tracking links, and
 * accumulates clicks and conversions. The stats and timeseries endpoints
 * power the analytics dashboards.
 */
class TrackingEventController extends Controller
{
    protected $trackingService;

    public function __construct(TrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

    /**
     * Get a list of distinct offer URLs used by the authenticated user.
     */
    public function getOffers()
    {
        $offers = Auth::user()->offers()
            ->select('offer_url')
            ->distinct()
            ->whereNotNull('offer_url')
            ->pluck('offer_url');

        return ['data' => $offers];
    }

    /**
     * Get aggregated stats for tracking events with date filtering and deduplication.
     */
    public function getStats(Request $request)
    {
        $query = Auth::user()->offers()->with(['links.video', 'goals']);

        $from = $request->input('from') ? Carbon::parse($request->input('from'))->startOfDay() : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to'))->endOfDay() : null;

        $query->when($from || $to, function ($q) use ($from, $to) {
            $q->where(function ($sub) use ($from, $to) {
                $sub->when($from, fn($q) => $q->where('created_at', '>=', $from))
                    ->when($to, fn($q) => $q->where('created_at', '<=', $to));
            })->orWhereHas('links', function ($linkQ) use ($from, $to) {
                $linkQ->when($from, fn($q) => $q->where('created_at', '>=', $from))
                      ->when($to, fn($q) => $q->where('created_at', '<=', $to));
            });
        });

        $events = $query->get();

        // Get all event IDs and link IDs upfront for aggregate queries
        $eventIds = $events->pluck('id');
        $linkIds = $events->flatMap(fn($e) => $e->links->pluck('id'));
        
        // Aggregate queries - much more efficient than N+1 pattern
        $totalClicks = TrackingClick::whereIn('tracking_link_id', $linkIds)->count();

        // Visitors = distinct people who LOADED a tracked page (GA-style, from
        // the pageview beacon) — not just people who came through a tracking
        // link. Falls back to click-derived visitors until pageview data
        // exists (older tracker installs pick the beacon up automatically).
        $totalVisitors = TrackingPageView::where('user_id', Auth::id())
            ->distinct('tracking_visitor_id')
            ->count('tracking_visitor_id');
        if ($totalVisitors === 0) {
            $totalVisitors = TrackingClick::whereIn('tracking_link_id', $linkIds)
                ->distinct('tracking_visitor_id')
                ->count('tracking_visitor_id');
        }
        
        $totalCallsBooked = TrackingConversion::whereIn('tracking_event_id', $eventIds)
            ->where('event_type', 'call booked')
            ->count();
            
        $totalEmailSignups = TrackingConversion::whereIn('tracking_event_id', $eventIds)
            ->where('event_type', 'email-signup')
            ->count();
            
        $totalSalesAmount = TrackingConversion::whereIn('tracking_event_id', $eventIds)
            ->sum('value');
        
        // Collect links that carry a reach source (YouTube video or Beehiiv post)
        // for view deduplication.
        $allReachLinks = $events->flatMap(fn($e) => $e->links)
            ->filter(fn($link) => $link->reachKey() !== null);

        // 2. Total reach with per-content de-dup (MIN initial across links to the
        // same content), preferring the freshly-synced current_view_count.
        $totalUniqueViews = $allReachLinks->groupBy(fn($l) => $l->reachKey())->sum(function ($contentLinks) {
             $current = $contentLinks->map(fn ($l) => $l->current_view_count ?? ($l->video ? $l->video->view_count : 0))->max();
             $minInitial = $contentLinks->map(fn ($l) => $l->initial_view_count ?? 0)->min();

             return max(0, ($current ?? 0) - $minInitial);
        });

        $totalConversions = TrackingConversion::whereIn('tracking_event_id', $eventIds)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'views' => $totalUniqueViews,
                'visitors' => $totalVisitors,
                'clicks' => $totalClicks,
                'conversions' => $totalConversions,
                'callsBooked' => $totalCallsBooked,
                'emailSignups' => $totalEmailSignups,
                'sales' => $totalSalesAmount,
                'eventCount' => $events->count(),
                // Reach-based conversion rate (conversions ÷ views since link
                // creation), null when there's no view data — never conv/clicks.
                'view_conversion_rate' => $totalUniqueViews > 0
                    ? round($totalConversions / $totalUniqueViews * 100, 2)
                    : null,
            ]
        ]);
    }

    /**
     * Daily time-series of clicks and attributed revenue for the authenticated
     * user, used by the Analytics overview area charts. Returns one bucket per
     * day across the requested window (gaps filled with zeros).
     */
    public function getTimeseries(Request $request)
    {
        $to = $request->input('to') ? Carbon::parse($request->input('to'))->endOfDay() : Carbon::now()->endOfDay();
        $from = $request->input('from') ? Carbon::parse($request->input('from'))->startOfDay() : (clone $to)->subDays(27)->startOfDay();

        // Scope to this user's events / links (optionally a single event).
        $eventIds = Auth::user()->offers()->pluck('id');
        if ($request->filled('event_id')) {
            $eventIds = $eventIds->filter(fn($id) => (string) $id === (string) $request->input('event_id'))->values();
        }
        $linkIds = TrackingLink::whereIn('tracking_event_id', $eventIds)->pluck('id');

        // Clicks + distinct visitors per day.
        $clickAgg = TrackingClick::whereIn('tracking_link_id', $linkIds)
            ->whereBetween('created_at', [$from, $to])
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT tracking_visitor_id) as visitors')
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');
        $clicksByDay = $clickAgg->map(fn ($row) => $row->total);

        // Conversions count + revenue per day.
        $revByDay = TrackingConversion::whereIn('tracking_event_id', $eventIds)
            ->whereBetween('created_at', [$from, $to])
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(value) as revenue'),
                DB::raw('COUNT(*) as conversions')
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Views per day = day-over-day gain in content reach, from the cumulative
        // per-link snapshots. Pull one extra prior day so the first in-range day
        // can be diffed against it.
        $snapshots = \App\Models\TrackingReachSnapshot::whereIn('tracking_link_id', $linkIds)
            ->whereBetween('snapshot_date', [(clone $from)->subDay()->toDateString(), $to->toDateString()])
            ->orderBy('snapshot_date')
            ->get(['tracking_link_id', 'snapshot_date', 'view_count']);

        // Dedup per video: multiple links to the same YouTube video carry
        // identical cumulative snapshots, so each video's daily gain must be
        // counted ONCE (mirrors the per-video MIN-initial dedup used for the
        // "views since" totals). Reduce to one cumulative value per video per day.
        $reachLinks = TrackingLink::whereIn('id', $linkIds)->get(['id', 'youtube_video_id', 'beehiiv_post_id']);
        $linkKey = $reachLinks->mapWithKeys(fn ($l) => [$l->id => ($l->reachKey() ?: 'link:'.$l->id)]);
        $byContentDay = [];
        foreach ($snapshots as $snap) {
            $key = $linkKey->get($snap->tracking_link_id) ?: ('link:'.$snap->tracking_link_id);
            $byContentDay[$key][substr((string) $snap->snapshot_date, 0, 10)] = $snap->view_count;
        }

        $viewsByDay = [];
        foreach ($byContentDay as $daily) {
            ksort($daily); // by 'Y-m-d'
            $prev = null;
            foreach ($daily as $day => $viewCount) {
                if ($prev !== null) {
                    $viewsByDay[$day] = ($viewsByDay[$day] ?? 0) + max(0, $viewCount - $prev);
                }
                $prev = $viewCount;
            }
        }

        // Visitors per day: distinct people who loaded a tracked page (GA-style).
        // Click-derived only as a fallback until pageview data exists.
        $pageViewVisitors = TrackingPageView::where('user_id', Auth::id())
            ->when($request->filled('event_id'), fn ($q) => $q->where('tracking_event_id', $request->input('event_id')))
            ->whereBetween('created_at', [$from, $to])
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(DISTINCT tracking_visitor_id) as visitors'))
            ->groupBy('day')
            ->pluck('visitors', 'day');
        $useClickVisitors = $pageViewVisitors->isEmpty();

        // Build a continuous daily series so the chart has no gaps.
        $series = [];
        for ($d = (clone $from); $d <= $to; $d->addDay()) {
            $key = $d->format('Y-m-d');
            $rev = $revByDay->get($key);
            $series[] = [
                'date' => $key,
                'clicks' => (int) ($clicksByDay[$key] ?? 0),
                'visitors' => $useClickVisitors
                    ? (int) ($clickAgg->get($key)?->visitors ?? 0)
                    : (int) ($pageViewVisitors[$key] ?? 0),
                'views' => (int) ($viewsByDay[$key] ?? 0),
                'revenue' => (float) ($rev->revenue ?? 0),
                'conversions' => (int) ($rev->conversions ?? 0),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $series,
        ]);
    }

    /**
     * Display a list of the resource.
     */
    /**
     * GA-style acquisition tables: where visitors actually came from.
     * GET /api/tracking-events/sources?from&to&event_id
     *
     * Returns `sources` (grouped by classified platform — google, x, direct…)
     * and `referrers` (grouped by the FULL referrer URL), each with distinct
     * visitors + view counts. Pageview-based; falls back to link-click data
     * (basis: "clicks") until the site has pageview beacons.
     */
    public function getSources(Request $request)
    {
        $to = $request->input('to') ? Carbon::parse($request->input('to'))->endOfDay() : Carbon::now()->endOfDay();
        $from = $request->input('from') ? Carbon::parse($request->input('from'))->startOfDay() : (clone $to)->subDays(27)->startOfDay();

        $pageViews = TrackingPageView::where('user_id', Auth::id())
            ->when($request->filled('event_id'), fn ($q) => $q->where('tracking_event_id', $request->input('event_id')))
            ->whereBetween('created_at', [$from, $to]);

        if ((clone $pageViews)->exists()) {
            $basis = 'pageviews';
            $base = $pageViews;
            $countLabel = 'views';
        } else {
            // No pageview beacons yet — derive acquisition from link clicks so
            // the tables aren't empty on older tracker installs.
            $basis = 'clicks';
            $eventIds = Auth::user()->offers()->pluck('id');
            if ($request->filled('event_id')) {
                $eventIds = $eventIds->filter(fn ($id) => (string) $id === (string) $request->input('event_id'))->values();
            }
            $linkIds = TrackingLink::whereIn('tracking_event_id', $eventIds)->pluck('id');
            $base = TrackingClick::whereIn('tracking_link_id', $linkIds)
                ->whereBetween('created_at', [$from, $to]);
            $countLabel = 'views';
        }

        $sources = (clone $base)
            ->select(
                'inferred_platform',
                DB::raw("COUNT(*) as {$countLabel}"),
                DB::raw('COUNT(DISTINCT tracking_visitor_id) as visitors')
            )
            ->groupBy('inferred_platform')
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($row) => [
                'source' => $row->inferred_platform ?: 'direct',
                'visitors' => (int) $row->visitors,
                'views' => (int) $row->{$countLabel},
            ]);

        $referrers = (clone $base)
            ->whereNotNull('referrer')
            ->where('referrer', '!=', '')
            ->select(
                'referrer',
                DB::raw("COUNT(*) as {$countLabel}"),
                DB::raw('COUNT(DISTINCT tracking_visitor_id) as visitors')
            )
            ->groupBy('referrer')
            ->orderByDesc('visitors')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'referrer' => $row->referrer,
                'visitors' => (int) $row->visitors,
                'views' => (int) $row->{$countLabel},
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'basis' => $basis,
                'sources' => $sources,
                'referrers' => $referrers,
            ],
        ]);
    }

    /**
     * Top referrer hosts (max 3) for a set of clicks: [{host, url, count}],
     * most-clicked first. Direct/empty referrers are excluded — the platform
     * breakdown already covers "direct".
     *
     * @param  \Illuminate\Support\Collection  $clicks
     */
    private static function topReferrers($clicks): array
    {
        return $clicks
            ->filter(fn ($c) => ! empty($c->referrer))
            ->groupBy(function ($c) {
                $host = parse_url($c->referrer, PHP_URL_HOST) ?: $c->referrer;

                return preg_replace('/^www\./i', '', strtolower($host));
            })
            ->map(fn ($group, $host) => [
                'host' => $host,
                'url' => $group->first()->referrer,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->take(3)
            ->values()
            ->all();
    }

    public function index(Request $request)
    {

        $query = Auth::user()->offers()
            ->with(['links.video', 'goals']);

        $from = $request->input('from') ? Carbon::parse($request->input('from'))->startOfDay() : null;
        $to = $request->input('to') ? Carbon::parse($request->input('to'))->endOfDay() : null;

        $query->when($from || $to, function ($q) use ($from, $to) {
            $q->where(function ($sub) use ($from, $to) {
                $sub->when($from, fn($q) => $q->where('created_at', '>=', $from))
                    ->when($to, fn($q) => $q->where('created_at', '<=', $to));
            })->orWhereHas('links', function ($linkQ) use ($from, $to) {
                $linkQ->when($from, fn($q) => $q->where('created_at', '>=', $from))
                      ->when($to, fn($q) => $q->where('created_at', '<=', $to));
            });
        });

        $events = $query->latest()
            ->get();
            
        // Pre-fetch all link IDs and event IDs for batch querying
        $allLinkIds = $events->flatMap(fn($e) => $e->links->pluck('id'))->all();
        $allEventIds = $events->pluck('id')->all();
        
        // Batch fetch clicks with visitor IDs (need this for per-link attribution).
        // created_at powers the per-link "Last click" timestamp + 7-day trend
        // sparkline; referrer + inferred_platform feed the source columns and
        // the per-platform breakdown.
        $clicks = TrackingClick::whereIn('tracking_link_id', $allLinkIds)
            ->select('id', 'tracking_link_id', 'tracking_visitor_id', 'referrer', 'inferred_platform', 'created_at')
            ->get();

        // Day buckets (oldest → newest) for the 7-day per-link sparkline.
        $sparkDays = collect(range(6, 0))->map(fn($d) => now()->subDays($d)->format('Y-m-d'));
        
        // Group clicks by link_id for click counts
        $clicksByLink = $clicks->groupBy('tracking_link_id');

        // Clicks grouped by visitor — used to reconstruct last-touch for legacy
        // (null-link) conversions below.
        $clicksByVisitor = $clicks->groupBy('tracking_visitor_id');

        // Get all visitor IDs for batch conversion query
        $allVisitorIds = $clicks->pluck('tracking_visitor_id')->unique()->all();
        
        // Batch fetch ALL conversions for these visitors (with event context)
        $conversions = TrackingConversion::whereIn('tracking_visitor_id', $allVisitorIds)
            ->whereIn('tracking_event_id', $allEventIds)
            ->select('id', 'tracking_visitor_id', 'tracking_event_id', 'tracking_link_id', 'event_type', 'value')
            ->get();

        // New conversions store the winning link directly → attribute by FK (no
        // double-count).
        $conversionsByLink = $conversions->whereNotNull('tracking_link_id')->groupBy('tracking_link_id');

        // Legacy rows (null link, pre-attribution-spine) can't name a link, so
        // reconstruct last-touch: credit each to the SINGLE link the visitor most
        // recently clicked for that same event. This avoids the old set-membership
        // double-count that credited one conversion to every link a visitor clicked.
        $linkEventMap = [];
        foreach ($events as $ev) {
            foreach ($ev->links as $lnk) {
                $linkEventMap[$lnk->id] = $ev->id;
            }
        }
        $legacyConversionsByLink = [];
        foreach ($conversions->whereNull('tracking_link_id') as $legacyConv) {
            $winningClick = $clicksByVisitor->get($legacyConv->tracking_visitor_id, collect())
                ->filter(fn($c) => ($linkEventMap[$c->tracking_link_id] ?? null) === $legacyConv->tracking_event_id)
                ->sortByDesc('created_at')
                ->first();
            if ($winningClick) {
                $legacyConversionsByLink[$winningClick->tracking_link_id][] = $legacyConv;
            }
        }

        $events->each(function ($event) use ($clicksByLink, $conversionsByLink, $legacyConversionsByLink, $conversions, $sparkDays) {
            // Calculate Link Level Metrics
            $event->links->each(function($link) use ($clicksByLink, $conversionsByLink, $legacyConversionsByLink, $event, $sparkDays) {
                 // Click count for this link
                 $linkClicks = $clicksByLink->get($link->id, collect());
                 $link->clicks_count = $linkClicks->count();

                 // Unique people behind the clicks, where their clicks came
                 // from (top referrer hosts), and the per-platform breakdown
                 // ("5 on x, 5 on linkedin") from the stored classification.
                 $link->visitors_count = $linkClicks->pluck('tracking_visitor_id')->filter()->unique()->count();
                 $link->platform_breakdown = $linkClicks
                     ->countBy(fn ($c) => $c->inferred_platform ?: 'direct')
                     ->sortDesc()
                     ->all();
                 $link->top_referrers = self::topReferrers($linkClicks);

                 // Most recent click time + 7-day click trend (for the links table).
                 // TrackingClick has $timestamps=false and no cast, so created_at
                 // comes back as a bare "Y-m-d H:i:s" string (UTC). Emit ISO-8601
                 // with a UTC marker so the SPA never parses it as local time.
                 $lastClick = $linkClicks->max('created_at');
                 $link->last_click_at = $lastClick ? Carbon::parse($lastClick)->toISOString() : null;
                 $clicksByDay = $linkClicks->groupBy(fn($c) => Carbon::parse($c->created_at)->format('Y-m-d'));
                 $link->clicks_spark = $sparkDays->map(fn($day) => $clicksByDay->get($day, collect())->count())->all();

                 // Conversions credited to THIS link: stored-FK rows attribute
                 // directly; legacy null-FK rows were reconstructed to a single
                 // last-touch link above. Both count each conversion at most once.
                 $directConversions = $conversionsByLink->get($link->id, collect())
                     ->where('tracking_event_id', $event->id);
                 $legacyConversions = collect($legacyConversionsByLink[$link->id] ?? []);
                 $linkConversions = $directConversions->concat($legacyConversions);

                 // Count per-link conversions
                 $link->calls_booked_count = $linkConversions->where('event_type', 'call booked')->count();
                 $link->email_signups_count = $linkConversions->where('event_type', 'email-signup')->count();
                 $link->conversions_count = $linkConversions->count();
                 $link->sales_amount = $linkConversions->sum('value');
                 
                  // Reach = views the content gained since this link was created.
                  // Prefer the freshly-synced current_view_count; fall back to the
                  // (possibly stale) imported video row for legacy links.
                  $currentViews = $link->current_view_count ?? ($link->video ? $link->video->view_count : 0);
                  $initialViews = $link->initial_view_count ?? 0;
                  $link->views = max(0, $currentViews - $initialViews);
                  // The content's TOTAL views — what the links table displays
                  // (the delta above powers the reach conversion rate).
                  $link->total_views = (int) ($currentViews ?? 0);
                  // View-based conversion rate — null (not 0) when there's no reach
                  // denominator, so the UI shows "—" instead of a fake 0%.
                  $link->view_conversion_rate = $link->views > 0
                      ? round($link->conversions_count / $link->views * 100, 2)
                      : null;
            });
            
            // Event reach = views-since across the event's YouTube-backed links,
            // de-duped per video (MIN initial across links to the same video) so
            // multiple links to one video don't multiply the count. Prefers the
            // freshly-synced current_view_count, falling back to the video row.
            $event->total_video_views = $event->links
                ->filter(fn ($l) => $l->reachKey() !== null)
                ->groupBy(fn ($l) => $l->reachKey())
                ->sum(function ($contentLinks) {
                    $current = $contentLinks
                        ->map(fn ($l) => $l->current_view_count ?? ($l->video ? $l->video->view_count : 0))
                        ->max();
                    $minInitial = $contentLinks->map(fn ($l) => $l->initial_view_count ?? 0)->min();

                    return max(0, ($current ?? 0) - $minInitial);
                });

            $event->total_clicks = $event->links->sum('clicks_count');

            // Event-level source rollups (distinct visitors, platform mix,
            // top referrers across ALL the event's links).
            $eventClicks = $event->links->flatMap(fn ($l) => $clicksByLink->get($l->id, collect()));
            $event->visitors_count = $eventClicks->pluck('tracking_visitor_id')->filter()->unique()->count();
            $event->platform_breakdown = $eventClicks
                ->countBy(fn ($c) => $c->inferred_platform ?: 'direct')
                ->sortDesc()
                ->all();
            $event->top_referrers = self::topReferrers($eventClicks);

            $event->calls_booked_count = $event->links->sum('calls_booked_count');
            $event->email_signups_count = $event->links->sum('email_signups_count');
            // Distinct conversions for this event (unique constraint is visitor+event).
            $event->conversions_count = $conversions->where('tracking_event_id', $event->id)->count();
            $event->sales_amount = $event->links->sum('sales_amount');
            // Views-based conversion rate for the event — null when no reach data.
            $event->view_conversion_rate = $event->total_video_views > 0
                ? round($event->conversions_count / $event->total_video_views * 100, 2)
                : null;
        });
            
        return ['data' => $events];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Backward-compat: accept the SPA's legacy `landing_page_url` as `offer_url`.
        if (! $request->has('offer_url') && $request->has('landing_page_url')) {
            $request->merge(['offer_url' => $request->input('landing_page_url')]);
        }

        // Enforce the plan's offer limit. Without an active subscription the user
        // falls back to the Free tier's cap (so a lapsed/past_due/never-subscribed
        // user is gated, NOT given unlimited). A null limit means unlimited.
        // Soft-deleted offers don't count.
        $plan = Auth::user()->activePlan()
            ?? Plan::where('name', 'free')->where('is_active', true)->first();
        if ($plan && $plan->max_offers !== null) {
            $activeOffers = Auth::user()->offers()->count();
            if ($activeOffers >= $plan->max_offers) {
                return response()->json([
                    'success' => false,
                    'message' => "You've reached your plan's limit of {$plan->max_offers} offer(s). "
                        . 'Delete an existing offer or upgrade your plan to add more.',
                ], 422);
            }
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'offer_url' => 'required|url',
            // Goals (conversion events) are optional at creation — added later on the offer page.
            'goals' => 'nullable|array',
            // Built-in types (conversion, call booked, email-signup, newsletter, trial) plus
            // arbitrary user-defined "custom" events, which are stored verbatim.
            'goals.*.event_type' => ['required', 'string', 'max:255'],
            'goals.*.conversion_url' => 'required|string',
            'goals.*.conversion_value' => 'nullable|numeric|min:0',
            
            'conversion_value' => 'nullable|numeric',
            'links.*.youtube_video_id' => [
                'nullable', 
                'string',
                function ($attribute, $value, $fail) {
                    if ($value) {
                         $exists = Video::where('youtube_video_id', $value)
                            ->whereHas('channel', function($query) {
                                $query->where('user_id', Auth::id());
                            })->exists();
                        
                        if (!$exists) {
                            $fail('The selected video is invalid or does not belong to you.');
                        }
                    }
                },
            ],
            'links.*.placement' => ['nullable', 'string', Rule::in([
                TrackingLink::PLACEMENT_VIDEO, 
                TrackingLink::PLACEMENT_EMAIL, 
                TrackingLink::PLACEMENT_X,
                TrackingLink::PLACEMENT_LINKEDIN,
                TrackingLink::PLACEMENT_PODCAST,
                TrackingLink::PLACEMENT_BLOG,
                TrackingLink::PLACEMENT_WEBSITE,
                TrackingLink::PLACEMENT_TIKTOK, 
                TrackingLink::PLACEMENT_AD,
                TrackingLink::PLACEMENT_INSTAGRAM,
                TrackingLink::PLACEMENT_OTHER
            ])],
            'links.*.name' => 'nullable|string',
            'links.*.description' => 'nullable|string|max:255',
        ]);

        $event = $this->trackingService->createEventWithLinksAndGoals($data); 
        return response()->json($event, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $event = Auth::user()->offers()->with(['links', 'goals'])->findOrFail($id);
        return $event;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $event = Auth::user()->offers()->findOrFail($id);

        // Backward-compat: accept the SPA's legacy `landing_page_url` as `offer_url`.
        if (! $request->has('offer_url') && $request->has('landing_page_url')) {
            $request->merge(['offer_url' => $request->input('landing_page_url')]);
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'offer_url' => 'sometimes|url',
            'goals' => 'nullable|array',
            'goals.*.event_type' => ['required', 'string', 'max:255'],
            'goals.*.conversion_url' => 'required|string',
            'goals.*.conversion_value' => 'nullable|numeric|min:0',

            'conversion_value' => 'nullable|numeric',
            'links' => 'nullable|array',
            'links.*.id' => 'nullable|integer', // For updating existing links
            'links.*.youtube_video_id' => [
                'nullable', 
                'string',
                function ($attribute, $value, $fail) {
                    if ($value) {
                         $exists = Video::where('youtube_video_id', $value)
                            ->whereHas('channel', function($query) {
                                $query->where('user_id', Auth::id());
                            })->exists();
                        
                        if (!$exists) {
                            $fail('The selected video is invalid or does not belong to you.');
                        }
                    }
                },
            ],
            'links.*.placement' => ['nullable', 'string', Rule::in([
                TrackingLink::PLACEMENT_VIDEO, 
                TrackingLink::PLACEMENT_EMAIL, 
                TrackingLink::PLACEMENT_X,
                TrackingLink::PLACEMENT_LINKEDIN,
                TrackingLink::PLACEMENT_PODCAST,
                TrackingLink::PLACEMENT_BLOG,
                TrackingLink::PLACEMENT_WEBSITE,
                TrackingLink::PLACEMENT_TIKTOK, 
                TrackingLink::PLACEMENT_AD,
                TrackingLink::PLACEMENT_INSTAGRAM,
                TrackingLink::PLACEMENT_OTHER
            ])],
            'links.*.name' => 'nullable|string',
            'links.*.description' => 'nullable|string|max:255',
        ]);

        $event = $this->trackingService->updateEventWithLinksAndGoals($event, $data);

        return $event->load(['links', 'goals']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $event = Auth::user()->offers()->findOrFail($id);
        $event->delete();

        return response()->noContent();
    }
}
