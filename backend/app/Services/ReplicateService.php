<?php

namespace App\Services;

use App\Models\AiModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReplicateService
{
    private string $apiKey;
    private string $apiUrl;
    private string $namespace;
    private string $huggingFaceApiKey;
    
    public function __construct()
    {
        $this->apiKey = config('services.replicates.api_key');
        $this->apiUrl = config('services.replicates.api_url') ?: 'https://api.replicate.com/v1';
        $this->namespace = config('services.replicates.destination_namespace') ?: 'viewsmax';
        $this->huggingFaceApiKey = config('services.huggingface.api_key');
        Log::info('ReplicateService initialized', [
            'api_url' => $this->apiUrl,
            'hugging_face_api_key' => $this->huggingFaceApiKey,
            'namespace' => $this->namespace,
            'api_key_set' => !empty($this->apiKey)
        ]);
    }

    /**
     * Create a private model repository on Replicate
     *
     * @param string $modelName
     * @param string $description
     * @return array|null
     */
    public function createModelRepository(string $modelName, string $description = ''): ?array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Replicate API key not configured');
        }

        // Validate namespace is not empty
        if (empty($this->namespace)) {
            throw new \Exception('Replicate namespace is not configured. Please set REPLICATES_DESTINATION_NAMESPACE environment variable.');
        }

        $payload = [
            'owner' => $this->namespace,
            'name' => $modelName,
            'description' => $description ?: "AI model created by ViewsMax",
            'visibility' => 'private', // Create as private repository
            'hardware' => 'gpu-t4', // Specify hardware requirements
            'github_url' => null,
            'paper_url' => null,
            'license_url' => null,
            'cover_image_url' => null,
        ];

        Log::info('Creating Replicate model repository', $payload);

        try {
            Log::info('Sending Replicate API request', [
                'url' => $this->apiUrl . '/models',
                'payload' => $payload
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->apiUrl . '/models', $payload);

            if (!$response->successful()) {
                Log::error('Replicate model repository creation failed', [
                    'model_name' => $modelName,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                
                // If model already exists, that's okay
                if ($response->status() === 409) {
                    Log::info('Replicate model repository already exists', [
                        'model_name' => $modelName
                    ]);
                    return [
                        'name' => $this->namespace . '/' . $modelName,
                        'url' => 'https://replicate.com/' . $this->namespace . '/' . $modelName,
                        'status' => 'exists'
                    ];
                }
                
                throw new \Exception('Replicate model repository creation failed: ' . $response->body());
            }

            $responseData = $response->json();
            
            Log::info('Replicate model repository created successfully', [
                'model_name' => $modelName,
                'response' => $responseData
            ]);

            return [
                'name' => $this->namespace . '/' . $modelName,
                'url' => 'https://replicate.com/' . $this->namespace . '/' . $modelName,
                'status' => 'created',
                'data' => $responseData
            ];

        } catch (\Exception $e) {
            Log::error('Replicate service error', [
                'model_name' => $modelName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if a model repository exists on Replicate
     *
     * @param string $modelName
     * @return bool
     */
    public function modelRepositoryExists(string $modelName): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get($this->apiUrl . '/models/' . $modelName);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Error checking Replicate model repository existence', [
                'model_name' => $modelName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get model repository information from Replicate
     *
     * @param string $modelName
     * @return array|null
     */
    public function getModelRepository(string $modelName): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get($this->apiUrl . '/models/' . $modelName);

            if (!$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::warning('Error fetching Replicate model repository', [
                'model_name' => $modelName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate a unique model name for Replicate
     *
     * @param int $aiModelId
     * @param int $userId
     * @return string
     */
    public function generateModelName(int $aiModelId, int $userId): string
    {
        return 'viewsmax-ai-model-' . $userId . '-' . $aiModelId . '-' . time();
    }

    /**
     * Start training on a model repository
     *
     * @param string $modelName
     * @param array $trainingData
     * @return array|null
     */
    public function startTraining(string $modelName, array $trainingData): ?array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Replicate API key not configured');
        }

        $apiUrl = 'https://api.replicate.com/v1/models/replicate/fast-flux-trainer/versions/8b10794665aed907bb98a1a5324cd1d3a8bea0e9b31e65210967fb9c9e2e08ed/trainings';

        $payload = [
            'destination' => $modelName,
            'input' => $trainingData
        ];

        Log::info('Starting Replicate training', [
            'model_name' => $modelName,
            'api_url' => $apiUrl,
            'payload' => $payload
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($apiUrl, $payload);

            if (!$response->successful()) {
                Log::error('Replicate training start failed', [
                    'model_name' => $modelName,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                throw new \Exception('Replicate training start failed: ' . $response->body());
            }

            $responseData = $response->json();
            
            Log::info('Replicate training started successfully', [
                'model_name' => $modelName,
                'training_id' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? null
            ]);

            return $responseData;

        } catch (\Exception $e) {
            Log::error('Replicate training error', [
                'model_name' => $modelName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get model files from a completed Replicate training
     *
     * @param string $trainingId
     * @return array|null
     */
    public function getModelFiles(string $trainingId): ?array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Replicate API key not configured');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->get("https://api.replicate.com/v1/trainings/{$trainingId}");

            if (!$response->successful()) {
                Log::error('Failed to get Replicate training', [
                    'training_id' => $trainingId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return null;
            }

            $responseData = $response->json();
            
            // Check if training was successful
            if (($responseData['status'] ?? '') !== 'succeeded') {
                Log::error('Replicate training not successful', [
                    'training_id' => $trainingId,
                    'status' => $responseData['status'] ?? 'unknown'
                ]);
                return null;
            }

            // Extract model files from the output
            $output = $responseData['output'] ?? [];
            $modelFiles = [];

            if (is_array($output)) {
                foreach ($output as $item) {
                    if (is_string($item) && filter_var($item, FILTER_VALIDATE_URL)) {
                        $modelFiles[] = $item;
                    }
                }
            } elseif (is_string($output) && filter_var($output, FILTER_VALIDATE_URL)) {
                $modelFiles[] = $output;
            }

            Log::info('Retrieved model files from Replicate', [
                'training_id' => $trainingId,
                'file_count' => count($modelFiles),
                'files' => $modelFiles
            ]);

            return $modelFiles;

        } catch (\Exception $e) {
            Log::error('Replicate service error during file retrieval', [
                'training_id' => $trainingId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate a thumbnail using the specified AI model
     *
     * @param int $aiModelId
     * @param string $prompt
     * @param array $options
     * @return array|null
     */
    public function createThumbnail(int $aiModelId, string $prompt, array $options = []): ?array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Replicate API key not configured');
        }

        // Get the AI model to retrieve the huggingface_model_id
        $aiModel = AiModel::find($aiModelId);
        if (!$aiModel) {
            throw new \Exception("AI Model with ID {$aiModelId} not found");
        }

        if (!$aiModel->huggingface_model_id) {
            throw new \Exception("AI Model {$aiModelId} does not have a Hugging Face model ID");
        }

        // Use the specified flux-dev-lora model for thumbnail generation
        $modelVersion = 'black-forest-labs/flux-dev-lora'; 
        $payload = [
            // 'version' => $modelVersion,
            'input' => array_merge([
                "go_fast" => false,
                'prompt' => $prompt,
                'lora_weights' => 'huggingface.co/'.$aiModel->huggingface_model_id.'/lora.safetensors',
                'aspect_ratio' => '16:9',
                'lora_scale' => 1,
                "megapixels" => "1",
                "guidance" => 3,
                'output_format' => 'jpg',
                "output_quality" => 100,
                "prompt_strength" => 0.8,
                "extra_lora_scale" => 1,
                'hf_api_token' => $this->huggingFaceApiKey,
                // 'width' => $options['width'] ?? 1024,
                // 'height' => $options['height'] ?? 1024,
                'num_inference_steps' => $options['num_inference_steps'] ?? 40,
                'guidance_scale' => $options['guidance_scale'] ?? 3.5,
                'num_outputs' => $options['num_outputs'] ?? 1,
            ], $options)
        ];

        Log::info('Creating thumbnail via Replicate', $payload);
        $url = $this->apiUrl . '/models/'.$modelVersion.'/predictions';
        Log::info($url);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Replicate thumbnail creation failed', [
                    'ai_model_id' => $aiModelId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                throw new \Exception('Replicate thumbnail creation failed: ' . $response->body());
            }

            $responseData = $response->json();
            
            Log::info('Replicate thumbnail creation started successfully', [
                'ai_model_id' => $aiModelId,
                'prediction_id' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? null
            ]);

            return $responseData;

        } catch (\Exception $e) {
            Log::error('Replicate thumbnail creation error', [
                'ai_model_id' => $aiModelId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get thumbnail generation status and result
     *
     * @param string $predictionId
     * @return array|null
     */
    public function getThumbnailResult(string $predictionId): ?array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Replicate API key not configured');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->get($this->apiUrl . '/predictions/' . $predictionId);

            if (!$response->successful()) {
                Log::error('Failed to get Replicate thumbnail prediction', [
                    'prediction_id' => $predictionId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return null;
            }

            $responseData = $response->json();
            
            Log::info('Retrieved thumbnail prediction status', [
                'prediction_id' => $predictionId,
                'status' => $responseData['status'] ?? 'unknown',
                'output' => $responseData['output'] ?? null
            ]);

            return $responseData;

        } catch (\Exception $e) {
            Log::error('Replicate service error during thumbnail result retrieval', [
                'prediction_id' => $predictionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
