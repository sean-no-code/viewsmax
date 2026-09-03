<?php

namespace App\Jobs;

use App\Models\OutlierVideo;
use App\Services\Exceptions\ProbeRateLimited;
use App\Services\VideoFormatClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Classifies freshly ingested YouTube outliers as Shorts / long-form. Runs as
 * a follow-up to the search-term job so ~dozens of /shorts/ probes never sit
 * inside the search job's time budget. Skips rows already classified.
 */
class ClassifyOutlierVideoFormatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public $tries = 2;

    /** Seconds to wait before retrying the remainder after a rate-limit signal. */
    public const RETRY_AFTER = 300;

    /** @param string[] $videoIds YouTube video ids */
    public function __construct(public array $videoIds)
    {
    }

    public function handle(VideoFormatClassifier $classifier): void
    {
        $videos = OutlierVideo::where('platform', 'youtube')
            ->whereIn('youtube_video_id', $this->videoIds)
            ->whereNull('is_short')
            ->orderBy('id')
            ->get();

        $delayMs = (int) config('services.youtube.format_probe_delay_ms', 250);
        $counts = ['short' => 0, 'long' => 0, 'unknown' => 0];

        foreach ($videos as $i => $video) {
            try {
                $result = $classifier->ensureClassified($video);
            } catch (ProbeRateLimited $e) {
                $remaining = $videos->slice($i)->pluck('youtube_video_id')->values()->all();
                Log::warning('[shorts-probe] rate limited — retrying the rest later', [
                    'remaining' => count($remaining), 'error' => $e->getMessage(),
                ]);
                static::dispatch($remaining)->delay(now()->addSeconds(self::RETRY_AFTER));

                return;
            }

            $counts[$result === true ? 'short' : ($result === false ? 'long' : 'unknown')]++;

            if ($delayMs > 0 && $i < $videos->count() - 1) {
                usleep($delayMs * 1000);
            }
        }

        Log::info('[shorts-probe] classified search results', $counts + ['requested' => count($this->videoIds)]);
    }
}
