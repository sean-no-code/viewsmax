<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateOutlierBreakdownJob;
use App\Models\OutlierBreakdown;
use App\Models\OutlierVideo;
use App\Models\User;
use App\Models\UserEvent;
use App\Contracts\Quota\QuotaGate;
use App\Support\AnthropicRateLimit;
use App\Support\Quota\QuotaMessage;
use App\Support\Quota\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Outliers
 *
 * AI breakdowns of outlier videos. Generation is queued (Gemini watches the
 * whole video, which is slow); the FE polls show() until status is completed/failed.
 * Breakdowns are per-video, not per-user — one generation serves everyone.
 */
class OutlierBreakdownController extends Controller
{
    /** The page polls show() every few seconds: one "viewed" event per user, video and this many minutes. */
    private const VIEW_DEDUPE_MINUTES = 10;

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

        if ($breakdown?->status === OutlierBreakdown::STATUS_COMPLETED && $request->user() instanceof User) {
            $this->recordView($request->user(), $platform, $videoId);
        }

        return response()->json(['success' => true, 'data' => $this->payload($breakdown)]);
    }

    /**
     * Breakdowns carry no user_id, so this is the only record of who viewed one.
     * UserEvent skips MCP and API key fetches: only web app views count.
     */
    private function recordView(User $user, string $platform, string $videoId): void
    {
        $viewedRecently = UserEvent::query()
            ->where('user_id', $user->id)
            ->where('event_name', UserEvent::OUTLIER_BREAKDOWN_VIEWED)
            ->where('created_at', '>=', now()->subMinutes(self::VIEW_DEDUPE_MINUTES))
            ->where('metadata->platform', $platform)
            ->where('metadata->video_id', $videoId)
            ->exists();

        if (! $viewedRecently) {
            UserEvent::record($user, UserEvent::OUTLIER_BREAKDOWN_VIEWED, ['platform' => $platform, 'video_id' => $videoId]);
        }
    }

    /**
     * Generate an outlier's AI breakdown
     *
     * Queues generation (the AI watches the video: transcript + analysis); poll the GET endpoint until
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

        if ($breakdown->wasRecentlyCreated || $breakdown->needsRequeue()) {
            // Daily allowance per plan. Only a new (or failed, re-run) analysis
            // counts; an existing breakdown above was returned for free.
            $user = $request->user();
            $gate = app(QuotaGate::class);
            $decision = $gate->allows(QuotaGate::BREAKDOWNS, Tier::of($user), 1, $user?->id);
            if (! $decision->allowed) {
                if ($breakdown->wasRecentlyCreated) {
                    $breakdown->delete();
                }

                return response()->json([
                    'success' => false,
                    'code' => 'quota_paused',
                    'message' => QuotaMessage::for($decision, $user),
                    'scope' => $decision->scope,
                    'reason' => $decision->reason,
                    'plan' => QuotaMessage::planLabel($user),
                    'upgrade' => QuotaMessage::offersUpgrade($decision),
                    'used' => $decision->used,
                    'cap' => $decision->cap,
                    'resets_at' => $decision->resetsAt->format(DATE_ATOM),
                ], 429);
            }

            if ($limited = AnthropicRateLimit::hit($request->user(), 'outlier_breakdown')) {
                if ($breakdown->wasRecentlyCreated) {
                    $breakdown->delete(); // don't leave a pending row no job will ever pick up
                }

                return $limited;
            }

            if (! $breakdown->wasRecentlyCreated) {
                $breakdown->update(['status' => OutlierBreakdown::STATUS_PENDING, 'error' => null]);
            }
            GenerateOutlierBreakdownJob::dispatch($platform, $videoId);
            if ($user) {
                $gate->recordUserFetch($user->id, QuotaGate::BREAKDOWNS);
            }
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
