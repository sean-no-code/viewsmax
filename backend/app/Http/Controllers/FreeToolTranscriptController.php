<?php

namespace App\Http\Controllers;

use App\Services\CaptApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint behind the free transcript tools (YouTube / TikTok / Instagram).
 * Fetches via CaptApiService (DB-cached). Kept server-side so the API key is never
 * exposed to the browser. Throttled per IP (route: throttle:transcript).
 */
class FreeToolTranscriptController extends Controller
{
    public function store(Request $request, CaptApiService $captApi): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'url' => 'required|url|max:2048',
        ]);

        try {
            $result = $captApi->getTranscript($validated['platform'], $validated['url']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        $t = $result['transcript'];

        return response()->json([
            'success' => true,
            'data' => [
                'platform' => $t->platform,
                'url' => $t->source_url,
                'text' => $t->text,
                'segments' => $t->segments,
                'language' => $t->language,
                'cached' => $result['cached'],
            ],
        ]);
    }
}
