<?php

namespace App\Jobs;

use App\Models\TitleScore;
use App\Models\Video;
use App\Services\TitleAnalyzer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AnalyzeTitleJob implements ShouldQueue
{
    use Queueable, Batchable;

    public $videoId;
    public $tries = 3;
    public $timeout = 300; // 5 minutes to allow for API responses

    /**
     * Create a new job instance.
     */
    public function __construct($videoId)
    {
        $this->videoId = $videoId;

        Log::info('AnalyzeTitleJob constructor called', [
            'video_id' => $this->videoId,
            'video_id_type' => gettype($this->videoId)
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(TitleAnalyzer $titleAnalyzer): void
    {
        $startTime = microtime(true);

        Log::info('AnalyzeTitleJob started', [
            'video_id' => $this->videoId,
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries
        ]);

        try {
            // Find the video
            $video = Video::findOrFail($this->videoId);

            Log::info('Video found for title analysis', [
                'video_id' => $this->videoId,
                'title' => $video->title,
                'youtube_video_id' => $video->youtube_video_id
            ]);

            // Find or create the title score record
            $titleScore = TitleScore::firstOrNew(['video_id' => $this->videoId]);

            // Check if video has a title
            if (empty($video->title)) {
                $errorMessage = 'Video does not have a title to analyze';
                Log::warning($errorMessage, ['video_id' => $this->videoId]);

                $titleScore->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'completed_at' => now()
                ]);
                return;
            }

            // Update status to processing
            $titleScore->update([
                'status' => 'processing',
                'started_at' => now(),
                'error_message' => null
            ]);

            // Perform the analysis
            $scores = $titleAnalyzer->analyzeTitle($video->title);

            // Update with results
            $titleScore->update(array_merge($scores, [
                'status' => 'completed',
                'completed_at' => now(),
                'error_message' => null
            ]));

            $totalTime = microtime(true) - $startTime;

            Log::info('AnalyzeTitleJob completed successfully', [
                'video_id' => $this->videoId,
                'total_time_seconds' => round($totalTime, 2),
                'scores_count' => count($scores),
                'final_status' => 'completed'
            ]);

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;

            Log::error('AnalyzeTitleJob failed', [
                'video_id' => $this->videoId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'total_time_seconds' => round($totalTime, 2),
                'attempts' => $this->attempts(),
                'max_tries' => $this->tries
            ]);

            // Update title score with failure status
            try {
                $titleScore = TitleScore::firstOrNew(['video_id' => $this->videoId]);
                $titleScore->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now()
                ]);
            } catch (\Exception $updateException) {
                Log::error('Failed to update TitleScore status after job failure', [
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
        Log::error('AnalyzeTitleJob permanently failed', [
            'video_id' => $this->videoId,
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'final_attempts' => $this->attempts(),
            'max_tries' => $this->tries
        ]);

        // Ensure the title score is marked as failed
        try {
            $titleScore = TitleScore::firstOrNew(['video_id' => $this->videoId]);
            $titleScore->update([
                'status' => 'failed',
                'error_message' => 'Job permanently failed: ' . $exception->getMessage(),
                'completed_at' => now()
            ]);
        } catch (\Exception $updateException) {
            Log::error('Failed to update TitleScore status after permanent job failure', [
                'video_id' => $this->videoId,
                'original_error' => $exception->getMessage(),
                'update_error' => $updateException->getMessage()
            ]);
        }
    }
}
