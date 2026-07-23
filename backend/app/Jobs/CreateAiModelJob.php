<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\FileUpload;
use App\Services\HuggingFaceService;
use App\Services\ReplicateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class CreateAiModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes timeout
    public int $tries = 3;
    
    private string $huggingFaceAccessToken;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $aiModelId
    ) {
        $this->huggingFaceAccessToken = config('services.huggingface.api_key');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('CreateAiModelJob started', [
                'ai_model_id' => $this->aiModelId,
                'job_class' => self::class,
                'timestamp' => now()->toISOString()
            ]);

            // Find the AI model
            $aiModel = AiModel::findOrFail($this->aiModelId);
            
            Log::info('AI Model found for processing', [
                'ai_model_id' => $aiModel->id,
                'name' => $aiModel->name,
                'user_id' => $aiModel->user_id,
                'file_uploads_count' => $aiModel->fileUploads->count(),
                'current_status' => $aiModel->status
            ]);

            // Check if the model is already in a terminal state
            if (in_array($aiModel->status, ['processing', 'started', 'starting', 'completed'])) {
                Log::info('AI Model already in terminal state, skipping processing', [
                    'ai_model_id' => $aiModel->id,
                    'current_status' => $aiModel->status,
                    'reason' => 'Model is already being processed or completed'
                ]);
                return; // Exit early without processing
            }

            // Update status to processing
            $aiModel->update(['status' => 'processing']);

            // Process the AI model with Replicates API
            $this->processWithReplicatesAPI($aiModel);

            // Status is now managed by the polling method, no need to override it
            Log::info('CreateAiModelJob completed successfully', [
                'ai_model_id' => $this->aiModelId,
                'final_status' => $aiModel->fresh()->status
            ]);

        } catch (\Exception $e) {
            Log::error('CreateAiModelJob failed', [
                'ai_model_id' => $this->aiModelId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString()
            ]);

            // Update the AI model with error status
            if (isset($aiModel)) {
                $aiModel->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ]);
            }

            // Re-throw the exception to mark the job as failed
            throw $e;
        }
    }

    /**
     * Process the AI model with Replicates API
     */
    private function processWithReplicatesAPI(AiModel $aiModel): void
    {
        Log::info('Starting Replicates API processing', [
            'ai_model_id' => $aiModel->id,
            'file_count' => $aiModel->fileUploads->count()
        ]);

        // Get Replicates API configuration
        $replicatesApiKey = config('services.replicates.api_key');
        
        if (empty($replicatesApiKey)) {
            throw new \Exception('Replicates API key not configured');
        }

        // Get only training images (exclude thumbnails)
        $trainingImages = $aiModel->fileUploads()
            ->whereHas('fileCategory', function ($query) {
                $query->where('name', 'AI Upload Image');
            })
            ->get();

        if ($trainingImages->isEmpty()) {
            throw new \Exception('No training images found for AI model');
        }

        // Create Hugging Face model first
        $huggingFaceService = new HuggingFaceService();
        $modelName = $aiModel->modelName();
        
        Log::info('Creating Hugging Face model', [
            'ai_model_id' => $aiModel->id,
            'model_name' => $modelName
        ]);

        $hfModel = $huggingFaceService->createModel(
            $modelName,
            "AI model created for user {$aiModel->user_id} - {$aiModel->name}",
            ['ai-model', 'lora', 'custom']
        );

        if (!$hfModel) {
            throw new \Exception('Failed to create Hugging Face model');
        }

        Log::info('Hugging Face model created', [
            'ai_model_id' => $aiModel->id,
            'hf_model_id' => $hfModel['id'],
            'hf_model_url' => $hfModel['url']
        ]);

        // Create Replicate model repository
        $replicateService = new ReplicateService();
        
        Log::info('Creating Replicate model repository', [
            'ai_model_id' => $aiModel->id,
            'replicate_model_name' => $modelName
        ]);

        $replicateModel = $replicateService->createModelRepository(
            $modelName,
            "AI model created for user {$aiModel->user_id} - {$aiModel->name}"
        );

        if (!$replicateModel) {
            throw new \Exception('Failed to create Replicate model repository');
        }

        Log::info('Replicate model repository created', [
            'ai_model_id' => $aiModel->id,
            'replicate_model_name' => $replicateModel['name'],
            'replicate_model_url' => $replicateModel['url']
        ]);

        

        // Wait a moment for the repositories to be fully created
        sleep(3);
        // Verify the HF model is accessible before proceeding
        $modelVerified = $huggingFaceService->modelExists($modelName);
        if (!$modelVerified) {
            throw new \Exception('Hugging Face model verification failed - model may not be accessible');
        }

        Log::info('HuggingFace Model Created');

        $zipPath = FileUpload::createZipFromUploads($trainingImages, 'ai_model_' . $aiModel->id . '_' . time() . '.zip');
        
        if (!$zipPath) {
            throw new \Exception('Failed to create zip file from training images');
        }

        try {
            // Get the public URL for the zip file
            $zipUrl = FileUpload::getZipUrl($zipPath);
            
            Log::info('Created zip file for Replicates API', [
                'ai_model_id' => $aiModel->id,
                'zip_path' => $zipPath,
                'zip_url' => $zipUrl,
                'training_images_count' => $trainingImages->count()
            ]);

            Log::info('Sending data to Replicates API', [
                'destination' => $hfModel['id'], // Use the Hugging Face model ID
                'input' => [
                    'input_images' => $zipUrl,
                    'lora_type' => 'subject'
                ]
            ]);

            // Prepare the request payload according to the specified format
            $payload = [
                'destination' => $replicateModel['name'], // Use the Replicate model name
                'input' => [
                    'input_images' => $zipUrl,
                    'trigger_word' => $aiModel->triggerWord(),
                    'lora_type' => 'subject',
                    'hf_token' => $this->huggingFaceAccessToken,
                    'hf_repo_id' => config('services.huggingface.namespace') . '/' . $aiModel->modelName(),
                    'steps' => 2000
                ]
            ];

            // $apiUrl = 'https://api.replicate.com/v1/models/replicate/fast-flux-trainer/versions/8b10794665aed907bb98a1a5324cd1d3a8bea0e9b31e65210967fb9c9e2e08ed/trainings';
            $apiUrl = 'https://api.replicate.com/v1/models/';
            $apiUrl .= 'ostris/flux-dev-lora-trainer/versions/26dce37af90b9d997eeb970d92e47de3064d46c300504ae376c75bef6a9022d2/trainings';

            Log::info('Sending request to Replicates API', [
                'ai_model_id' => $aiModel->id,
                'api_url' => $apiUrl,
                'zip_url' => $zipUrl,
                'destination' => $replicateModel['name'],
                'hf_token_length' => strlen($this->huggingFaceAccessToken),
                'hf_token_prefix' => substr($this->huggingFaceAccessToken, 0, 8) . '...',
                'payload' => $payload
            ]);

            // Make the API request
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $replicatesApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($apiUrl, $payload);

            if (!$response->successful()) {
                Log::error('Replicates API request failed', [
                    'ai_model_id' => $aiModel->id,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                
                // If it's a 404 error, try without hf_token (in case the repo is public)
                if ($response->status() === 404) {
                    Log::info('Trying Replicate API request without hf_token', [
                        'ai_model_id' => $aiModel->id,
                        'destination' => $replicateModel['name']
                    ]);
                    
                    $payloadWithoutToken = $payload;
                    unset($payloadWithoutToken['input']['hf_token']);
                    
                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $replicatesApiKey,
                        'Content-Type' => 'application/json',
                    ])->timeout(120)->post($apiUrl, $payloadWithoutToken);
                    
                    if (!$response->successful()) {
                        Log::error('Replicates API request failed even without hf_token', [
                            'ai_model_id' => $aiModel->id,
                            'status_code' => $response->status(),
                            'response_body' => $response->body()
                        ]);
                        throw new \Exception('Replicates API request failed: ' . $response->body());
                    }
                } else {
                    throw new \Exception('Replicates API request failed: ' . $response->body());
                }
            }

            $responseData = $response->json();
            
            Log::info('Replicates API response received', [
                'ai_model_id' => $aiModel->id,
                'training_id' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? null,
                'hf_model_id' => $hfModel['id'],
                'replicate_model_name' => $replicateModel['name']
            ]);

            // Store the training ID and model references for future reference
            $aiModel->update([
                'replicates_prediction_id' => $responseData['id'] ?? null,
                'huggingface_model_id' => $hfModel['id'],
                'huggingface_model_url' => $hfModel['url'],
                'replicate_model_name' => $replicateModel['name'],
                'replicate_model_url' => $replicateModel['url'],
                'status' => 'processing' // Set to processing, not completed
            ]);

            // Start polling for completion
            PollReplicateTrainingJob::dispatch($aiModel->id, $responseData['id'] ?? null, 1, $zipPath)
                ->delay(now()->addSeconds(3)); // Start polling after 3 seconds

        } catch (\Exception $e) {
            Log::error('CreateAiModelJob failed', [
                'ai_model_id' => $aiModel->id,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);
            
            // Update AI model status to failed
            $aiModel->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            
            throw $e; // Re-throw to mark the job as failed
        }
    }
}