<?php

namespace App\Jobs;

use App\Models\OutlierBreakdown;
use App\Models\OutlierVideo;
use App\Services\AnthropicService;
use App\Services\CaptApiOutlierService;
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

    public function handle(CaptApiService $captApi, AnthropicService $anthropic, CaptApiOutlierService $captApiOutliers): void
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

            $url = $this->videoUrl($video, $captApiOutliers);
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

    /**
     * The URL CaptAPI gets for the transcript. TikTok links need the creator's
     * real @handle in the path — CaptAPI parses it, and a display name ("Tunde
     * Alao") makes it read the link as a profile. Channels ingested before we
     * stored handles get one back-filled here via a single video-details call.
     */
    private function videoUrl(OutlierVideo $video, CaptApiOutlierService $captApiOutliers): string
    {
        $channel = $video->channel;

        if ($this->platform === 'tiktok' && $channel && $channel->handle === null) {
            try {
                $captApiOutliers->fetchAndStore('tiktok', self::nativeUrl('tiktok', $this->videoId, $channel->channel_name));
                $channel->refresh();
            } catch (\Throwable $e) {
                Log::info('GenerateOutlierBreakdownJob could not back-fill the TikTok handle', [
                    'video_id' => $this->videoId, 'channel_id' => $channel->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return self::nativeUrl($this->platform, $this->videoId, $channel?->handle ?? $channel?->channel_name);
    }

    /** Canonical native watch/permalink URL (mirror of the FE's outlierUrl helper). */
    public static function nativeUrl(string $platform, string $videoId, ?string $handle = null): string
    {
        return match ($platform) {
            // TikTok handles are [A-Za-z0-9._]; strip anything else so a display
            // name passed by a legacy caller still yields a parseable video link.
            'tiktok' => 'https://www.tiktok.com/@'.preg_replace('/[^A-Za-z0-9._]/', '', (string) $handle).'/video/'.$videoId,
            'instagram' => "https://www.instagram.com/p/{$videoId}/",
            default => "https://www.youtube.com/watch?v={$videoId}",
        };
    }
}
