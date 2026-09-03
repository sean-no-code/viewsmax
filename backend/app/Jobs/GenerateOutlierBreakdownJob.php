<?php

namespace App\Jobs;

use App\Models\OutlierBreakdown;
use App\Models\OutlierVideo;
use App\Services\AnthropicService;
use App\Services\CaptApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the video's transcript (CaptAPI) and asks Claude for a structured
 * breakdown (idea / hook / structure / visual layout / annotated transcript),
 * storing the result on the outlier_breakdowns row for the video.
 */
class GenerateOutlierBreakdownJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 1;

    public function __construct(
        protected string $platform,
        protected string $videoId,
    ) {}

    public function handle(CaptApiService $captApi, AnthropicService $anthropic): void
    {
        $breakdown = OutlierBreakdown::where('platform', $this->platform)
            ->where('video_id', $this->videoId)
            ->first();

        if (! $breakdown || $breakdown->status === OutlierBreakdown::STATUS_COMPLETED) {
            return;
        }

        $breakdown->update(['status' => OutlierBreakdown::STATUS_PROCESSING]);

        try {
            $video = OutlierVideo::with('channel')
                ->where('platform', $this->platform)
                ->where('youtube_video_id', $this->videoId)
                ->first();

            if (! $video) {
                throw new \RuntimeException('Video not found.');
            }

            $url = self::nativeUrl($this->platform, $this->videoId, $video->channel?->channel_name);
            $transcript = $captApi->getTranscript($this->platform, $url)['transcript'];

            if (trim((string) $transcript->text) === '') {
                throw new \RuntimeException('No transcript is available for this video.');
            }

            $payload = $anthropic->generateOutlierBreakdown(
                [
                    'title' => $video->title,
                    'channel' => $video->channel?->channel_name ?? 'Unknown',
                    'platform' => $this->platform,
                    'duration_seconds' => $video->duration_in_seconds,
                    'views' => $video->views,
                    'like_count' => $video->like_count,
                    'comment_count' => $video->comment_count,
                ],
                (string) $transcript->text,
                $transcript->segments ?? [],
            );

            $breakdown->update([
                'status' => OutlierBreakdown::STATUS_COMPLETED,
                'payload' => $payload,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateOutlierBreakdownJob failed', [
                'platform' => $this->platform,
                'video_id' => $this->videoId,
                'error' => $e->getMessage(),
            ]);

            $breakdown->update([
                'status' => OutlierBreakdown::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Canonical native watch/permalink URL (mirror of the FE's outlierUrl helper). */
    public static function nativeUrl(string $platform, string $videoId, ?string $handle = null): string
    {
        return match ($platform) {
            'tiktok' => 'https://www.tiktok.com/@'.ltrim((string) $handle, '@').'/video/'.$videoId,
            'instagram' => "https://www.instagram.com/p/{$videoId}/",
            default => "https://www.youtube.com/watch?v={$videoId}",
        };
    }
}
