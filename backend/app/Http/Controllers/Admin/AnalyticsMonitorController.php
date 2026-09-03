<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingLink;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only cross-client monitoring of offers and tracking links with their
 * performance aggregates (views/reach, clicks, conversions, revenue, and both
 * reach- and click-based conversion rates).
 */
class AnalyticsMonitorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('role:admin')];
    }

    /** Views since link creation for a set of links, de-duped per content source (MIN initial). */
    private function reachForLinks($links): int
    {
        return (int) $links
            ->filter(fn ($l) => $l->reachKey() !== null)
            ->groupBy(fn ($l) => $l->reachKey())
            ->sum(function ($group) {
                $current = $group->map(fn ($l) => (int) ($l->current_view_count ?? 0))->max();
                $minInitial = $group->map(fn ($l) => (int) ($l->initial_view_count ?? 0))->min();

                return max(0, ($current ?? 0) - $minInitial);
            });
    }

    private static function pct(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : null;
    }

    /**
     * Every offer across all clients with owner + performance aggregates.
     * Optional `q` searches offer name/url or owner name/email.
     */
    public function offers(Request $request)
    {
        $query = Offer::query()->with('user:id,name,email');

        if ($term = trim((string) $request->input('q', ''))) {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(offer_url) LIKE ?', [$like])
                    ->orWhereHas('user', fn ($u) => $u
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            });
        }

        $offers = $query->latest()->limit(200)->get();
        $offerIds = $offers->pluck('id');

        $links = TrackingLink::whereIn('tracking_event_id', $offerIds)
            ->get(['id', 'tracking_event_id', 'youtube_video_id', 'beehiiv_post_id', 'initial_view_count', 'current_view_count']);
        $linksByOffer = $links->groupBy('tracking_event_id');

        $clicksByLink = TrackingClick::whereIn('tracking_link_id', $links->pluck('id'))
            ->select('tracking_link_id', DB::raw('count(*) as c'))
            ->groupBy('tracking_link_id')->pluck('c', 'tracking_link_id');

        $convByOffer = TrackingConversion::whereIn('tracking_event_id', $offerIds)
            ->select('tracking_event_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(value),0) as rev'))
            ->groupBy('tracking_event_id')->get()->keyBy('tracking_event_id');

        $rows = $offers->map(function (Offer $offer) use ($linksByOffer, $clicksByLink, $convByOffer) {
            $oLinks = $linksByOffer->get($offer->id, collect());
            $clicks = (int) $oLinks->sum(fn ($l) => (int) ($clicksByLink[$l->id] ?? 0));
            $conv = $convByOffer->get($offer->id);
            $conversions = (int) ($conv->c ?? 0);
            $views = $this->reachForLinks($oLinks);

            return [
                'id' => $offer->id,
                'name' => $offer->name,
                'url' => $offer->offer_url,
                'user' => $offer->user ? ['id' => $offer->user->id, 'name' => $offer->user->name, 'email' => $offer->user->email] : null,
                'links_count' => $oLinks->count(),
                'views' => $views,
                'clicks' => $clicks,
                'conversions' => $conversions,
                'revenue' => (float) ($conv->rev ?? 0),
                'view_conversion_rate' => self::pct($conversions, $views),
                'click_conversion_rate' => self::pct($conversions, $clicks),
                'created_at' => optional($offer->created_at)->toISOString(),
            ];
        });

        return ['data' => $rows];
    }

    /**
     * Every tracking link across all clients with owner + offer + aggregates.
     * Optional `q` searches link/offer name or owner name/email.
     */
    public function links(Request $request)
    {
        $query = TrackingLink::query()->with(['event:id,name,offer_url,user_id', 'event.user:id,name,email']);

        if ($term = trim((string) $request->input('q', ''))) {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(placement) LIKE ?', [$like])
                    ->orWhereHas('event', fn ($e) => $e
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(offer_url) LIKE ?', [$like])
                        ->orWhereHas('user', fn ($u) => $u
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$like])));
            });
        }

        $links = $query->latest()->limit(200)->get();
        $linkIds = $links->pluck('id');

        $clicksByLink = TrackingClick::whereIn('tracking_link_id', $linkIds)
            ->select('tracking_link_id', DB::raw('count(*) as c'))
            ->groupBy('tracking_link_id')->pluck('c', 'tracking_link_id');

        $convByLink = TrackingConversion::whereIn('tracking_link_id', $linkIds)
            ->select('tracking_link_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(value),0) as rev'))
            ->groupBy('tracking_link_id')->get()->keyBy('tracking_link_id');

        $rows = $links->map(function (TrackingLink $link) use ($clicksByLink, $convByLink) {
            $views = max(0, (int) ($link->current_view_count ?? 0) - (int) ($link->initial_view_count ?? 0));
            $clicks = (int) ($clicksByLink[$link->id] ?? 0);
            $conv = $convByLink->get($link->id);
            $conversions = (int) ($conv->c ?? 0);
            $offer = $link->event;

            return [
                'id' => $link->id,
                'name' => $link->name,
                'placement' => $link->placement,
                'parameter_id' => $link->parameter_id,
                'offer' => $offer ? ['id' => $offer->id, 'name' => $offer->name, 'url' => $offer->offer_url] : null,
                'user' => $offer && $offer->user ? ['id' => $offer->user->id, 'name' => $offer->user->name, 'email' => $offer->user->email] : null,
                'views' => $views,
                'clicks' => $clicks,
                'conversions' => $conversions,
                'revenue' => (float) ($conv->rev ?? 0),
                'view_conversion_rate' => self::pct($conversions, $views),
                'click_conversion_rate' => self::pct($conversions, $clicks),
                'created_at' => optional($link->created_at)->toISOString(),
            ];
        });

        return ['data' => $rows];
    }
}
