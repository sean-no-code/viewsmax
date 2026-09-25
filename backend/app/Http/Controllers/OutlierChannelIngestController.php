<?php

namespace App\Http\Controllers;

use App\Jobs\IngestOutlierChannelJob;
use App\Models\OutlierChannel;
use App\Models\OutlierChannelIngest;
use App\Models\OutlierCompetitorChannel;
use App\Support\OutlierProfileInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Outliers
 *
 * Add a creator's channel to the outlier database by profile URL or @handle
 * (YouTube, TikTok, Instagram). The creator's recent videos are pulled in the
 * background and scored against each other; the channel is added to the
 * user's competitor list. No AI breakdowns are generated.
 */
class OutlierChannelIngestController extends Controller
{
    /** Repeat adds inside this window are served from the DB, no provider call. */
    public const DEDUPE_HOURS = 24;

    /** A queued/processing ingest for the same channel is reused instead of duplicated. */
    private const INFLIGHT_MINUTES = 10;

    /**
     * Add a channel
     *
     * Queue a pull of the creator's most recent videos. Returns HTTP 200 with
     * `status: done` when the channel was already pulled in the last 24 hours,
     * otherwise HTTP 202 with an `ingest_id` to poll.
     *
     * @bodyParam input string required Profile URL or @handle. Example: https://www.tiktok.com/@khaby.lame
     * @bodyParam platform string Required for a bare @handle: youtube, tiktok or instagram. Example: tiktok
     * @bodyParam max_videos integer How many recent videos to pull (5-50, default 10). Example: 10
     */
    public function store(Request $request): JsonResponse
    {
        if (! config('services.outliers.channel_ingest_enabled')) {
            return response()->json(['message' => 'Adding channels is not available right now.'], 404);
        }

        $validated = $request->validate([
            'platform' => 'nullable|string|in:youtube,tiktok,instagram',
            'input' => 'required|string|max:500',
            'max_videos' => 'nullable|integer|min:5|max:50',
        ]);

        try {
            $parsed = OutlierProfileInput::parse($validated['platform'] ?? null, $validated['input']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $userId = $request->user()->id;

        $channel = $this->findChannel($parsed);
        if ($channel && $channel->last_ingested_at && $channel->last_ingested_at->gt(now()->subHours(self::DEDUPE_HOURS))) {
            OutlierCompetitorChannel::firstOrCreate(['user_id' => $userId, 'channel_id' => $channel->id]);

            return response()->json([
                'status' => OutlierChannelIngest::STATUS_DONE,
                'queued' => false,
                'platform' => $parsed['platform'],
                'handle' => $parsed['handle'],
                'channel' => self::serializeChannel($channel),
            ]);
        }

        $ingest = OutlierChannelIngest::where('user_id', $userId)
            ->where('platform', $parsed['platform'])
            ->where('handle', $parsed['handle'])
            ->whereIn('status', [OutlierChannelIngest::STATUS_QUEUED, OutlierChannelIngest::STATUS_PROCESSING])
            ->where('created_at', '>', now()->subMinutes(self::INFLIGHT_MINUTES))
            ->latest('id')
            ->first();

        if (! $ingest) {
            $ingest = OutlierChannelIngest::create([
                'user_id' => $userId,
                'platform' => $parsed['platform'],
                'input' => trim($validated['input']),
                'handle' => $parsed['handle'],
                'status' => OutlierChannelIngest::STATUS_QUEUED,
                'max_videos' => $validated['max_videos'] ?? OutlierChannelIngest::DEFAULT_MAX_VIDEOS,
            ]);
            IngestOutlierChannelJob::dispatch($ingest->id);
        }

        return response()->json([
            'ingest_id' => $ingest->id,
            'status' => $ingest->status,
            'queued' => true,
            'platform' => $ingest->platform,
            'handle' => $ingest->handle,
        ], 202);
    }

    /**
     * Channel ingest status
     *
     * Poll an ingest started by the add endpoint until `status` is `done`
     * (then `channel` is set) or `failed` (then `error` explains why).
     *
     * @urlParam id integer required The ingest id. Example: 12
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $ingest = OutlierChannelIngest::with('channel')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json(['data' => [
            'ingest_id' => $ingest->id,
            'status' => $ingest->status,
            'error' => $ingest->error,
            'platform' => $ingest->platform,
            'handle' => $ingest->handle,
            'videos_added' => $ingest->videos_added,
            'channel' => $ingest->channel ? self::serializeChannel($ingest->channel) : null,
        ]]);
    }

    /**
     * The channel row a parsed input refers to, if it already exists. YouTube
     * channel ids map straight onto youtube_channel_id; everything else is
     * matched by platform + handle.
     */
    private function findChannel(array $parsed): ?OutlierChannel
    {
        if ($parsed['kind'] === 'channel_id') {
            return OutlierChannel::where('platform', 'youtube')->where('youtube_channel_id', $parsed['handle'])->first();
        }

        return OutlierChannel::where('platform', $parsed['platform'])->where('handle', $parsed['handle'])->first();
    }

    /**
     * Same `id`/`name`/`avatar`/`platform`/`subscriber_count` shape as the
     * channels list endpoint (so the app can drop it straight into the channel
     * picker), plus the numeric row id, handle and baseline.
     */
    public static function serializeChannel(OutlierChannel $channel): array
    {
        return [
            'id' => $channel->youtube_channel_id,
            'channel_id' => $channel->id,
            'name' => $channel->channel_name,
            'handle' => $channel->handle,
            'avatar' => $channel->profile_image_url,
            'platform' => $channel->platform,
            'subscriber_count' => $channel->subscriber_count,
            'average_views' => $channel->average_views,
            'last_ingested_at' => $channel->last_ingested_at?->toISOString(),
        ];
    }
}
