<?php

namespace App\Jobs;

use App\Models\GeneratedImage;
use App\Models\Thumbnail;
use App\Services\ComfyUIService;
use App\Services\CreditService;
use App\Services\Flux2Service;
use App\Services\ReplicateThumbnailService;
use App\Services\ThumbnailHelper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateThumbnailsJob implements ShouldQueue
{
    use Queueable;

    protected $thumbnailId;

    protected ?string $serviceOverride;

    /**
     * The number of seconds the job can run before timing out.
     * ComfyUI image generation can take 5-15 minutes depending on server
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * Create a new job instance.
     *
     * @param  string|null  $serviceOverride  Optional service override (flux, flux2, openai, gemini)
     */
    public function __construct(int $thumbnailId, ?string $serviceOverride = null)
    {
        $this->thumbnailId = $thumbnailId;
        $this->serviceOverride = $serviceOverride;

        Log::info('GenerateThumbnailsJob instantiated', [
            'thumbnail_id' => $this->thumbnailId,
            'service_override' => $this->serviceOverride,
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(ThumbnailHelper $thumbnailHelper): void
    {
        Log::info('=== GenerateThumbnailsJob START ===', [
            'thumbnail_id' => $this->thumbnailId,
        ]);

        try {
            Log::info('GenerateThumbnailsJob started', [
                'thumbnail_id' => $this->thumbnailId,
                'job_class' => self::class,
                'timestamp' => now()->toISOString(),
            ]);
            // Find the thumbnail record
            Log::info('Looking up thumbnail record', ['thumbnail_id' => $this->thumbnailId]);
            $thumbnail = Thumbnail::findOrFail($this->thumbnailId);

            Log::info('Thumbnail record found', [
                'thumbnail_id' => $thumbnail->id,
                'description' => $thumbnail->description,
                'user_id' => $thumbnail->user_id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'current_file_location' => $thumbnail->file_location,
                'current_status' => $thumbnail->status,
            ]);

            // Check if thumbnail already has a status set (not pending or null)
            // Prevent reprocessing if status is already set to completed, failed, or processing
            if ($thumbnail->status && $thumbnail->status !== 'pending') {
                Log::info('Thumbnail already has status set, skipping processing', [
                    'thumbnail_id' => $thumbnail->id,
                    'current_status' => $thumbnail->status,
                    'message' => 'Thumbnail is already being processed or has been completed/failed',
                ]);

                return; // Exit early without processing
            }

            // Mark thumbnail as processing to prevent concurrent processing
            $thumbnail->status = 'processing';
            $thumbnail->save();

            Log::info('Thumbnail marked as processing', [
                'thumbnail_id' => $thumbnail->id,
                'status' => 'processing',
            ]);

            // Service selection: .env THUMBNAIL_SERVICE (or per-request override) decides the pipeline.
            // Within each pipeline, AI model / reference image is optional.
            $serviceUsed = null;
            $generatedThumbnails = [];
            $defaultService = $this->serviceOverride ?? config('services.thumbnail.default_service', 'openai');

            Log::info('Thumbnail service selection', [
                'thumbnail_id' => $thumbnail->id,
                'service' => $defaultService,
                'ai_model_id' => $thumbnail->ai_model_id,
                'has_reference_image' => optional($thumbnail->user)->hasDefaultReferenceImage() ?? false,
            ]);

            if ($defaultService === 'flux2') {
                // Flux2 async pipeline
                // - With reference image: identity-preserving generation
                // - Without reference image: text-only generation
                Log::info('=== THUMBNAIL SERVICE: FLUX2 ===', [
                    'thumbnail_id' => $thumbnail->id,
                    'service' => 'Flux2 async pipeline',
                    'ai_model_id' => $thumbnail->ai_model_id,
                    'description' => $thumbnail->description,
                ]);

                $this->generateWithFlux2($thumbnail);

                return; // Exit early — async completion will update thumbnail

            } elseif (in_array($defaultService, ['flux', 'comfyui'])) {
                // Flux1/ComfyUI sync pipeline
                // - With ai_model_id: uses trained LoRA for identity
                // - Without ai_model_id: text-only generation
                $serviceUsed = $thumbnail->ai_model_id
                    ? 'COMFYUI/FLUX1 (LoRA + AI Model)'
                    : 'COMFYUI/FLUX1 (Text Only)';

                Log::info('=== THUMBNAIL SERVICE: FLUX1/COMFYUI ===', [
                    'thumbnail_id' => $thumbnail->id,
                    'service' => $serviceUsed,
                    'ai_model_id' => $thumbnail->ai_model_id,
                    'description' => $thumbnail->description,
                ]);

                $generatedThumbnails = $this->generateWithComfyUISync($thumbnail, $thumbnailHelper);

            } else {
                $serviceUsed = strtoupper($defaultService).' (Default Service)';

                Log::info('=== THUMBNAIL SERVICE: '.strtoupper($defaultService).' ===', [
                    'thumbnail_id' => $thumbnail->id,
                    'service' => $defaultService,
                    'ai_model_id' => $thumbnail->ai_model_id,
                    'description' => $thumbnail->description,
                ]);

                // Use ThumbnailHelper for OpenAI/Gemini/Replicate generation
                $generatedThumbnails = $thumbnailHelper->generateThumbnailsWithFallback(
                    $thumbnail->description,
                    $thumbnail->user_id,
                    $thumbnail->id,
                    1
                );
            }

            Log::info('Thumbnail generation completed', [
                'thumbnail_id' => $this->thumbnailId,
                'generated_count' => count($generatedThumbnails),
                'generated_thumbnails' => $generatedThumbnails,
                'used_ai_model' => (bool) $thumbnail->ai_model_id,
            ]);

            // Check if generation was successful
            if (empty($generatedThumbnails)) {
                Log::error('Thumbnail generation failed - no thumbnails generated', [
                    'thumbnail_id' => $this->thumbnailId,
                    'ai_model_id' => $thumbnail->ai_model_id,
                ]);
                $this->markThumbnailAsFailed();

                return;
            }

            // Use the first generated thumbnail as the file location
            $fileLocation = $generatedThumbnails[0] ?? null;

            Log::info('Selected file location', [
                'thumbnail_id' => $this->thumbnailId,
                'file_location' => $fileLocation,
                'is_null' => is_null($fileLocation),
            ]);

            // Double-check that we have a valid file location
            if (empty($fileLocation)) {
                Log::error('Thumbnail generation failed - no valid file location', [
                    'thumbnail_id' => $this->thumbnailId,
                    'generated_thumbnails' => $generatedThumbnails,
                ]);
                $this->markThumbnailAsFailed();

                return;
            }

            // Update thumbnail with the file location and status
            Log::info('Updating thumbnail record with file location and status', [
                'thumbnail_id' => $this->thumbnailId,
                'file_location' => $fileLocation,
                'status' => 'completed',
            ]);

            $thumbnail->update([
                'file_location' => $fileLocation,
                'status' => 'completed',
            ]);

            $creditService = app(CreditService::class);
            $creditService->deductCreditsForOperation($thumbnail->user, CreditService::THUMBNAIL_GENERATION_OPERATION, 1);

            Log::info('=== THUMBNAIL GENERATION COMPLETED ===', [
                'thumbnail_id' => $this->thumbnailId,
                'service_used' => $serviceUsed,
                'file_location' => $fileLocation,
                'ai_model_id' => $thumbnail->ai_model_id,
                'updated_at' => $thumbnail->fresh()->updated_at,
            ]);

        } catch (\Exception $e) {
            Log::error('Thumbnail generation failed', [
                'thumbnail_id' => $this->thumbnailId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            // For failed generation, set status to failed and leave file_location as null
            $this->markThumbnailAsFailed();
        } catch (\Throwable $e) {
            // Catch any unexpected errors that weren't handled above
            Log::error('Unexpected error in GenerateThumbnailsJob', [
                'thumbnail_id' => $this->thumbnailId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            // Ensure thumbnail is marked as failed
            $this->markThumbnailAsFailed();
        }
    }

    /**
     * Generate thumbnail using Replicate with trained AI model
     *
     * @return array Array of generated thumbnail file paths
     */
    private function generateWithReplicate(Thumbnail $thumbnail, ThumbnailHelper $thumbnailHelper): array
    {
        try {
            Log::info('Starting Replicate thumbnail generation with trained model', [
                'thumbnail_id' => $thumbnail->id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'description' => $thumbnail->description,
            ]);

            // Use ReplicateThumbnailService for generation
            $replicateService = app(ReplicateThumbnailService::class);

            // Generate the image
            $result = $replicateService->generateThumbnailImage(
                $thumbnail->description,
                $thumbnail
            );

            $imageUrl = $result['image_url'];
            $prompt = $result['prompt'];

            Log::info('Replicate image generated successfully', [
                'thumbnail_id' => $thumbnail->id,
                'image_url' => $imageUrl,
                'prompt' => $prompt,
            ]);

            // Update the thumbnail with the prompt used
            if ($prompt) {
                $thumbnail->update(['prompt' => $prompt]);
            }

            // Download and store the image locally
            $localFilePath = $thumbnailHelper->downloadAndStoreImage(
                $imageUrl,
                $thumbnail->user_id,
                $thumbnail->id,
                1
            );

            Log::info('Replicate image downloaded and stored', [
                'thumbnail_id' => $thumbnail->id,
                'local_file_path' => $localFilePath,
            ]);

            return [$localFilePath];

        } catch (\Exception $e) {
            Log::error('Replicate thumbnail generation failed', [
                'thumbnail_id' => $thumbnail->id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return empty array to indicate failure
            return [];
        }
    }

    /**
     * Generate thumbnail using ComfyUI service synchronously
     * Waits for generation to complete and returns the image
     *
     * @return array Array of generated thumbnail file paths
     */
    private function generateWithComfyUISync(Thumbnail $thumbnail, ThumbnailHelper $thumbnailHelper): array
    {
        try {
            Log::info('Starting ComfyUI synchronous thumbnail generation', [
                'thumbnail_id' => $thumbnail->id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'description' => $thumbnail->description,
            ]);

            $comfyUIService = app(ComfyUIService::class);

            // Check if ComfyUI is enabled
            if (! $comfyUIService->isEnabled()) {
                Log::error('ComfyUI is not enabled', [
                    'thumbnail_id' => $thumbnail->id,
                ]);
                throw new \Exception('ComfyUI is not enabled');
            }

            // Check if the LoRA exists on ComfyUI server
            if ($thumbnail->ai_model_id) {
                $aiModel = \App\Models\AiModel::find($thumbnail->ai_model_id);
                if ($aiModel) {
                    $loraName = $comfyUIService->getLoraFilename($aiModel);
                    $loraExists = $comfyUIService->loraExists($loraName);

                    Log::info('Checking LoRA availability on ComfyUI', [
                        'thumbnail_id' => $thumbnail->id,
                        'lora_name' => $loraName,
                        'exists' => $loraExists,
                    ]);

                    if (! $loraExists) {
                        Log::warning('LoRA not found on ComfyUI server', [
                            'lora_name' => $loraName,
                            'ai_model_id' => $aiModel->id,
                            'hint' => "Upload the LoRA file to ComfyUI: models/loras/{$loraName}",
                        ]);
                        throw new \Exception("LoRA '{$loraName}' not found on ComfyUI server. Please upload it first.");
                    }
                }
            }

            // Generate the image synchronously
            $result = $comfyUIService->generateThumbnailSync(
                $thumbnail->description,
                $thumbnail
            );

            $imageUrl = $result['image_url'];
            $prompt = $result['prompt'];
            $filename = $result['filename'] ?? null;

            Log::info('ComfyUI image generated successfully', [
                'thumbnail_id' => $thumbnail->id,
                'image_url' => $imageUrl,
                'filename' => $filename,
            ]);

            // Update the thumbnail with the prompt used
            if ($prompt) {
                $thumbnail->update(['prompt' => $prompt]);
            }

            // Download the image from ComfyUI
            if ($filename) {
                // Get the image data directly from ComfyUI
                $imageData = $comfyUIService->getImage($filename);

                if ($imageData) {
                    // Store the image locally
                    $localFilePath = $this->storeComfyUIImage(
                        $imageData,
                        $thumbnail->user_id,
                        $thumbnail->id
                    );

                    Log::info('ComfyUI image stored locally', [
                        'thumbnail_id' => $thumbnail->id,
                        'local_file_path' => $localFilePath,
                    ]);

                    return [$localFilePath];
                }
            }

            // Fallback: download from URL
            $localFilePath = $thumbnailHelper->downloadAndStoreImage(
                $imageUrl,
                $thumbnail->user_id,
                $thumbnail->id,
                1
            );

            Log::info('ComfyUI image downloaded and stored', [
                'thumbnail_id' => $thumbnail->id,
                'local_file_path' => $localFilePath,
            ]);

            return [$localFilePath];

        } catch (\Exception $e) {
            Log::error('ComfyUI thumbnail generation failed', [
                'thumbnail_id' => $thumbnail->id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Store ComfyUI image data to local storage
     *
     * @param  string  $imageData  Binary image data
     * @return string Local file path
     */
    private function storeComfyUIImage(string $imageData, int $userId, int $thumbnailId): string
    {
        $folderPath = "thumbnails/{$userId}/{$thumbnailId}";

        // Ensure directory exists
        if (! Storage::disk('public')->exists($folderPath)) {
            Storage::disk('public')->makeDirectory($folderPath);
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $fileName = "{$thumbnailId}_comfyui_{$timestamp}.png";
        $filePath = "{$folderPath}/{$fileName}";

        Storage::disk('public')->put($filePath, $imageData);

        return $filePath;
    }

    /**
     * Generate thumbnail using ComfyUI service
     * Dispatches the workflow and polling job - completion is handled by PollComfyUIJob
     */
    private function generateWithComfyUI(Thumbnail $thumbnail): void
    {
        try {
            $comfyUIService = app(ComfyUIService::class);

            Log::info('Starting ComfyUI thumbnail generation', [
                'thumbnail_id' => $thumbnail->id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'description' => $thumbnail->description,
            ]);

            // Generate thumbnail using ComfyUI - this sends the workflow and stores prompt_id
            $response = $comfyUIService->generateThumbnailImage($thumbnail->description, $thumbnail);

            if (! $response || ! isset($response['prompt_id'])) {
                Log::error('Failed to start ComfyUI thumbnail generation', [
                    'thumbnail_id' => $thumbnail->id,
                    'ai_model_id' => $thumbnail->ai_model_id,
                    'response' => $response,
                ]);
                throw new \Exception('Failed to start ComfyUI thumbnail generation');
            }

            $promptId = $response['prompt_id'];
            $filenamePrefix = $response['filename_prefix'] ?? "{$thumbnail->user_id}_{$thumbnail->id}_".time();

            Log::info('ComfyUI thumbnail generation started, dispatching polling job', [
                'thumbnail_id' => $thumbnail->id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'prompt_id' => $promptId,
                'comfy_prompt_id' => $thumbnail->comfy_prompt_id,
                'filename_prefix' => $filenamePrefix,
            ]);

            // Dispatch polling job to check for completion
            // PollComfyUIJob will handle downloading the image and updating the thumbnail
            PollComfyUIJob::dispatch($thumbnail->id);

            Log::info('PollComfyUIJob dispatched', [
                'thumbnail_id' => $thumbnail->id,
                'comfy_prompt_id' => $promptId,
            ]);

            // Return empty array - the polling job will handle completion
            return;
        } catch (\Exception $e) {
            Log::error('ComfyUI thumbnail generation error', [
                'thumbnail_id' => $thumbnail->id,
                'ai_model_id' => $thumbnail->ai_model_id,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Mark thumbnail as failed with error handling
     */
    /**
     * Generate thumbnail using the Flux2 async pipeline.
     * Creates a GeneratedImage bridge record and dispatches GenerateFlux2ImageJob.
     * Completion is handled by PollFlux2ImageJob → ImageGenerationService.syncLinkedThumbnail()
     */
    private function generateWithFlux2(Thumbnail $thumbnail): void
    {
        try {
            $flux2Service = app(Flux2Service::class);

            Log::info('Starting Flux2 thumbnail generation', [
                'thumbnail_id' => $thumbnail->id,
                'description' => $thumbnail->description,
                'user_id' => $thumbnail->user_id,
            ]);

            // Store canvas.png as the base image (required by workflow)
            $canvasSource = resource_path('images/canvas.png');
            if (! file_exists($canvasSource)) {
                throw new \Exception('Canvas image not found at: '.$canvasSource);
            }

            $canvasPath = "thumbnails/{$thumbnail->user_id}/{$thumbnail->id}/canvas_".time().'.png';
            Storage::disk('public')->put($canvasPath, file_get_contents($canvasSource));

            // Text-only generation for thumbnails (no reference image)
            $prompt = $thumbnail->description;

            // Get workflow parameters from Flux2 config
            $quality = $flux2Service->getDefaultQuality();
            $megapixel = $flux2Service->getQualityMegapixel($quality);
            $steps = $flux2Service->getDefaultSteps();

            // Create a GeneratedImage record (same pattern as GenerateCopyThumbnailJob)
            $generatedImage = GeneratedImage::create([
                'user_id' => $thumbnail->user_id,
                'method' => GeneratedImage::METHOD_GENERATE,
                'quality' => $quality,
                'status' => GeneratedImage::STATUS_PENDING,
                'prompt' => $prompt,
                'base_image_path' => $canvasPath,
                'megapixel' => $megapixel,
                'steps' => $steps,
                'refine_enabled' => $flux2Service->getDefaultRefineEnabled(),
                'inpainting_enabled' => $flux2Service->getDefaultInpaintingEnabled(),
                'number_of_images' => 1,
            ]);

            $generatedImage->addLog('Created from thumbnail generation (text-only)', [
                'thumbnail_id' => $thumbnail->id,
            ]);

            // Link the GeneratedImage to the Thumbnail
            $thumbnail->generated_image_id = $generatedImage->id;
            $thumbnail->status = 'processing';
            $thumbnail->save();

            Log::info('Flux2 GeneratedImage created, dispatching job', [
                'thumbnail_id' => $thumbnail->id,
                'generated_image_id' => $generatedImage->id,
                'prompt_length' => strlen($prompt),
                'has_reference' => ! empty($referencePath),
            ]);

            // Dispatch the standard Flux2 generation job
            GenerateFlux2ImageJob::dispatch($generatedImage->id);

            Log::info('GenerateFlux2ImageJob dispatched for thumbnail', [
                'thumbnail_id' => $thumbnail->id,
                'generated_image_id' => $generatedImage->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Flux2 thumbnail generation failed', [
                'thumbnail_id' => $thumbnail->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->markThumbnailAsFailed();
            throw $e;
        }
    }

    /**
     * Mark thumbnail as failed with error handling
     */
    private function markThumbnailAsFailed(): void
    {
        try {
            $thumbnail = Thumbnail::find($this->thumbnailId);

            if ($thumbnail) {
                $thumbnail->update([
                    'file_location' => null,
                    'status' => 'failed',
                ]);

                Log::info('Updated thumbnail with failed status', [
                    'thumbnail_id' => $this->thumbnailId,
                    'status' => 'failed',
                ]);
            } else {
                Log::warning('Thumbnail not found when marking as failed', [
                    'thumbnail_id' => $this->thumbnailId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to mark thumbnail as failed', [
                'thumbnail_id' => $this->thumbnailId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build combined prompt using the same logic as OpenAIService
     */
    private function buildCombinedPrompt(Thumbnail $thumbnail): ?string
    {
        Log::info('buildCombinedPrompt started');
        // Use the thumbnail's combined prompt text
        $combinedPrompt = $thumbnail->getCombinedPromptText();

        if ($combinedPrompt) {
            Log::info('Using thumbnail combined prompt for Replicate', [
                'thumbnail_id' => $thumbnail->id,
                'prompt_length' => strlen($combinedPrompt),
            ]);

            return $combinedPrompt;
        }

        // Fallback: create a simple prompt with the visualizable scene
        $fallbackPrompt = ($thumbnail->prompt ?: $thumbnail->description);
        Log::info('Using fallback prompt for Replicate', [
            'thumbnail_id' => $thumbnail->id,
            'fallback_prompt' => $fallbackPrompt,
        ]);

        return $fallbackPrompt;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateThumbnailsJob failed', [
            'thumbnail_id' => $this->thumbnailId,
            'exception_class' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'error_trace' => $exception->getTraceAsString(),
            'timestamp' => now()->toISOString(),
        ]);

        // Mark thumbnail as failed
        $this->markThumbnailAsFailed();
    }
}
