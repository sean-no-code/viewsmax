<?php

namespace App\Jobs;

use App\Models\OutlierChannelIngest;
use App\Models\OutlierCompetitorChannel;
use App\Services\CaptApiOutlierService;
use App\Services\YouTubeChannelService;
use App\Support\OutlierProfileInput;
use App\Support\UserSafeError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pulls a creator's recent videos into the outlier DB for one "add channel"
 * request, then follows the channel for the requesting user. No AI breakdown
 * is generated — that stays opt-in per video. Progress and the user-facing
 * error live on the outlier_channel_ingests row, which the app and the MCP
 * tool poll.
 */
class IngestOutlierChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const FAILURE_FALLBACK = 'Could not load that channel. Check the handle and try again.';

    public $timeout = 300;

    public $tries = 1;

    public function __construct(public int $ingestId) {}

    public function handle(YouTubeChannelService $youtube, CaptApiOutlierService $captApiOutliers): void
    {
        $ingest = OutlierChannelIngest::find($this->ingestId);
        if (! $ingest || $ingest->status === OutlierChannelIngest::STATUS_DONE) {
            return;
        }

        $ingest->forceFill(['status' => OutlierChannelIngest::STATUS_PROCESSING, 'started_at' => now()])->save();

        try {
            if ($ingest->platform === 'youtube') {
                // The row stores the normalised handle; the original input says
                // whether it was an @handle, a /channel/UC… id or a legacy /user/ name.
                $parsed = OutlierProfileInput::parse('youtube', $ingest->input);
                $channelId = $youtube->resolveChannelId($parsed['handle'], $parsed['kind']);
                if ($channelId === null) {
                    throw new \InvalidArgumentException("Couldn't find a YouTube channel for @{$ingest->handle}.");
                }
                $result = $youtube->ingestChannelRecentVideos($channelId, $ingest->max_videos);
            } else {
                $result = $captApiOutliers->ingestChannel($ingest->platform, $ingest->handle, $ingest->max_videos);
            }
        } catch (\Throwable $e) {
            Log::warning('[channel-ingest] failed', [
                'ingest_id' => $ingest->id, 'platform' => $ingest->platform, 'handle' => $ingest->handle,
                'error' => $e->getMessage(),
            ]);
            $ingest->markFailed(UserSafeError::message($e, self::FAILURE_FALLBACK));

            return;
        }

        $channel = $result['channel'];
        $channel->forceFill(array_filter([
            'last_ingested_at' => now(),
            // YouTube only knows the handle when the API sends customUrl; keep what the user typed.
            'handle' => $channel->handle ?? $ingest->handle,
        ]))->save();

        $ingest->forceFill([
            'status' => OutlierChannelIngest::STATUS_DONE,
            'channel_id' => $channel->id,
            'videos_added' => count($result['video_ids']),
            'error' => null,
            'finished_at' => now(),
        ])->save();

        OutlierCompetitorChannel::firstOrCreate(['user_id' => $ingest->user_id, 'channel_id' => $channel->id]);

        Log::info('[channel-ingest] done', [
            'ingest_id' => $ingest->id, 'platform' => $ingest->platform, 'handle' => $ingest->handle,
            'channel_id' => $channel->id, 'videos_added' => count($result['video_ids']),
        ]);
    }

    /**
     * A worker timeout kills the process without reaching handle()'s catch —
     * mark the row failed here so the UI never spins on "processing" forever.
     */
    public function failed(\Throwable $e): void
    {
        $ingest = OutlierChannelIngest::find($this->ingestId);
        if ($ingest && $ingest->status !== OutlierChannelIngest::STATUS_DONE) {
            Log::warning('[channel-ingest] job failed hook', ['ingest_id' => $ingest->id, 'error' => $e->getMessage()]);
            $ingest->markFailed(UserSafeError::message($e, self::FAILURE_FALLBACK));
        }
    }
}
