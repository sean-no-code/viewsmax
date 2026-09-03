<?php

namespace App\Jobs;

use App\Models\OutlierBreakdown;
use App\Services\CaptApiOutlierService;
use App\Services\YouTubeChannelService;
use App\Support\UserSafeError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Downloads a pasted video URL into the outliers DB in the background (Instagram
 * scrapes can take minutes), then chains straight into breakdown generation so
 * the breakdown page's spinner rolls from "downloading" into "analysing".
 * Failures are recorded on the video's outlier_breakdowns row — the page polls
 * that row, so the error surfaces there.
 */
class IngestOutlierByUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 1;

    public function __construct(
        protected string $platform,
        protected string $url,
        protected string $videoId,
    ) {}

    public function handle(YouTubeChannelService $youtube, CaptApiOutlierService $captApiOutliers): void
    {
        try {
            $this->platform === 'youtube'
                ? $youtube->ingestOutlierByUrl($this->url)
                : $captApiOutliers->fetchAndStore($this->platform, $this->url);
        } catch (\Throwable $e) {
            Log::warning('IngestOutlierByUrlJob failed', [
                'platform' => $this->platform,
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);

            OutlierBreakdown::updateOrCreate(
                ['platform' => $this->platform, 'video_id' => $this->videoId],
                [
                    'status' => OutlierBreakdown::STATUS_FAILED,
                    'error' => 'Download failed: '.UserSafeError::message($e, 'Could not fetch that video. Check the URL and try again.'),
                    'payload' => null,
                ],
            );

            return;
        }

        OutlierBreakdown::firstOrCreate(
            ['platform' => $this->platform, 'video_id' => $this->videoId],
            ['status' => OutlierBreakdown::STATUS_PENDING],
        );
        GenerateOutlierBreakdownJob::dispatch($this->platform, $this->videoId);
    }

}
