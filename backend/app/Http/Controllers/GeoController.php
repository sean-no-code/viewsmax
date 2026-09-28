<?php

namespace App\Http\Controllers;

use App\Services\GeoLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Discovery
 */
class GeoController extends Controller
{
    /**
     * Visitor region
     *
     * Where the current visitor appears to be, and whether cookie-consent law
     * applies there (EU/EEA, UK, Switzerland). The SPA uses this to show the
     * cookie banner only where it is required. Unknown location reports
     * consent as required.
     *
     * @unauthenticated
     * @response 200 {"country_code":"DE","cookie_consent_required":true}
     */
    public function show(Request $request, GeoLocationService $geo): JsonResponse
    {
        // CloudFront / Cloudflare stamp the viewer's country on the request; use it when present.
        $cdnCountry = $request->header('CloudFront-Viewer-Country') ?? $request->header('CF-IPCountry');

        [, $code] = $geo->lookup($request->ip(), $cdnCountry);

        return response()->json([
            'country_code' => $code,
            'cookie_consent_required' => $geo->requiresCookieConsent($code),
        ])->header('Cache-Control', 'private, max-age=86400');
    }
}
