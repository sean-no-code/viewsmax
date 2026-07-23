<?php

namespace App\Jobs;

use App\Models\ThumbnailScore;
use App\Models\Video;
use App\Services\ThumbnailAnalyzer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeThumbnailJob implements ShouldQueue
{
    use Queueable, Batchable;

    public $videoId;
    public $tries = 3;
    public $timeout = 600; // 10 minutes to allow for image download + API responses

    /**
     * Create a new job instance.
     */
    public function __construct($videoId)
    {
        $this->videoId = $videoId;

        Log::info('AnalyzeThumbnailJob constructor called', [
            'video_id' => $this->videoId,
            'video_id_type' => gettype($this->videoId)
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(ThumbnailAnalyzer $thumbnailAnalyzer): void
    {
        $startTime = microtime(true);

        Log::info('AnalyzeThumbnailJob started', [
            'video_id' => $this->videoId,
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries
        ]);

        try {
            // Find the video
            $video = Video::findOrFail($this->videoId);

            Log::info('Video found for thumbnail analysis', [
                'video_id' => $this->videoId,
                'title' => $video->title,
                'youtube_video_id' => $video->youtube_video_id
            ]);

            // Find or create the thumbnail score record
            $thumbnailScore = ThumbnailScore::firstOrNew(['video_id' => $this->videoId]);

            // Check if video has youtube_video_id
            if (empty($video->youtube_video_id)) {
                $errorMessage = 'Video does not have a YouTube video ID for thumbnail analysis';
                Log::warning($errorMessage, ['video_id' => $this->videoId]);

                $thumbnailScore->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'completed_at' => now()
                ]);
                return;
            }

            // Update status to processing
            $thumbnailScore->update([
                'status' => 'processing',
                'started_at' => now(),
                'error_message' => null,
                'thumbnail_file_path' => null
            ]);

            // Download the thumbnail
            $thumbnailPath = $thumbnailAnalyzer->downloadThumbnail($video->youtube_video_id);

            // Update the record with the thumbnail path
            $thumbnailScore->update(['thumbnail_file_path' => $thumbnailPath]);

            // Perform the analysis
            $scores = $thumbnailAnalyzer->analyzeThumbnail($thumbnailPath, $video->title);

            // Update with results
            $thumbnailScore->update(array_merge($scores, [
                'status' => 'completed',
                'completed_at' => now(),
                'error_message' => null
            ]));

            $totalTime = microtime(true) - $startTime;

            Log::info('AnalyzeThumbnailJob completed successfully', [
                'video_id' => $this->videoId,
                'thumbnail_path' => $thumbnailPath,
                'total_time_seconds' => round($totalTime, 2),
                'scores_count' => count($scores),
                'final_status' => 'completed'
            ]);

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;

            Log::error('AnalyzeThumbnailJob failed', [
                'video_id' => $this->videoId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'total_time_seconds' => round($totalTime, 2),
                'attempts' => $this->attempts(),
                'max_tries' => $this->tries
            ]);

            // Update thumbnail score with failure status
            try {
                $thumbnailScore = ThumbnailScore::firstOrNew(['video_id' => $this->videoId]);
                $thumbnailScore->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now()
                ]);
            } catch (\Exception $updateException) {
                Log::error('Failed to update ThumbnailScore status after job failure', [
                    'video_id' => $this->videoId,
                    'original_error' => $e->getMessage(),
                    'update_error' => $updateException->getMessage()
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeThumbnailJob permanently failed', [
            'video_id' => $this->videoId,
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'final_attempts' => $this->attempts(),
            'max_tries' => $this->tries
        ]);

        // Ensure the thumbnail score is marked as failed
        try {
            $thumbnailScore = ThumbnailScore::firstOrNew(['video_id' => $this->videoId]);
            $thumbnailScore->update([
                'status' => 'failed',
                'error_message' => 'Job permanently failed: ' . $exception->getMessage(),
                'completed_at' => now()
            ]);

            // Clean up downloaded thumbnail if it exists
            if ($thumbnailScore->thumbnail_file_path && Storage::disk('local')->exists($thumbnailScore->thumbnail_file_path)) {
                Storage::disk('local')->delete($thumbnailScore->thumbnail_file_path);
                Log::info('Cleaned up thumbnail file after job failure', [
                    'video_id' => $this->videoId,
                    'file_path' => $thumbnailScore->thumbnail_file_path
                ]);
            }
        } catch (\Exception $updateException) {
            Log::error('Failed to update ThumbnailScore status after permanent job failure', [
                'video_id' => $this->videoId,
                'original_error' => $exception->getMessage(),
                'update_error' => $updateException->getMessage()
            ]);
        }
    }
}
