<?php

namespace App\Jobs;

use App\Models\Thumbnail;
use App\Services\ComfyUIService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PollComfyUIJob implements ShouldQueue
{
    use Queueable;

    protected $thumbnailId;
    
    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600; // 10 minutes
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 60; // Poll up to 60 times (10 minutes with 10-second intervals)

    /**
     * Create a new job instance.
     */
    public function __construct(int $thumbnailId)
    {
        $this->thumbnailId = $thumbnailId;
        
        Log::info("PollComfyUIJob instantiated", [
            'thumbnail_id' => $this->thumbnailId
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(ComfyUIService $comfyUIService): void
    {
        Log::info("=== PollComfyUIJob START ===", [
            'thumbnail_id' => $this->thumbnailId
        ]);
        
        try {
            $thumbnail = Thumbnail::findOrFail($this->thumbnailId);
            
            if (empty($thumbnail->comfy_prompt_id)) {
                Log::error("Thumbnail does not have comfy_prompt_id", [
                    'thumbnail_id' => $this->thumbnailId
                ]);
                $this->markThumbnailAsFailed($thumbnail, 'No ComfyUI prompt_id found');
                return;
            }
            
            $promptId = $thumbnail->comfy_prompt_id;
            $startTime = $thumbnail->created_at;
            
            Log::info("Starting to poll ComfyUI for thumbnail", [
                'thumbnail_id' => $this->thumbnailId,
                'comfy_prompt_id' => $promptId,
                'start_time' => $startTime?->toISOString()
            ]);
            
            // Poll for completion
            $maxAttempts = 60; // 10 minutes with 10-second intervals
            $attempt = 0;
            $timeoutSeconds = 180; // 3 minutes
            
            while ($attempt < $maxAttempts) {
                sleep(10); // Wait 10 seconds
                $attempt++;
                
                // Refresh thumbnail to get latest status
                $thumbnail->refresh();
                
                // Check if thumbnail was already processed or failed
                if ($thumbnail->status === 'completed' || $thumbnail->status === 'failed') {
                    Log::info("Thumbnail already processed, stopping poll", [
                        'thumbnail_id' => $this->thumbnailId,
                        'status' => $thumbnail->status
                    ]);
                    return;
                }
                
                // Check timeout: 3 minutes from thumbnail creation
                if ($startTime) {
                    $elapsedSeconds = now()->diffInSeconds($startTime);
                    
                    if ($elapsedSeconds > $timeoutSeconds) {
                        $errorMessage = "No response passed 3 minutes";
                        Log::error("ComfyUI polling timeout - 3 minutes exceeded", [
                            'thumbnail_id' => $this->thumbnailId,
                            'prompt_id' => $promptId,
                            'elapsed_seconds' => $elapsedSeconds,
                            'timeout_seconds' => $timeoutSeconds,
                            'created_at' => $startTime->toISOString()
                        ]);
                        
                        $this->markThumbnailAsFailed($thumbnail, $errorMessage);
                        throw new \Exception($errorMessage);
                    }
                }
                
                // Get history for this specific prompt_id
                $historyResponse = $comfyUIService->getHistoryForPrompt($promptId);
                
                if ($historyResponse) {
                    // Response might be keyed by prompt_id or direct object
                    // If keyed by prompt_id, extract it; otherwise use directly
                    $promptData = $historyResponse[$promptId] ?? $historyResponse;
                    
                    // Extract status_str from the nested status object
                    $statusStr = $promptData['status']['status_str'] ?? null;
                    $completed = $promptData['status']['completed'] ?? false;
                    
                    Log::info("ComfyUI thumbnail generation status", [
                        'thumbnail_id' => $this->thumbnailId,
                        'prompt_id' => $promptId,
                        'attempt' => $attempt,
                        'status_str' => $statusStr,
                        'completed' => $completed,
                        'has_outputs' => isset($promptData['outputs'])
                    ]);
                    
                    // Check if status_str exists and is not "success" - mark as failed
                    if ($statusStr !== null && $statusStr !== 'success') {
                        Log::error("ComfyUI prompt status is not success", [
                            'thumbnail_id' => $this->thumbnailId,
                            'prompt_id' => $promptId,
                            'status_str' => $statusStr,
                            'completed' => $completed
                        ]);
                        $this->markThumbnailAsFailed($thumbnail, "ComfyUI prompt status: {$statusStr}");
                        return;
                    }
                    
                    // Check if prompt is completed and successful (has outputs and status_str is success)
                    if ($statusStr === 'success' && $completed && isset($promptData['outputs'])) {
                        // Find the SaveImage node output
                        $outputs = $promptData['outputs'];
                        $imageFilename = null;
                        
                        Log::debug("ComfyUI outputs structure", [
                            'thumbnail_id' => $this->thumbnailId,
                            'outputs_keys' => array_keys($outputs),
                            'has_node_9' => isset($outputs['9'])
                        ]);
                        
                        // Look for node 9 (SaveImage) output
                        if (isset($outputs['9']['images'])) {
                            $images = $outputs['9']['images'];
                            Log::debug("ComfyUI node 9 images", [
                                'thumbnail_id' => $this->thumbnailId,
                                'images_count' => count($images),
                                'first_image' => $images[0] ?? null
                            ]);
                            
                            if (!empty($images) && isset($images[0]['filename'])) {
                                $imageFilename = $images[0]['filename'];
                                Log::info("Extracted image filename from ComfyUI", [
                                    'thumbnail_id' => $this->thumbnailId,
                                    'image_filename' => $imageFilename,
                                    'filename_type' => gettype($imageFilename),
                                    'filename_empty' => empty($imageFilename)
                                ]);
                            }
                        } else {
                            Log::warning("ComfyUI node 9 images not found", [
                                'thumbnail_id' => $this->thumbnailId,
                                'outputs_structure' => json_encode($outputs)
                            ]);
                        }
                        
                        if ($imageFilename && !empty(trim($imageFilename))) {
                            Log::info("Found image filename in ComfyUI output", [
                                'thumbnail_id' => $this->thumbnailId,
                                'image_filename' => $imageFilename
                            ]);
                            
                            // Download the image from ComfyUI
                            $imageContent = $comfyUIService->getImage($imageFilename);
                            
                            if ($imageContent) {
                                // Generate filename
                                $filename = 'thumbnail_' . $thumbnail->id . '_' . time() . '.jpg';
                                $path = 'thumbnails/' . $thumbnail->user_id . '/' . $thumbnail->id . '/' . $filename;
                                
                                // Store the image
                                Storage::disk('public')->put($path, $imageContent);
                                
                                // Update thumbnail with file location and status
                                $thumbnail->update([
                                    'file_location' => $path,
                                    'status' => 'completed',
                                    'processed_at' => now()
                                ]);
                                
                                Log::info("ComfyUI thumbnail saved successfully", [
                                    'thumbnail_id' => $this->thumbnailId,
                                    'path' => $path,
                                    'image_filename' => $imageFilename
                                ]);
                                
                                return; // Success!
                            } else {
                                Log::error("Failed to download ComfyUI image", [
                                    'thumbnail_id' => $this->thumbnailId,
                                    'image_filename' => $imageFilename
                                ]);
                            }
                        }
                    } else {
                        // Prompt data exists but not completed yet (still processing)
                        Log::debug("ComfyUI prompt still processing", [
                            'thumbnail_id' => $this->thumbnailId,
                            'prompt_id' => $promptId,
                            'status_str' => $statusStr,
                            'completed' => $completed,
                            'attempt' => $attempt
                        ]);
                    }
                } else {
                    Log::debug("ComfyUI prompt not found in history yet", [
                        'thumbnail_id' => $this->thumbnailId,
                        'prompt_id' => $promptId,
                        'attempt' => $attempt
                    ]);
                }
            }
            
            // Timeout reached
            Log::error("ComfyUI thumbnail generation timeout", [
                'thumbnail_id' => $this->thumbnailId,
                'prompt_id' => $promptId,
                'max_attempts' => $maxAttempts
            ]);
            
            $this->markThumbnailAsFailed($thumbnail, 'Polling timeout after ' . $maxAttempts . ' attempts');
            
        } catch (\Exception $e) {
            Log::error("PollComfyUIJob error", [
                'thumbnail_id' => $this->thumbnailId,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);
            
            try {
                $thumbnail = Thumbnail::find($this->thumbnailId);
                if ($thumbnail) {
                    $this->markThumbnailAsFailed($thumbnail, $e->getMessage());
                }
            } catch (\Exception $updateException) {
                Log::error("Failed to mark thumbnail as failed", [
                    'thumbnail_id' => $this->thumbnailId,
                    'error' => $updateException->getMessage()
                ]);
            }
        }
    }

    /**
     * Mark thumbnail as failed
     */
    private function markThumbnailAsFailed(Thumbnail $thumbnail, string $errorMessage): void
    {
        try {
            $thumbnail->update([
                'file_location' => null,
                'status' => 'failed',
                'error_message' => $errorMessage
            ]);
            
            Log::info("Updated thumbnail with failed status", [
                'thumbnail_id' => $this->thumbnailId,
                'status' => 'failed',
                'error_message' => $errorMessage
            ]);
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
        Log::error("PollComfyUIJob failed", [
            'thumbnail_id' => $this->thumbnailId,
            'exception_class' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString()
        ]);

        try {
            $thumbnail = Thumbnail::find($this->thumbnailId);
            if ($thumbnail) {
                $this->markThumbnailAsFailed($thumbnail, $exception->getMessage());
            }
        } catch (\Exception $e) {
            Log::error("Failed to mark thumbnail as failed in failed()", [
                'thumbnail_id' => $this->thumbnailId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
