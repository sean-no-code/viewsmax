<?php

namespace App\Http\Controllers;

use App\Exceptions\GeminiVideoException;
use App\Exceptions\TranscriptUnavailableException;
use App\Services\TranscriptService;
use App\Support\UnsafeUrlException;
use App\Support\UserSafeError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public endpoint behind the free transcript tools (YouTube / TikTok / Instagram).
 * Gemini transcribes the video (TranscriptService, DB-cached). Kept server-side
 * so no API key reaches the browser. Throttled per IP (route: throttle:transcript)
 * and every request clips the video (services.gemini.transcript_max_video_seconds).
 */
class FreeToolTranscriptController extends Controller
{
    public function store(Request $request, TranscriptService $transcripts): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'url' => 'required|url|max:2048',
        ]);

        try {
            $result = $transcripts->getTranscript($validated['platform'], $validated['url']);
        } catch (\InvalidArgumentException|UnsafeUrlException $e) {
            return response()->json(['success' => false, 'message' => UserSafeError::detail($e) ?? $e->getMessage()], 422);
        } catch (TranscriptUnavailableException $e) {
            // The video has no spoken audio. The provider's answer, not our bug: no report().
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (GeminiVideoException $e) {
            if (in_array($e->reason, [GeminiVideoException::VIDEO_UNAVAILABLE, GeminiVideoException::MEDIA_UNAVAILABLE, GeminiVideoException::TOO_LARGE], true)) {
                if ($e->reportable) {
                    report($e);
                }

                return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
            }
            if (! $e->reportable) {
                // Busy / slow provider: tell the caller to retry; don't page Sentry.
                return response()->json(['success' => false, 'message' => $e->getMessage()], 504);
            }
            report($e);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        } catch (ConnectionException $e) {
            // CaptAPI (TikTok / Instagram media lookup) was unreachable or too slow.
            Log::channel('gemini')->warning('transcript media lookup timed out', ['platform' => $validated['platform']]);

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
