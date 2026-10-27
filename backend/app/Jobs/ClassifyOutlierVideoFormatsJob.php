<?php

namespace App\Jobs;

use App\Models\OutlierVideo;
use App\Services\VideoFormatClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Classifies freshly ingested YouTube outliers as Shorts / long-form from
 * their stored duration. Runs as a follow-up to the ingest job so the
 * classification is one place (VideoFormatClassifier) rather than repeated in
 * every ingest path. Skips rows already classified.
 */
class ClassifyOutlierVideoFormatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public $tries = 2;

    /**
     * @param  string[]  $videoIds  YouTube video ids
     */
    public function __construct(public array $videoIds) {}

    public function handle(VideoFormatClassifier $classifier): void
    {
        $videos = OutlierVideo::where('platform', 'youtube')
            ->whereIn('youtube_video_id', $this->videoIds)
            ->whereNull('is_short')
            ->orderBy('id')
            ->get();

        $counts = ['short' => 0, 'long' => 0, 'unknown' => 0];

        foreach ($videos as $video) {
            $result = $classifier->ensureClassified($video);
            $counts[$result === true ? 'short' : ($result === false ? 'long' : 'unknown')]++;
        }

        Log::info('[shorts-format] classified search results', $counts + ['requested' => count($this->videoIds)]);
    }
}
