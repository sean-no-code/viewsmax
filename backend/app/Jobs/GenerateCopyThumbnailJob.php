<?php

namespace App\Jobs;

use App\Models\GeneratedImage;
use App\Models\Thumbnail;
use App\Services\Flux2Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GenerateCopyThumbnailJob implements ShouldQueue
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
        public int $copyThumbnailId
    ) {}

    /**
     * Execute the job.
     *
     * Creates a GeneratedImage record with method=head_swap and dispatches
     * GenerateFlux2ImageJob to use the flux_2_with_refine workflow.
     */
    public function handle(Flux2Service $flux2Service): void
    {
        Log::info('GenerateCopyThumbnailJob started', [
            'copy_thumbnail_id' => $this->copyThumbnailId,
        ]);

        $copyThumbnail = Thumbnail::copied()->find($this->copyThumbnailId);

        if (! $copyThumbnail) {
            Log::error('CopyThumbnail not found', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
            ]);

            return;
        }

        if ($copyThumbnail->status === Thumbnail::STATUS_COMPLETED) {
            Log::info('CopyThumbnail already completed, skipping', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
            ]);

            return;
        }

        try {
            $copyThumbnail->addLog('Job started - using Flux2 pipeline');

            // Determine reference image path (face to swap in)
            // Priority: 1) per-request reference image on the thumbnail, 2) user's default reference image
            $referencePath = null;
            $user = $copyThumbnail->user;

            // Check if a per-request reference image was uploaded
            if ($copyThumbnail->reference_image_path) {
                $referencePath = $copyThumbnail->reference_image_path;
                $copyThumbnail->addLog('Using per-request reference image', [
                    'reference_image_path' => $referencePath,
                ]);
            }
            // Fall back to user's default reference image
            elseif ($user && $user->hasDefaultReferenceImage()) {
                $referencePath = $user->default_reference_image_path;
                $copyThumbnail->addLog('Using user default reference image', [
                    'reference_image_path' => $referencePath,
                ]);
            }

            if (! $referencePath) {
                throw new \Exception('No reference image available. Please upload a reference image or set a default reference image.');
            }

            // Get workflow parameters
            $quality = $flux2Service->getDefaultQuality();
            $megapixel = $flux2Service->getQualityMegapixel($quality);
            $steps = $flux2Service->getDefaultSteps();

            // Create a GeneratedImage record (same as /api/images/generate does for head_swap)
            $generatedImage = GeneratedImage::create([
                'user_id' => $copyThumbnail->user_id,
                'method' => GeneratedImage::METHOD_HEAD_SWAP,
                'quality' => $quality,
                'status' => GeneratedImage::STATUS_PENDING,
                'prompt' => $copyThumbnail->transformed_prompt,
                'base_image_path' => $copyThumbnail->source_image_path,
                'reference_image_path' => $referencePath,
                'megapixel' => $megapixel,
                'steps' => $steps,
                'refine_enabled' => $flux2Service->getDefaultRefineEnabled(),
                'inpainting_enabled' => $flux2Service->getDefaultInpaintingEnabled(),
                'number_of_images' => 1,
            ]);

            $generatedImage->addLog('Created from copy thumbnail', [
                'copy_thumbnail_id' => $copyThumbnail->id,
            ]);

            // Link the GeneratedImage to CopyThumbnail
            $copyThumbnail->generated_image_id = $generatedImage->id;
            $copyThumbnail->status = Thumbnail::STATUS_PROCESSING;
            $copyThumbnail->save();

            $copyThumbnail->addLog('GeneratedImage created, dispatching Flux2 job', [
                'generated_image_id' => $generatedImage->id,
                'base_image' => $copyThumbnail->source_image_path,
                'reference_image' => $referencePath,
            ]);

            // Dispatch the standard Flux2 generation job
            GenerateFlux2ImageJob::dispatch($generatedImage->id);

            Log::info('GenerateCopyThumbnailJob completed, Flux2 job dispatched', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
                'generated_image_id' => $generatedImage->id,
            ]);

        } catch (\Exception $e) {
            Log::error('GenerateCopyThumbnailJob failed', [
                'copy_thumbnail_id' => $this->copyThumbnailId,
                'error' => $e->getMessage(),
            ]);

            $copyThumbnail->markAsFailed($e->getMessage());

            throw $e; // Re-throw to trigger retry if attempts remain
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateCopyThumbnailJob permanently failed', [
            'copy_thumbnail_id' => $this->copyThumbnailId,
            'error' => $exception->getMessage(),
        ]);

        $copyThumbnail = Thumbnail::copied()->find($this->copyThumbnailId);
        if ($copyThumbnail && $copyThumbnail->status !== Thumbnail::STATUS_FAILED) {
            $copyThumbnail->markAsFailed('Job permanently failed: '.$exception->getMessage());
        }
    }
}
