<?php

namespace App\Jobs;

use App\Models\GeneratedImage;
use App\Services\ImageGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessComfyUIWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $promptId,
        public array $outputs = [],
        public ?string $imageId = null,
        public ?string $status = 'completed',
        public ?string $error = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ImageGenerationService $generationService): void
    {
        Log::info('ProcessComfyUIWebhookJob started', [
            'prompt_id' => $this->promptId,
            'image_id' => $this->imageId,
            'status' => $this->status,
            'outputs_provided' => !empty($this->outputs),
            'outputs_count' => count($this->outputs),
        ]);

        // Find the image by prompt_id or image_id
        $image = null;
        if ($this->imageId) {
            $image = GeneratedImage::find($this->imageId);
        }
        if (! $image && $this->promptId && $this->promptId !== 'PENDING') {
            $image = GeneratedImage::where('comfy_prompt_id', $this->promptId)->first();
        }

        if (! $image) {
            Log::warning('ProcessComfyUIWebhookJob: Image not found', [
                'prompt_id' => $this->promptId,
                'image_id' => $this->imageId,
            ]);

            return;
        }

        // Already complete, ignore
        if ($image->isComplete()) {
            Log::info('ProcessComfyUIWebhookJob: Image already complete', [
                'image_id' => $image->id,
            ]);

            return;
        }

        if ($this->status === 'failed') {
            $image->markAsFailed($this->error ?? 'ComfyUI reported failure');
            Log::info('Image marked as failed via webhook job', [
                'image_id' => $image->id,
                'error' => $this->error,
            ]);

            return;
        }

        // Handle completion
        try {
            $realPromptId = ($this->promptId && $this->promptId !== 'PENDING') ? $this->promptId : $image->comfy_prompt_id;
            $generationService->handleWebhookCompletion($realPromptId, $this->outputs);

            $freshImage = $image->fresh();
            Log::info('ProcessComfyUIWebhookJob completed', [
                'image_id' => $image->id,
                'status' => $freshImage->status,
                'result_image_count' => $freshImage->result_image_count,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessComfyUIWebhookJob failed', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't mark as failed - polling job will handle it
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessComfyUIWebhookJob permanently failed', [
            'prompt_id' => $this->promptId,
            'image_id' => $this->imageId,
            'error' => $exception->getMessage(),
        ]);
    }
}
