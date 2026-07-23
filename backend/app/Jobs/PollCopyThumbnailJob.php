<?php

namespace App\Jobs;

use App\Models\CopyThumbnail;
use App\Services\ComfyUIService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PollCopyThumbnailJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 120; // 120 attempts × 5 seconds = 10 minutes max polling

    /**
     * The maximum number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Maximum number of poll attempts before giving up
     */
    private const MAX_POLL_ATTEMPTS = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $copyThumbnailId,
        public int $pollAttempt = 1
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ComfyUIService $comfyUIService): void
    {
        Log::info('PollCopyThumbnailJob started', [
            'copy_thumbnail_id' => $this->copyThumbnailId,
            'poll_attempt' => $this->pollAttempt,
        ]);

        $copyThumbnail = CopyThumbnail::find($this->copyThumbnailId);

        if (! $copyThumbnail) {
            Log::error('CopyThumbnail not found for polling', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
            ]);

            return;
        }

        // Skip if already completed or failed
        if ($copyThumbnail->isComplete()) {
            Log::info('CopyThumbnail already complete, skipping poll', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
                'status' => $copyThumbnail->status,
            ]);

            return;
        }

        if (! $copyThumbnail->comfy_prompt_id) {
            Log::error('No ComfyUI prompt_id found for polling', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
            ]);
            $copyThumbnail->markAsFailed('No ComfyUI prompt_id for polling');

            return;
        }

        try {
            // Check queue status
            $queueStatus = $comfyUIService->getQueueStatus($copyThumbnail->comfy_prompt_id);

            if ($queueStatus['in_queue'] || $queueStatus['running']) {
                // Still processing, re-queue for later
                if ($this->pollAttempt >= self::MAX_POLL_ATTEMPTS) {
                    $copyThumbnail->markAsFailed('Polling timeout - exceeded max attempts');

                    return;
                }

                Log::debug('CopyThumbnail still processing, re-queuing poll', [
                    'copy_thumbnail_id' => $this->copyThumbnailId,
                    'poll_attempt' => $this->pollAttempt,
                    'in_queue' => $queueStatus['in_queue'],
                    'running' => $queueStatus['running'],
                ]);

                self::dispatch($this->copyThumbnailId, $this->pollAttempt + 1)
                    ->delay(now()->addSeconds(5));

                return;
            }

            // Check history for results
            $history = $comfyUIService->getHistoryForPrompt($copyThumbnail->comfy_prompt_id);

            if (! $history || ! isset($history[$copyThumbnail->comfy_prompt_id])) {
                // No history yet, queue another poll
                if ($this->pollAttempt >= self::MAX_POLL_ATTEMPTS) {
                    $copyThumbnail->markAsFailed('No history found after max attempts');

                    return;
                }

                self::dispatch($this->copyThumbnailId, $this->pollAttempt + 1)
                    ->delay(now()->addSeconds(5));

                return;
            }

            $promptData = $history[$copyThumbnail->comfy_prompt_id];

            // Check for errors
            if (isset($promptData['status']['status_str']) && $promptData['status']['status_str'] === 'error') {
                $errorMessages = $promptData['status']['messages'] ?? [];
                $copyThumbnail->markAsFailed('ComfyUI error: '.json_encode($errorMessages));

                return;
            }

            // Look for output images
            $outputs = $promptData['outputs'] ?? [];
            $imageFilename = null;

            // Check node 488 (SaveImage after FaceDetailer) for output - this is the final output
            $subfolder = '';
            if (isset($outputs['488']['images'][0]['filename'])) {
                $imageFilename = $outputs['488']['images'][0]['filename'];
                $subfolder = $outputs['488']['images'][0]['subfolder'] ?? '';
            }

            // Try other potential output nodes
            if (! $imageFilename) {
                foreach ($outputs as $nodeId => $nodeOutput) {
                    if (isset($nodeOutput['images'][0]['filename'])) {
                        $imageFilename = $nodeOutput['images'][0]['filename'];
                        $subfolder = $nodeOutput['images'][0]['subfolder'] ?? '';
                        break;
                    }
                }
            }

            if (! $imageFilename) {
                // No output yet, check if still just pending completion
                if ($this->pollAttempt < 30) {
                    self::dispatch($this->copyThumbnailId, $this->pollAttempt + 1)
                        ->delay(now()->addSeconds(5));

                    return;
                }

                $copyThumbnail->markAsFailed('No output image found in ComfyUI history');

                return;
            }

            $copyThumbnail->addLog('Output image found', [
                'filename' => $imageFilename,
                'subfolder' => $subfolder,
                'node_outputs_count' => count($outputs),
            ]);

            Log::info('Attempting to download image from ComfyUI', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
                'filename' => $imageFilename,
                'subfolder' => $subfolder,
                'expected_url_pattern' => "view?filename={$imageFilename}&subfolder={$subfolder}&type=output",
            ]);

            // Download the image with subfolder parameter
            $imageData = $comfyUIService->getImage($imageFilename, 3, $subfolder);

            if (! $imageData) {
                Log::error('getImage returned null', [
                    'copy_thumbnail_id' => $this->copyThumbnailId,
                    'filename' => $imageFilename,
                    'subfolder' => $subfolder,
                ]);

                $copyThumbnail->markAsFailed('Failed to download output image from ComfyUI');

                return;
            }

            // Store the image locally
            $localPath = $this->storeImage($imageData, $copyThumbnail);

            $copyThumbnail->markAsCompleted($localPath);

            Log::info('PollCopyThumbnailJob completed successfully', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
                'result_path' => $localPath,
            ]);

        } catch (\Exception $e) {
            Log::error('PollCopyThumbnailJob error', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
                'error' => $e->getMessage(),
            ]);

            // Retry if we have attempts left
            if ($this->pollAttempt < self::MAX_POLL_ATTEMPTS) {
                self::dispatch($this->copyThumbnailId, $this->pollAttempt + 1)
                    ->delay(now()->addSeconds(10));
            } else {
                $copyThumbnail->markAsFailed($e->getMessage());
            }
        }
    }

    /**
     * Store the downloaded image to local storage
     */
    private function storeImage(string $imageData, CopyThumbnail $copyThumbnail): string
    {
        $timestamp = time();
        $filename = "copy_thumbnail_{$copyThumbnail->id}_{$timestamp}.png";
        $path = "copy-thumbnails/{$copyThumbnail->user_id}/{$filename}";

        Storage::disk('public')->put($path, $imageData);

        Log::info('Stored copy thumbnail image', [
            'copy_thumbnail_id' => $copyThumbnail->id,
            'path' => $path,
            'size' => strlen($imageData),
        ]);

        return $path;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PollCopyThumbnailJob permanently failed', [
            'copy_thumbnail_id' => $this->copyThumbnailId,
            'error' => $exception->getMessage(),
        ]);

        $copyThumbnail = CopyThumbnail::find($this->copyThumbnailId);
        if ($copyThumbnail && ! $copyThumbnail->isComplete()) {
            $copyThumbnail->markAsFailed('Polling job failed: '.$exception->getMessage());
        }
    }
}
