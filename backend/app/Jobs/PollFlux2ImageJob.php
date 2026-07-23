<?php

namespace App\Jobs;

use App\Models\GeneratedImage;
use App\Services\Flux2Service;
use App\Services\ImageGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollFlux2ImageJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 60; // 60 attempts for polling

    /**
     * The maximum number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * Maximum number of poll attempts before giving up
     */
    private const MAX_POLL_ATTEMPTS = 60;

    /**
     * Minimum delay between polls (seconds)
     */
    private const MIN_POLL_DELAY = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $generatedImageId,
        public int $pollAttempt = 1,
        public int $initialDelay = 40,
        public bool $useFixedPollInterval = false
    ) {
        // When webhooks are disabled, use fixed poll interval instead of exponential decay
        $this->useFixedPollInterval = $useFixedPollInterval;
    }

    /**
     * Execute the job.
     */
    public function handle(ImageGenerationService $generationService, Flux2Service $flux2Service): void
    {
        Log::info('PollFlux2ImageJob started', [
            'generated_image_id' => $this->generatedImageId,
            'poll_attempt' => $this->pollAttempt,
            'webhook_enabled' => $flux2Service->isWebhookEnabled(),
            'use_fixed_interval' => $this->useFixedPollInterval,
        ]);

        $image = GeneratedImage::find($this->generatedImageId);

        if (! $image) {
            Log::error('GeneratedImage not found for polling', [
                'generated_image_id' => $this->generatedImageId,
            ]);

            return;
        }

        // Skip if already completed (e.g., by webhook)
        if ($image->isComplete()) {
            Log::info('GeneratedImage already complete, skipping poll', [
                'generated_image_id' => $this->generatedImageId,
                'status' => $image->status,
            ]);

            return;
        }

        if (! $image->comfy_prompt_id) {
            Log::error('No ComfyUI prompt_id found for polling', [
                'generated_image_id' => $this->generatedImageId,
            ]);
            $image->markAsFailed('No ComfyUI prompt_id for polling');

            return;
        }

        // Check for timeout
        if ($generationService->hasTimedOut($image)) {
            Log::warning('Image generation timed out', [
                'generated_image_id' => $this->generatedImageId,
            ]);
            $image->markAsFailed('Generation timed out after 6 minutes');

            return;
        }

        try {
            // Poll for completion
            $completed = $generationService->pollForCompletion($image);

            if ($completed) {
                $freshImage = $image->fresh();
                Log::info('PollFlux2ImageJob completed', [
                    'generated_image_id' => $this->generatedImageId,
                    'status' => $freshImage->status,
                    'result_image_count' => $freshImage->result_image_count,
                ]);

                return;
            }

            // Still processing, re-queue for later
            if ($this->pollAttempt >= self::MAX_POLL_ATTEMPTS) {
                $image->markAsFailed('Polling timeout - exceeded max attempts');

                return;
            }

            $nextDelay = $this->calculateNextDelay($flux2Service);

            Log::debug('GeneratedImage still processing, re-queuing poll', [
                'generated_image_id' => $this->generatedImageId,
                'poll_attempt' => $this->pollAttempt,
                'next_delay' => $nextDelay,
            ]);

            self::dispatch(
                $this->generatedImageId,
                $this->pollAttempt + 1,
                $this->initialDelay,
                $this->useFixedPollInterval
            )->delay(now()->addSeconds($nextDelay));

        } catch (\Exception $e) {
            Log::error('PollFlux2ImageJob error', [
                'generated_image_id' => $this->generatedImageId,
                'poll_attempt' => $this->pollAttempt,
                'error' => $e->getMessage(),
            ]);

            // Retry if we have attempts left
            if ($this->pollAttempt < self::MAX_POLL_ATTEMPTS) {
                self::dispatch(
                    $this->generatedImageId,
                    $this->pollAttempt + 1,
                    $this->initialDelay,
                    $this->useFixedPollInterval
                )->delay(now()->addSeconds($this->calculateNextDelay($flux2Service)));
            } else {
                $image->markAsFailed($e->getMessage());
            }
        }
    }

    /**
     * Calculate next poll delay
     * When webhooks are disabled: use fixed poll interval (default 1 second)
     * When webhooks are enabled: use exponential decay
     */
    private function calculateNextDelay(Flux2Service $flux2Service): int
    {
        if ($this->useFixedPollInterval) {
            // Use fixed poll interval when webhooks are disabled
            return $flux2Service->getPollInterval();
        }

        // Use exponential decay when webhooks are enabled (fallback mode)
        return max(self::MIN_POLL_DELAY, (int)($this->initialDelay / pow(2, $this->pollAttempt - 1)));
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PollFlux2ImageJob permanently failed', [
            'generated_image_id' => $this->generatedImageId,
            'error' => $exception->getMessage(),
        ]);

        $image = GeneratedImage::find($this->generatedImageId);
        if ($image && ! $image->isComplete()) {
            $image->markAsFailed('Polling job failed: '.$exception->getMessage());
        }
    }
}
