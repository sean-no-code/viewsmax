<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\FileUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Jobs\PushModelToComfyUI;
use App\Jobs\PushReplicateModelToHuggingFace;

class PollReplicateTrainingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout for this job
    public $tries = 3; // Retry up to 3 times

    private int $aiModelId;
    private string $predictionId;
    private int $attempt;
    private ?string $zipPath;

    /**
     * Create a new job instance.
     */
    public function __construct(int $aiModelId, string $predictionId, int $attempt = 1, ?string $zipPath = null)
    {
        $this->aiModelId = $aiModelId;
        $this->predictionId = $predictionId;
        $this->attempt = $attempt;
        $this->zipPath = $zipPath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $aiModel = AiModel::find($this->aiModelId);
        
        if (!$aiModel) {
            Log::error('AI model not found for polling', [
                'ai_model_id' => $this->aiModelId,
                'prediction_id' => $this->predictionId
            ]);
            return;
        }

        $replicatesApiKey = config('services.replicates.api_key');
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $replicatesApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->get("https://api.replicate.com/v1/trainings/{$this->predictionId}");

            if (!$response->successful()) {
                Log::error('Failed to check training status', [
                    'ai_model_id' => $this->aiModelId,
                    'prediction_id' => $this->predictionId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'attempt' => $this->attempt
                ]);
                
                // If this is not the first attempt and we're still failing, mark as failed
                if ($this->attempt >= 3) {
                    $aiModel->update([
                        'status' => 'failed',
                        'error_message' => 'Failed to check training status after multiple attempts'
                    ]);
                    return;
                }
                
                // Retry this job with incremented attempt
                self::dispatch($this->aiModelId, $this->predictionId, $this->attempt + 1, $this->zipPath)
                    ->delay(now()->addSeconds(10));
                return;
            }

            $responseData = $response->json();
            $status = $responseData['status'] ?? 'unknown';

            Log::info('Training status check', [
                'ai_model_id' => $this->aiModelId,
                'prediction_id' => $this->predictionId,
                'status' => $status,
                'attempt' => $this->attempt
            ]);

            switch ($status) {
                case 'succeeded':
                    Log::info('Training completed successfully', [
                        'ai_model_id' => $this->aiModelId,
                        'prediction_id' => $this->predictionId
                    ]);
                    
                    // Set status to training_completed and dispatch push job
                    $aiModel->update([
                        'status' => 'training_completed',
                        'error_message' => null
                    ]);
                    
                    // Dispatch job based on AI_MODEL_THUMBNAIL_SERVICE config
                    $this->dispatchModelUploadJob();
                    
                    // Clean up zip file now that training is complete
                    $this->cleanupZipFile();
                    return;

                case 'failed':
                    Log::error('Training failed', [
                        'ai_model_id' => $this->aiModelId,
                        'prediction_id' => $this->predictionId,
                        'status' => $status,
                        'error' => $responseData['error'] ?? null
                    ]);
                    
                    $aiModel->update([
                        'status' => 'failed',
                        'error_message' => $responseData['error'] ?? 'Training failed'
                    ]);
                    
                    // Clean up zip file now that training has failed
                    $this->cleanupZipFile();
                    return;
                    
                case 'canceled':
                    Log::error('Training was canceled', [
                        'ai_model_id' => $this->aiModelId,
                        'prediction_id' => $this->predictionId,
                        'status' => $status,
                        'error' => $responseData['error'] ?? null
                    ]);
                    
                    $aiModel->update([
                        'status' => 'failed',
                        'error_message' => $responseData['error'] ?? "Training {$status}"
                    ]);
                    
                    // Clean up zip file now that training has failed
                    $this->cleanupZipFile();
                    return;

                case 'starting':
                case 'processing':
                    // Continue polling - dispatch another job in 3 seconds
                    self::dispatch($this->aiModelId, $this->predictionId, $this->attempt + 1, $this->zipPath)
                        ->delay(now()->addSeconds(5));
                    break;

                default:
                    Log::warning('Unknown training status', [
                        'ai_model_id' => $this->aiModelId,
                        'prediction_id' => $this->predictionId,
                        'status' => $status
                    ]);
                    
                    $this->cleanupZipFile();
                    break;
            }

        } catch (\Exception $e) {
            Log::error('Exception during polling', [
                'ai_model_id' => $this->aiModelId,
                'prediction_id' => $this->predictionId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempt
            ]);
            
            if ($this->attempt >= 3) {
                $aiModel->update([
                    'status' => 'failed',
                    'error_message' => 'Polling failed: ' . $e->getMessage()
                ]);
                return;
            }
            
            // Retry this job with incremented attempt
            self::dispatch($this->aiModelId, $this->predictionId, $this->attempt + 1, $this->zipPath)
                ->delay(now()->addSeconds(10));
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PollReplicateTrainingJob failed', [
            'ai_model_id' => $this->aiModelId,
            'prediction_id' => $this->predictionId,
            'error' => $exception->getMessage()
        ]);

        $aiModel = AiModel::find($this->aiModelId);
        if ($aiModel) {
            $aiModel->update([
                'status' => 'failed',
                'error_message' => 'Polling job failed: ' . $exception->getMessage()
            ]);
        }
    }

    /**
     * Dispatch the appropriate model upload job based on AI_MODEL_THUMBNAIL_SERVICE config
     * - 'comfyui' -> Upload to ComfyUI server via SCP
     * - 'replicate' or other -> Upload to HuggingFace (for Replicate to use)
     */
    private function dispatchModelUploadJob(): void
    {
        $thumbnailService = config('services.ai_model_thumbnail.default_service', 'replicate');
        
        Log::info('=== DISPATCHING MODEL UPLOAD JOB ===', [
            'ai_model_id' => $this->aiModelId,
            'thumbnail_service' => $thumbnailService
        ]);
        
        if ($thumbnailService === 'comfyui') {
            // Upload to ComfyUI server
            PushModelToComfyUI::dispatch($this->aiModelId);
            
            Log::info('Dispatched PushModelToComfyUI job', [
                'ai_model_id' => $this->aiModelId,
                'reason' => 'AI_MODEL_THUMBNAIL_SERVICE=comfyui'
            ]);
        } else {
            // Upload to HuggingFace (default, for Replicate to use)
            PushReplicateModelToHuggingFace::dispatch($this->aiModelId);
            
            Log::info('Dispatched PushReplicateModelToHuggingFace job', [
                'ai_model_id' => $this->aiModelId,
                'reason' => "AI_MODEL_THUMBNAIL_SERVICE={$thumbnailService}"
            ]);
        }
    }

    /**
     * Clean up the zip file if it exists
     */
    private function cleanupZipFile(): void
    {
        if ($this->zipPath) {
            try {
                // $deleted = FileUpload::deleteZipFile($this->zipPath);
                // if ($deleted) {
                //     Log::info('Cleaned up zip file after training completion', [
                //         'ai_model_id' => $this->aiModelId,
                //         'zip_path' => $this->zipPath
                //     ]);
                // } else {
                //     Log::warning('Failed to clean up zip file', [
                //         'ai_model_id' => $this->aiModelId,
                //         'zip_path' => $this->zipPath
                //     ]);
                // }
            } catch (\Exception $e) {
                Log::error('Exception during zip file cleanup', [
                    'ai_model_id' => $this->aiModelId,
                    'zip_path' => $this->zipPath,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
