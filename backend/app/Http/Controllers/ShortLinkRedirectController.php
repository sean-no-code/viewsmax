<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use App\Models\ShortLinkClick;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The /l/{slug} public redirect. Counts the click inline (one UPDATE), writes
 * the detail row, and 302s to the destination. Unknown slugs bounce to the
 * frontend rather than 404ing a user who clicked a stale link.
 */
class ShortLinkRedirectController extends Controller
{
    public const VISITOR_COOKIE = 'vmx_visitor';

    public function __invoke(Request $request, string $slug)
    {
        $link = ShortLink::with('trackingLink')->where('slug', $slug)->first();

        if (! $link) {
            return redirect()->away(rtrim(config('app.frontend_url') ?: config('app.url'), '/'));
        }

        // Reuse the tracker's visitor UUID when the browser has one; otherwise
        // mint our own so repeat clicks correlate.
        $visitor = $request->cookie(self::VISITOR_COOKIE) ?: (string) Str::uuid();

        DB::transaction(function () use ($link, $request, $visitor) {
            $link->increment('clicks_count');
            $link->forceFill(['last_clicked_at' => now()])->save();
            ShortLinkClick::create([
                'short_link_id' => $link->id,
                'visitor_uuid' => Str::isUuid($visitor) ? $visitor : null,
                'referer' => Str::limit((string) $request->headers->get('referer'), 1000, ''),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
        });

        return redirect()->away($this->destinationFor($link))
            ->withCookie(cookie(self::VISITOR_COOKIE, $visitor, 60 * 24 * 365, '/', null, true, false));
    }

    /**
     * Offer-bound shortlinks land with ?trk={parameter_id} so the on-page
     * pixel attributes the visit (and any conversion) to the offer's link.
     */
    private function destinationFor(ShortLink $link): string
    {
        $destination = $link->destination_url;
        $trk = $link->trackingLink?->parameter_id;

        if (! $trk || str_contains($destination, 'trk=')) {
            return $destination;
        }

        return $destination.(str_contains($destination, '?') ? '&' : '?').'trk='.$trk;
    }
}
