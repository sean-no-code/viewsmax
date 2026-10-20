<?php

namespace App\Http\Controllers;

use App\Exceptions\TranscriptUnavailableException;
use App\Services\CaptApiService;
use App\Support\UserSafeError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            return response()->json(['success' => false, 'message' => UserSafeError::detail($e)], 422);
        } catch (TranscriptUnavailableException $e) {
            // The video has no captions. The provider's answer, not our bug: no report().
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (ConnectionException $e) {
            // CaptAPI was unreachable or too slow (Instagram/TikTok transcribe audio and
            // can run past our timeout). That's the provider, not a bug: warn, don't
            // page Sentry, and tell the caller to retry.
            Log::warning('CaptAPI transcript request timed out', [
                'platform' => $validated['platform'],
                'timeout' => config('services.captapi.transcript_timeout'),
            ]);

            return response()->json(['success' => false, 'message' => UserSafeError::message($e, '')], 504);
        } catch (\Throwable $e) {
            // Anything else is our bug, not the caller's: report it so it reaches Sentry.
            report($e);

            return response()->json(['success' => false, 'message' => UserSafeError::detail($e)], 502);
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
