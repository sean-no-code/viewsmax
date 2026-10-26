<?php

namespace App\Jobs;

use App\Exceptions\GeminiVideoException;
use App\Exceptions\TranscriptUnavailableException;
use App\Models\OutlierBreakdown;
use App\Models\OutlierVideo;
use App\Services\CaptApiOutlierService;
use App\Services\GeminiVideoService;
use App\Support\UserSafeError;
use App\Support\VideoSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Has Gemini watch the video and return a structured breakdown (transcript /
 * idea / hook / structure / visual layout), stored on the outlier_breakdowns
 * row for the video. Tokens and cost per call go to the `gemini` log channel.
 *
 * YouTube videos go to Gemini by URL. Instagram needs the file: the row's
 * CaptAPI media URL is used while it is fresh, otherwise one details call
 * refreshes it. TikTok has no obtainable file (CaptAPI exposes none), so
 * Gemini works from CaptAPI's transcript and the visual cards are inferred.
 *
 * Every failure is report()ed, not just logged — the exception handler sends
 * it to Sentry and the error log/Slack, with the platform + video id attached
 * via Context — except the provider's own answers (private video, busy,
 * timeout: GeminiVideoException with reportable = false), which are stored on
 * the row for the user and not reported.
 */
class GenerateOutlierBreakdownJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Must stay below the database queue's retry_after (330s): a job that
     * outlives it is handed to a second worker, which would run Gemini twice.
     */
    public $timeout = 300;

    public $tries = 1;

    public function __construct(
        protected string $platform,
        protected string $videoId,
    ) {}

    public function handle(GeminiVideoService $gemini, CaptApiOutlierService $captApiOutliers): void
    {
        $breakdown = OutlierBreakdown::where('platform', $this->platform)
            ->where('video_id', $this->videoId)
            ->first();

        if (! $breakdown || $breakdown->status === OutlierBreakdown::STATUS_COMPLETED) {
            return;
        }

        Context::add(['platform' => $this->platform, 'video_id' => $this->videoId]);
        $breakdown->update(['status' => OutlierBreakdown::STATUS_PROCESSING]);

        try {
            $video = OutlierVideo::with('channel')
                ->where('platform', $this->platform)
                ->where('youtube_video_id', $this->videoId)
                ->first();

            if (! $video) {
                throw new \RuntimeException('Video not found.');
            }

            $meta = [
                'title' => $video->title,
                'channel' => $video->channel?->channel_name ?? 'Unknown',
                'platform' => $this->platform,
                'duration_seconds' => $video->duration_in_seconds,
                'views' => $video->views,
                'like_count' => $video->like_count,
                'comment_count' => $video->comment_count,
                'video_id' => $this->videoId,
            ];

            if ($this->platform === 'tiktok') {
                // CaptAPI exposes no TikTok media file, so Gemini works from CaptAPI's transcript.
                $transcript = $captApiOutliers->fetchTranscript('tiktok', $this->tiktokUrl($video, $captApiOutliers));
                if (trim($transcript['text']) === '') {
                    throw new TranscriptUnavailableException();
                }
                $result = $gemini->breakdownFromTranscript($meta, $transcript['text'], $transcript['segments']);
            } else {
                $result = $gemini->breakdown($meta, $this->videoSource($video, $captApiOutliers));
            }

            $breakdown->update([
                'status' => OutlierBreakdown::STATUS_COMPLETED,
                'payload' => $result['payload'],
                'error' => null,
            ]);
        } catch (TranscriptUnavailableException $e) {
            // Nothing to transcribe: the provider's answer, stored for the user, not reported.
            Log::info('GenerateOutlierBreakdownJob: no transcript for video', ['platform' => $this->platform, 'video_id' => $this->videoId]);

            $breakdown->update(['status' => OutlierBreakdown::STATUS_FAILED, 'error' => $e->getMessage()]);
        } catch (GeminiVideoException $e) {
            if ($e->reportable) {
                report($e);
            } else {
                Log::info('GenerateOutlierBreakdownJob: provider could not analyse the video', [
                    'platform' => $this->platform,
                    'video_id' => $this->videoId,
                    'reason' => $e->reason,
                ]);
            }

            $breakdown->update([
                'status' => OutlierBreakdown::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            $breakdown->update([
                'status' => OutlierBreakdown::STATUS_FAILED,
                'error' => UserSafeError::message($e, 'Breakdown generation failed. Try again in a few minutes.'),
            ]);
        }
    }

    /**
     * Failures handle() never sees — a dependency that can't be built (e.g. a
     * missing API key), a timeout, a killed worker — would otherwise leave the
     * row pending and the page spinning. Mark it failed so a retry requeues it.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('GenerateOutlierBreakdownJob crashed', [
            'platform' => $this->platform,
            'video_id' => $this->videoId,
            'error' => $e->getMessage(),
        ]);

        OutlierBreakdown::where('platform', $this->platform)
            ->where('video_id', $this->videoId)
            ->where('status', '!=', OutlierBreakdown::STATUS_COMPLETED)
            ->update([
                'status' => OutlierBreakdown::STATUS_FAILED,
                'error' => 'Breakdown generation failed. Try again in a few minutes.',
            ]);
    }

    /**
     * The TikTok URL CaptAPI's transcript endpoint needs: it must carry the
     * creator's real @handle (a display name reads as a profile link). Channels
     * ingested before handles were stored get one back-filled through a single
     * video-details call.
     */
    private function tiktokUrl(OutlierVideo $video, CaptApiOutlierService $captApiOutliers): string
    {
        $channel = $video->channel;

        if ($channel && $channel->handle === null) {
            try {
                $captApiOutliers->fetchAndStore('tiktok', self::nativeUrl('tiktok', $this->videoId, $channel->channel_name));
                $channel->refresh();
            } catch (\Throwable $e) {
                Log::info('GenerateOutlierBreakdownJob could not back-fill the TikTok handle', [
                    'video_id' => $this->videoId, 'channel_id' => $channel->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return self::nativeUrl('tiktok', $this->videoId, $channel?->handle ?? $channel?->channel_name);
    }

    /**
     * Where Gemini gets the video. YouTube: the public watch URL. Instagram:
     * the CaptAPI media link stored on the row, refreshed through one details
     * call when it is missing or about to expire.
     */
    private function videoSource(OutlierVideo $video, CaptApiOutlierService $captApiOutliers): VideoSource
    {
        if ($this->platform === 'youtube') {
            return VideoSource::youtube($this->videoId);
        }

        $fresh = $video->video_url && $video->video_url_expires_at?->gt(now()->addMinutes(10));
        if (! $fresh) {
            $channel = $video->channel;
            $video = $captApiOutliers->fetchAndStore(
                $this->platform,
                self::nativeUrl($this->platform, $this->videoId, $channel?->handle ?? $channel?->channel_name),
            );
        }

        if (! $video->video_url) {
            throw GeminiVideoException::mediaUnavailable('no media url from CaptAPI');
        }

        return VideoSource::remote($video->video_url);
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
