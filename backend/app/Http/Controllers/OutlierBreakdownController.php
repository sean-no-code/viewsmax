<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateOutlierBreakdownJob;
use App\Models\OutlierBreakdown;
use App\Models\OutlierVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Outliers
 *
 * AI breakdowns of outlier videos. Generation is queued (transcript fetch +
 * Claude call are slow); the FE polls show() until status is completed/failed.
 * Breakdowns are per-video, not per-user — one generation serves everyone.
 */
class OutlierBreakdownController extends Controller
{
    /** Minutes after which a pending/processing row is assumed crashed and re-queued. */
    private const STALE_MINUTES = 10;

    /**
     * Get an outlier's AI breakdown
     *
     * `status` is none (never generated), pending/processing (poll again),
     * completed (`payload` holds the analysis) or failed (`error`).
     *
     * @urlParam platform string required youtube, tiktok, or instagram. Example: youtube
     * @urlParam videoId string required The platform's video id. Example: dQw4w9WgXcQ
     */
    public function show(Request $request, string $platform, string $videoId): JsonResponse
    {
        $breakdown = OutlierBreakdown::where('platform', $platform)->where('video_id', $videoId)->first();

        return response()->json(['success' => true, 'data' => $this->payload($breakdown)]);
    }

    /**
     * Generate an outlier's AI breakdown
     *
     * Queues generation (transcript + LLM analysis); poll the GET endpoint until
     * `status` is completed. An existing breakdown is returned, not regenerated.
     *
     * @urlParam platform string required youtube, tiktok, or instagram. Example: youtube
     * @urlParam videoId string required The platform's video id. Example: dQw4w9WgXcQ
     */
    public function store(Request $request, string $platform, string $videoId): JsonResponse
    {
        $videoExists = OutlierVideo::where('platform', $platform)
            ->where('youtube_video_id', $videoId)
            ->exists();

        if (! $videoExists) {
            return response()->json(['success' => false, 'message' => 'Video not found'], 404);
        }

        $breakdown = OutlierBreakdown::firstOrCreate(
            ['platform' => $platform, 'video_id' => $videoId],
            ['status' => OutlierBreakdown::STATUS_PENDING],
        );

        $isStale = in_array($breakdown->status, [OutlierBreakdown::STATUS_PENDING, OutlierBreakdown::STATUS_PROCESSING], true)
            && ! $breakdown->wasRecentlyCreated
            && $breakdown->updated_at?->lt(now()->subMinutes(self::STALE_MINUTES));

        if ($breakdown->wasRecentlyCreated || $breakdown->status === OutlierBreakdown::STATUS_FAILED || $isStale) {
            if (! $breakdown->wasRecentlyCreated) {
                $breakdown->update(['status' => OutlierBreakdown::STATUS_PENDING, 'error' => null]);
            }
            GenerateOutlierBreakdownJob::dispatch($platform, $videoId);
        }

        return response()->json(['success' => true, 'data' => $this->payload($breakdown)]);
    }

    private function payload(?OutlierBreakdown $breakdown): array
    {
        if (! $breakdown) {
            return ['status' => 'none', 'payload' => null, 'error' => null];
        }

        return [
            'status' => $breakdown->status,
            'payload' => $breakdown->payload,
            'error' => $breakdown->error,
            'updated_at' => $breakdown->updated_at?->toIso8601String(),
        ];
    }
}
