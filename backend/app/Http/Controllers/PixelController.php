<?php

namespace App\Http\Controllers;

use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingLink;
use App\Models\TrackingPageView;
use App\Models\TrackingVisitor;
use App\Models\User;
use App\Services\PlatformClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PixelController extends Controller
{
    // Cap attribution lookback to the click cookie's lifetime so an immortal
    // localStorage visitor id can't credit a click from months ago.
    private const ATTRIBUTION_LOOKBACK_DAYS = 30;

    /**
     * Handle a Click Event.
     * POST /api/track/click
     */
    public function trackClick(Request $request)
    {
        $request->validate([
            'trk' => 'required|string',
            'visitor_id' => 'nullable|uuid',
            'public_id' => 'required|uuid',
            // Attribution fields are best-effort: accept any string and truncate
            // on store (see attributionFields). Never let an oversized real URL
            // reject the click/conversion that carries the revenue.
            'referrer' => 'nullable|string',
            'landing_url' => 'nullable|string',
            'utm_source' => 'nullable|string',
            'utm_medium' => 'nullable|string',
            'utm_campaign' => 'nullable|string',
            'utm_term' => 'nullable|string',
            'utm_content' => 'nullable|string',
        ]);

        $trk = $request->input('trk');
        $visitorUuid = $request->input('visitor_id');

        // 1. Find the Link
        $link = TrackingLink::where('parameter_id', $trk)->with('event')->first();

        if (!$link) {
            return response()->json(['error' => 'Invalid Link'], 404);
        }

        // Validate Ownership (Strict)
        if ($link->event->user->public_id !== $request->input('public_id')) {
             Log::warning('Tracking Public ID mismatch', [
                 'expected' => $link->event->user->public_id,
                 'received' => $request->input('public_id')
             ]);
             return response()->json(['error' => 'Invalid Tracking Context'], 403);
        }

        // 2. Resolve Visitor
        $visitor = null;
        if ($visitorUuid) {
            $visitor = TrackingVisitor::where('visitor_id', $visitorUuid)->first();
        }

        if (!$visitor) {
            $visitorUuid = (string) Str::uuid();
            $visitor = TrackingVisitor::create([
                'visitor_id' => $visitorUuid,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } else {
            // Update IP/User Agent on return visit? Maybe not needed for strict attribution but good for logging 'last seen'.
            // For now, keep it simple.
        }

        // 3. Log Click
        // Skip automated security scanners (e.g. Google Safe Browsing) which hit
        // freshly-created links within seconds and inflate the click count.
        $isAutomatedScan = $this->isLikelyBot($request->userAgent())
            || $link->created_at->gt(now()->subSeconds(60));

        // Throttle: Don't log if clicked same link in last 15 minutes
        $recentClick = TrackingClick::where('tracking_visitor_id', $visitor->id)
            ->where('tracking_link_id', $link->id)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();

        if (!$isAutomatedScan && !$recentClick) {
            $click = TrackingClick::create([
                'tracking_visitor_id' => $visitor->id,
                'tracking_link_id' => $link->id,
                'created_at' => now(),
            ] + $this->attributionFields($request));

            $this->recordFirstTouch($visitor, $click);
        }

        // 4. Return Data
        return response()->json([
            'visitor_id' => $visitor->visitor_id,
            'goals' => $link->event->goals->pluck('conversion_url'),
        ]);
    }

    /**
     * Handle a Page View.
     * POST /api/track/pageview
     *
     * Fired by tracker.js on EVERY load of a page carrying the user's meta tag
     * — this is what makes "Visitors" mean real site visitors (not just people
     * who arrived through a tracking link) and feeds the Sources/Referrers
     * acquisition tables.
     */
    public function trackPageView(Request $request)
    {
        $request->validate([
            'public_id' => 'required|uuid',
            'visitor_id' => 'nullable|uuid',
            'url' => 'required|string',
            'referrer' => 'nullable|string',
            'utm_source' => 'nullable|string',
        ]);

        $user = User::where('public_id', $request->input('public_id'))->first();
        if (! $user) {
            return response()->json(['error' => 'Invalid Tracking Context'], 404);
        }

        // Bots don't get a visitor identity or a pageview row.
        if ($this->isLikelyBot($request->userAgent())) {
            return response()->json(['visitor_id' => $request->input('visitor_id')]);
        }

        $visitor = null;
        if ($request->input('visitor_id')) {
            $visitor = TrackingVisitor::where('visitor_id', $request->input('visitor_id'))->first();
        }
        if (! $visitor) {
            $visitor = TrackingVisitor::create([
                'visitor_id' => (string) Str::uuid(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $url = mb_substr((string) $request->input('url'), 0, 2048);
        $path = mb_substr((string) (parse_url($url, PHP_URL_PATH) ?: '/'), 0, 512);

        // Refresh spam guard: the same visitor reloading the same path within
        // 30 seconds counts once.
        $recent = TrackingPageView::where('tracking_visitor_id', $visitor->id)
            ->where('path', $path)
            ->where('created_at', '>=', now()->subSeconds(30))
            ->exists();

        if (! $recent) {
            TrackingPageView::create([
                'user_id' => $user->id,
                'tracking_event_id' => $this->matchOfferByUrl($user, $url),
                'tracking_visitor_id' => $visitor->id,
                'url' => $url,
                'path' => $path,
                'referrer' => $request->input('referrer') ? mb_substr($request->input('referrer'), 0, 2048) : null,
                'inferred_platform' => PlatformClassifier::forClick($request->input('referrer'), $request->input('utm_source')),
                'created_at' => now(),
            ]);
        }

        return response()->json(['visitor_id' => $visitor->visitor_id]);
    }

    /** The user's offer whose offer_url matches the viewed page, if any. */
    private function matchOfferByUrl(User $user, string $url): ?int
    {
        $normalize = function (string $u): string {
            $parts = parse_url(trim($u));
            $host = strtolower(preg_replace('/^www\./i', '', $parts['host'] ?? ''));

            return $host.rtrim($parts['path'] ?? '', '/');
        };

        $target = $normalize($url);

        return $user->offers()->get()
            ->first(fn ($o) => $o->offer_url && $normalize($o->offer_url) === $target)?->id;
    }

    /**
     * Handle a Conversion Event.
     * POST /api/track/conversion
     */
    public function trackConversion(Request $request)
    {
        $request->validate([
            'visitor_id' => 'required|uuid',
            'current_url' => 'required|string', // To verify match
            'trk' => 'nullable|string', // For Direct Link Backfill
            'public_id' => 'required|uuid',
            // Best-effort attribution — accept any string, truncate on store.
            'referrer' => 'nullable|string',
            'landing_url' => 'nullable|string',
            'utm_source' => 'nullable|string',
            'utm_medium' => 'nullable|string',
            'utm_campaign' => 'nullable|string',
            'utm_term' => 'nullable|string',
            'utm_content' => 'nullable|string',
        ]);

        $visitorUuid = $request->input('visitor_id');
        $currentUrl = $request->input('current_url');
        $trk = $request->input('trk');

        $visitor = TrackingVisitor::where('visitor_id', $visitorUuid)->first();

        // BACKFILL LOGIC: If trk is provided but no visitor/click exists
        if (!$visitor && $trk) {
             // Resolve Link. Skip automated scanners so they don't backfill a phantom click.
             $link = TrackingLink::where('parameter_id', $trk)->first();
             if ($link && !$this->isLikelyBot($request->userAgent())) {
                 // Create Visitor
                 $visitorUuid = $visitorUuid ?: (string) Str::uuid();
                 $visitor = TrackingVisitor::create([
                     'visitor_id' => $visitorUuid,
                     'ip_address' => $request->ip(),
                     'user_agent' => $request->userAgent(),
                 ]);
                 // Backfill Click
                 $backfillClick = TrackingClick::create([
                     'tracking_visitor_id' => $visitor->id,
                     'tracking_link_id' => $link->id,
                     'created_at' => now(), // Assume click happened just now
                 ] + $this->attributionFields($request));
                 $this->recordFirstTouch($visitor, $backfillClick);
             }
        }

        if (!$visitor) {
            return response()->json(['error' => 'Visitor not found'], 404);
        }

        // Find the "Active" Event for this visitor — capped to the attribution
        // lookback window so a stale (immortal-localStorage) click can't convert.
        $lastClick = TrackingClick::where('tracking_visitor_id', $visitor->id)
            ->where('created_at', '>=', now()->subDays(self::ATTRIBUTION_LOOKBACK_DAYS))
            ->with(['link.event'])
            ->latest('created_at')
            ->first();

        if (!$lastClick) {
             return response()->json(['error' => 'No click history'], 400); 
        }

        if (!$lastClick->link || !$lastClick->link->event) {
             Log::error('Tracking Data Integrity Error: Click found but Link or Event missing', ['click_id' => $lastClick->id]);
             return response()->json(['error' => 'Tracking data error'], 500);
        }

        $event = $lastClick->link->event;
        $event->load('goals'); // Load goals to check against

        // Match Logic: Check against ALL goals
        $matchedGoal = null;
        $currentPath = parse_url($currentUrl, PHP_URL_PATH);

        foreach ($event->goals as $goal) {
             // Parse Goal URL (might be absolute or relative)
             $goalPath = parse_url($goal->conversion_url, PHP_URL_PATH) ?? $goal->conversion_url;

             // Debug: Log::debug("Matching: Current: $currentPath vs Goal: $goalPath");

             // Strict Equality Check on Pathname
             if ($currentPath === $goalPath) {
                 $matchedGoal = $goal;
                 break;
             }
        }
        
        if (!$matchedGoal) {
             // For MVP, stick to Last Click event's goals.
             Log::warning('Tracking URL mismatch: ' . $currentUrl . ' does not match any goal for event ' . $event->id);
             return response()->json(['status' => 'ignored', 'reason' => 'URL mismatch'], 200); // 200 to silence FE errors
        }

        // Validate Ownership (Strict)
        if ($event->user->public_id !== $request->input('public_id')) {
             Log::warning('Conversion Public ID mismatch', [
                 'expected' => $event->user->public_id,
                 'received' => $request->input('public_id')
             ]);
             return response()->json(['error' => 'Invalid Tracking Context'], 403);
        }

        // De-dupe Conversions?
        // Check if this visitor already converted on this event in the last 5 minutes? 
        // Or just log it. RevTrack logs all.
        
        // De-dupe Conversions
        // Check if this visitor already converted on this specific goal type for this event
        $alreadyConverted = TrackingConversion::where('tracking_visitor_id', $visitor->id)
            ->where('tracking_event_id', $event->id)
            ->where('event_type', $matchedGoal->event_type)
            ->exists();

        if ($alreadyConverted) {
             return response()->json(['status' => 'already_converted', 'value' => $event->conversion_value], 200);
        }

        // Persist the resolved last-touch attribution (click + link) and the
        // visitor's first touch, so downstream analytics are plain joins instead
        // of the old set-membership reconstruction that double-counted.
        TrackingConversion::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_event_id' => $event->id,
            'tracking_click_id' => $lastClick->id,
            'tracking_link_id' => $lastClick->tracking_link_id,
            'first_touch_click_id' => $visitor->first_touch_click_id,
            'event_type' => $matchedGoal->event_type,
            'value' => $matchedGoal->conversion_value ?? 0,
        ]);

        return response()->json(['status' => 'converted', 'value' => $matchedGoal->conversion_value ?? 0, 'type' => $matchedGoal->event_type]);
    }

    /**
     * Attribution metadata to store on a click: raw referrer/landing/UTM plus a
     * derived platform label (utm_source wins, else referrer host classification).
     */
    private function attributionFields(Request $request): array
    {
        $referrer = $request->input('referrer');
        // Truncate to protect the columns (referrer/landing_url are TEXT; utm_*
        // are varchar(255)) — an oversized real URL must never fail the insert.
        $cap = fn (?string $v, int $len): ?string => $v === null ? null : mb_substr($v, 0, $len);

        return [
            'referrer' => $cap($referrer, 2048),
            'landing_url' => $cap($request->input('landing_url'), 2048),
            'utm_source' => $cap($request->input('utm_source'), 255),
            'utm_medium' => $cap($request->input('utm_medium'), 255),
            'utm_campaign' => $cap($request->input('utm_campaign'), 255),
            'utm_term' => $cap($request->input('utm_term'), 255),
            'utm_content' => $cap($request->input('utm_content'), 255),
            'inferred_platform' => PlatformClassifier::forClick($referrer, $request->input('utm_source')),
        ];
    }

    /**
     * Stamp the visitor's first-touch click exactly once (first click we ever
     * log for them), so first-touch attribution is available without re-deriving.
     */
    private function recordFirstTouch(TrackingVisitor $visitor, TrackingClick $click): void
    {
        if (empty($visitor->first_touch_click_id)) {
            $visitor->forceFill(['first_touch_click_id' => $click->id])->save();
        }
    }

    /**
     * Heuristic check for automated crawlers / security scanners (e.g. Google
     * Safe Browsing, link-preview bots) so their visits don't count as clicks.
     */
    private function isLikelyBot(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return true; // Real browsers always send a UA; empty = automated.
        }

        $ua = strtolower($userAgent);

        $signatures = [
            'bot', 'crawl', 'spider', 'slurp', 'bing', 'google', 'safebrowsing',
            'facebookexternalhit', 'facebot', 'whatsapp', 'telegrambot', 'slackbot',
            'discordbot', 'twitterbot', 'linkedinbot', 'embedly', 'pinterest',
            'headless', 'phantomjs', 'preview', 'monitor', 'pingdom', 'uptimerobot',
            'python-requests', 'python-urllib', 'go-http-client', 'curl', 'wget',
            'axios', 'okhttp', 'java/', 'libwww', 'scrapy', 'apache-httpclient',
        ];

        foreach ($signatures as $signature) {
            if (str_contains($ua, $signature)) {
                return true;
            }
        }

        return false;
    }
}
