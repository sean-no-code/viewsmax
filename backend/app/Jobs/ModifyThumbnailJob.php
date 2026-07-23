<?php

namespace App\Jobs;

use App\Models\GeneratedImage;
use App\Models\Thumbnail;
use App\Services\CreditService;
use App\Services\Flux2Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ModifyThumbnailJob implements ShouldQueue
{
    use Queueable;

    protected $thumbnailId;
    
    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600; // 10 minutes (Flux2 async)
    
    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(int $thumbnailId)
    {
        $this->thumbnailId = $thumbnailId;
    }

    /**
     * Execute the job.
     * 
     * Uses the Flux2 async pipeline: creates a GeneratedImage bridge record
     * with the parent thumbnail's image as base, and dispatches GenerateFlux2ImageJob.
     * Completion is handled by PollFlux2ImageJob → ImageGenerationService.syncLinkedThumbnail()
     */
    public function handle(): void
    {
        try {
            Log::info("ModifyThumbnailJob started (Flux2 pipeline)", [
                'thumbnail_id' => $this->thumbnailId,
                'timestamp' => now()->toISOString()
            ]);

            $thumbnail = Thumbnail::findOrFail($this->thumbnailId);
            $flux2Service = app(Flux2Service::class);
            $user = $thumbnail->user;
            
            Log::info("Thumbnail record found", [
                'thumbnail_id' => $thumbnail->id,
                'parent_id' => $thumbnail->parent_id,
                'prompt' => $thumbnail->prompt,
                'user_id' => $thumbnail->user_id
            ]);

            // Validate parent thumbnail exists with an image
            if (!$thumbnail->parent_id) {
                Log::error("Thumbnail has no parent_id", ['thumbnail_id' => $thumbnail->id]);
                $this->markThumbnailAsFailed("Thumbnail has no parent thumbnail");
                return;
            }

            $parentThumbnail = Thumbnail::find($thumbnail->parent_id);
            
            if (!$parentThumbnail) {
                $this->markThumbnailAsFailed("Parent thumbnail not found");
                return;
            }

            if (!$parentThumbnail->file_location) {
                $this->markThumbnailAsFailed("Parent thumbnail has no image file");
                return;
            }

            // Get the raw storage path from parent (bypass URL accessor)
            $rawFileLocation = $parentThumbnail->getAttributes()['file_location'] ?? null;
            
            if (!$rawFileLocation) {
                $fileLocation = $parentThumbnail->file_location;
                if (filter_var($fileLocation, FILTER_VALIDATE_URL)) {
                    $parsedUrl = parse_url($fileLocation);
                    $path = $parsedUrl['path'] ?? '';
                    $rawFileLocation = ltrim($path, '/');
                } else {
                    $rawFileLocation = $fileLocation;
                }
            }
            
            if (strpos($rawFileLocation, '/') === 0 && strpos($rawFileLocation, '/thumbnails/') === 0) {
                $rawFileLocation = ltrim($rawFileLocation, '/');
            }

            // Verify the file exists
            if (!Storage::disk('public')->exists($rawFileLocation)) {
                $this->markThumbnailAsFailed("Parent thumbnail image file not found in storage: {$rawFileLocation}");
                return;
            }

            $modificationPrompt = $thumbnail->prompt ?? $thumbnail->description;

            Log::info("Modifying thumbnail with Flux2 pipeline", [
                'thumbnail_id' => $thumbnail->id,
                'parent_thumbnail_id' => $parentThumbnail->id,
                'modification_prompt' => $modificationPrompt,
                'base_image_path' => $rawFileLocation,
            ]);

            // Use user's default reference image if available
            $referencePath = null;
            if ($user && $user->hasDefaultReferenceImage()) {
                $referencePath = $user->default_reference_image_path;
                Log::info("Using user's default reference image for modification", [
                    'thumbnail_id' => $thumbnail->id,
                    'reference_image_path' => $referencePath,
                ]);
            }

            // Get workflow parameters from Flux2 config
            $quality = $flux2Service->getDefaultQuality();
            $megapixel = $flux2Service->getQualityMegapixel($quality);
            $steps = $flux2Service->getDefaultSteps();

            // Create a GeneratedImage record with parent's image as base
            $generatedImage = GeneratedImage::create([
                'user_id' => $thumbnail->user_id,
                'method' => GeneratedImage::METHOD_GENERATE,
                'quality' => $quality,
                'status' => GeneratedImage::STATUS_PENDING,
                'prompt' => $modificationPrompt,
                'base_image_path' => $rawFileLocation,
                'reference_image_path' => $referencePath,
                'megapixel' => $megapixel,
                'steps' => $steps,
                'refine_enabled' => $flux2Service->getDefaultRefineEnabled(),
                'inpainting_enabled' => $flux2Service->getDefaultInpaintingEnabled(),
                'number_of_images' => 1,
            ]);

            $generatedImage->addLog('Created from thumbnail modification', [
                'thumbnail_id' => $thumbnail->id,
                'parent_thumbnail_id' => $parentThumbnail->id,
                'has_reference_image' => !empty($referencePath),
            ]);

            // Link the GeneratedImage to the Thumbnail
            $thumbnail->generated_image_id = $generatedImage->id;
            $thumbnail->status = 'processing';
            $thumbnail->save();

            // Dispatch the standard Flux2 generation job
            GenerateFlux2ImageJob::dispatch($generatedImage->id);

            Log::info("ModifyThumbnailJob dispatched Flux2 job", [
                'thumbnail_id' => $thumbnail->id,
                'generated_image_id' => $generatedImage->id,
                'parent_thumbnail_id' => $parentThumbnail->id,
            ]);

        } catch (\Exception $e) {
            Log::error("ModifyThumbnailJob failed", [
                'thumbnail_id' => $this->thumbnailId,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);
            
            $this->markThumbnailAsFailed($e->getMessage());
        } catch (\Throwable $e) {
            Log::error("Unexpected error in ModifyThumbnailJob", [
                'thumbnail_id' => $this->thumbnailId,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);
            
            $this->markThumbnailAsFailed($e->getMessage());
        }
    }

    /**
     * Mark thumbnail as failed with error handling
     */
    private function markThumbnailAsFailed(string $errorMessage = 'Unknown error'): void
    {
        try {
            $thumbnail = Thumbnail::find($this->thumbnailId);
            if ($thumbnail) {
                $thumbnail->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage
                ]);
                
                Log::info("Thumbnail marked as failed", [
                    'thumbnail_id' => $this->thumbnailId,
                    'error_message' => $errorMessage
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to mark thumbnail as failed", [
                'thumbnail_id' => $this->thumbnailId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ModifyThumbnailJob permanently failed', [
            'thumbnail_id' => $this->thumbnailId,
            'error' => $exception->getMessage(),
        ]);

        $this->markThumbnailAsFailed('Job permanently failed: ' . $exception->getMessage());
    }
}
