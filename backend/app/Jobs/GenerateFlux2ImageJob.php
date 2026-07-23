<?php

namespace App\Jobs;

use App\Models\GeneratedImage;
use App\Services\Flux2Service;
use App\Services\ImageGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateFlux2ImageJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $generatedImageId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ImageGenerationService $generationService, Flux2Service $flux2Service): void
    {
        Log::info('GenerateFlux2ImageJob started', [
            'generated_image_id' => $this->generatedImageId,
            'webhook_enabled' => $flux2Service->isWebhookEnabled(),
        ]);

        $image = GeneratedImage::find($this->generatedImageId);

        if (! $image) {
            Log::error('GeneratedImage not found', [
                'generated_image_id' => $this->generatedImageId,
            ]);

            return;
        }

        if ($image->status === GeneratedImage::STATUS_COMPLETED) {
            Log::info('GeneratedImage already completed, skipping', [
                'generated_image_id' => $this->generatedImageId,
            ]);

            return;
        }

        // Mark as processing BEFORE starting generation work
        // This ensures the frontend sees "processing" status during the upload/workflow/submit phase
        if ($image->status !== GeneratedImage::STATUS_PROCESSING) {
            $image->status = GeneratedImage::STATUS_PROCESSING;
            $image->save();
            $image->addLog('Job started, marking as processing');
        }

        try {
            // Start generation
            $result = $generationService->generate($image);

            if (! isset($result['prompt_id'])) {
                throw new \Exception('No prompt_id returned from ComfyUI');
            }

            // Update with the prompt_id from ComfyUI
            $image->comfy_prompt_id = $result['prompt_id'];
            $image->save();
            $image->addLog('Generation submitted to ComfyUI', ['prompt_id' => $result['prompt_id']]);

            // Determine polling mode based on webhook status
            $webhookEnabled = $flux2Service->isWebhookEnabled();
            $useFixedPollInterval = ! $webhookEnabled; // Use fixed interval when webhooks are disabled

            // Calculate poll delay based on quality and steps
            $pollDelay = $generationService->calculatePollDelay($image);

            // Dispatch polling job
            // When webhooks are disabled: poll immediately with fixed interval (1 second)
            // When webhooks are enabled: poll after calculated delay as fallback
            $initialDelay = $useFixedPollInterval ? $flux2Service->getPollInterval() : $pollDelay;

            PollFlux2ImageJob::dispatch(
                $this->generatedImageId,
                1,
                $pollDelay, // Keep original pollDelay for reference
                $useFixedPollInterval
            )->delay(now()->addSeconds($initialDelay));

            Log::info('GenerateFlux2ImageJob completed, polling job dispatched', [
                'generated_image_id' => $this->generatedImageId,
                'prompt_id' => $result['prompt_id'],
                'webhook_enabled' => $webhookEnabled,
                'use_fixed_poll_interval' => $useFixedPollInterval,
                'initial_delay_seconds' => $initialDelay,
            ]);

        } catch (\Exception $e) {
            Log::error('GenerateFlux2ImageJob failed', [
                'generated_image_id' => $this->generatedImageId,
                'error' => $e->getMessage(),
            ]);

            $image->markAsFailed($e->getMessage());

            throw $e; // Re-throw to trigger retry if attempts remain
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateFlux2ImageJob permanently failed', [
            'generated_image_id' => $this->generatedImageId,
            'error' => $exception->getMessage(),
        ]);

        $image = GeneratedImage::find($this->generatedImageId);
        if ($image && $image->status !== GeneratedImage::STATUS_FAILED) {
            $image->markAsFailed('Job permanently failed: '.$exception->getMessage());
        }
    }
}
