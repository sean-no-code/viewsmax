<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\Thumbnail;
use App\Services\Contracts\ThumbnailServiceInterface;
use App\Services\PromptService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating thumbnails using trained LoRA models via Replicate
 */
class ReplicateThumbnailService implements ThumbnailServiceInterface
{
    private string $apiKey;
    private string $apiUrl;
    private string $huggingFaceApiKey;
    private PromptService $promptService;

    public function __construct(PromptService $promptService)
    {
        $this->apiKey = config('services.replicates.api_key');
        $this->apiUrl = config('services.replicates.api_url') ?: 'https://api.replicate.com/v1';
        $this->huggingFaceApiKey = config('services.huggingface.api_key');
        $this->promptService = $promptService;
    }

    /**
     * Generate a thumbnail image using trained LoRA model via Replicate
     *
     * @param string $description The description/prompt for the image
     * @param Thumbnail|null $thumbnail The thumbnail model with AI model reference
     * @return array Array with 'image_url' and 'prompt' keys
     * @throws \Exception
     */
    public function generateThumbnailImage(string $description, ?Thumbnail $thumbnail = null): array
    {
        Log::info("ReplicateThumbnailService::generateThumbnailImage started", [
            'description' => $description,
            'thumbnail_id' => $thumbnail?->id,
            'ai_model_id' => $thumbnail?->ai_model_id
        ]);

        if (empty($this->apiKey)) {
            throw new \Exception('Replicate API key not configured');
        }

        // Get the AI model if thumbnail has one
        $aiModel = null;
        if ($thumbnail && $thumbnail->ai_model_id) {
            $aiModel = AiModel::find($thumbnail->ai_model_id);
            
            if (!$aiModel) {
                throw new \Exception("AI Model with ID {$thumbnail->ai_model_id} not found");
            }

            if ($aiModel->status !== 'completed') {
                throw new \Exception("AI Model {$aiModel->id} is not ready (status: {$aiModel->status})");
            }

            if (!$aiModel->huggingface_model_id) {
                throw new \Exception("AI Model {$aiModel->id} does not have a Hugging Face model ID");
            }

            Log::info("Using trained AI model for thumbnail generation", [
                'ai_model_id' => $aiModel->id,
                'huggingface_model_id' => $aiModel->huggingface_model_id,
                'trigger_word' => $aiModel->triggerWord()
            ]);
        }

        // Build the enhanced prompt
        $enhancedPrompt = $this->buildCombinedPrompt($thumbnail, $description);

        Log::info("Enhanced prompt for Replicate", [
            'original_description' => $description,
            'enhanced_prompt' => $enhancedPrompt
        ]);

        // Prepare the prediction payload
        $modelVersion = 'black-forest-labs/flux-dev-lora';
        
        $inputParams = [
            'prompt' => $enhancedPrompt,
            'aspect_ratio' => '16:9', // YouTube thumbnail ratio
            'output_format' => 'jpg',
            'output_quality' => 100,
            'num_outputs' => 1,
            'num_inference_steps' => 40,
            'guidance_scale' => 3.5,
            'go_fast' => false,
            'megapixels' => '1',
            'guidance' => 3,
            'prompt_strength' => 0.8,
        ];

        // Add LoRA weights if using a trained model
        if ($aiModel && $aiModel->huggingface_model_id) {
            $inputParams['lora_weights'] = 'huggingface.co/' . $aiModel->huggingface_model_id . '/lora.safetensors';
            $inputParams['lora_scale'] = 1;
            $inputParams['extra_lora_scale'] = 1;
            $inputParams['hf_api_token'] = $this->huggingFaceApiKey;
        }

        $payload = [
            'input' => $inputParams
        ];

        Log::info('Creating thumbnail via Replicate', [
            'model' => $modelVersion,
            'has_lora' => isset($inputParams['lora_weights']),
            'lora_weights' => $inputParams['lora_weights'] ?? null
        ]);

        try {
            // Start the prediction
            $url = $this->apiUrl . '/models/' . $modelVersion . '/predictions';
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Replicate prediction creation failed', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                throw new \Exception('Replicate prediction creation failed: ' . $response->body());
            }

            $predictionData = $response->json();
            $predictionId = $predictionData['id'] ?? null;

            if (!$predictionId) {
                throw new \Exception('No prediction ID returned from Replicate');
            }

            Log::info('Replicate prediction started', [
                'prediction_id' => $predictionId,
                'status' => $predictionData['status'] ?? 'unknown'
            ]);

            // Poll for completion (Replicate predictions are async)
            $imageUrl = $this->waitForPrediction($predictionId);

            if (!$imageUrl) {
                throw new \Exception('Failed to get image from Replicate prediction');
            }

            Log::info("Successfully generated image with Replicate", [
                'image_url' => $imageUrl,
                'prediction_id' => $predictionId
            ]);

            return [
                'image_url' => $imageUrl,
                'prompt' => $enhancedPrompt
            ];

        } catch (\Exception $e) {
            Log::error('ReplicateThumbnailService::generateThumbnailImage error', [
                'error_message' => $e->getMessage(),
                'description' => $description,
                'ai_model_id' => $thumbnail?->ai_model_id
            ]);
            throw $e;
        }
    }

    /**
     * Wait for a Replicate prediction to complete
     *
     * @param string $predictionId
     * @param int $maxAttempts
     * @param int $delaySeconds
     * @return string|null The image URL or null if failed
     */
    private function waitForPrediction(string $predictionId, int $maxAttempts = 60, int $delaySeconds = 3): ?string
    {
        Log::info("Waiting for Replicate prediction", [
            'prediction_id' => $predictionId,
            'max_attempts' => $maxAttempts,
            'delay_seconds' => $delaySeconds
        ]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])->timeout(30)->get($this->apiUrl . '/predictions/' . $predictionId);

                if (!$response->successful()) {
                    Log::warning("Failed to get prediction status", [
                        'prediction_id' => $predictionId,
                        'attempt' => $attempt,
                        'status_code' => $response->status()
                    ]);
                    sleep($delaySeconds);
                    continue;
                }

                $data = $response->json();
                $status = $data['status'] ?? 'unknown';

                Log::debug("Prediction status check", [
                    'prediction_id' => $predictionId,
                    'attempt' => $attempt,
                    'status' => $status
                ]);

                if ($status === 'succeeded') {
                    $output = $data['output'] ?? null;
                    
                    // Output can be an array or a single URL
                    if (is_array($output) && !empty($output)) {
                        return $output[0];
                    } elseif (is_string($output)) {
                        return $output;
                    }

                    Log::error("Prediction succeeded but no output found", [
                        'prediction_id' => $predictionId,
                        'output' => $output
                    ]);
                    return null;
                }

                if ($status === 'failed' || $status === 'canceled') {
                    Log::error("Prediction failed or canceled", [
                        'prediction_id' => $predictionId,
                        'status' => $status,
                        'error' => $data['error'] ?? 'unknown'
                    ]);
                    return null;
                }

                // Still processing, wait and try again
                sleep($delaySeconds);

            } catch (\Exception $e) {
                Log::warning("Exception while polling prediction", [
                    'prediction_id' => $predictionId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                sleep($delaySeconds);
            }
        }

        Log::error("Prediction timed out", [
            'prediction_id' => $predictionId,
            'max_attempts' => $maxAttempts
        ]);
        return null;
    }

    /**
     * Get the combined prompt from the thumbnail or create a fallback
     *
     * @param Thumbnail|null $thumbnail
     * @param string $description
     * @return string
     */
    private function buildCombinedPrompt(?Thumbnail $thumbnail, string $description): string
    {
        if ($thumbnail) {
            // Use the thumbnail's combined prompt text if available
            $combinedPrompt = $thumbnail->getCombinedPromptText();
            
            if ($combinedPrompt) {
                Log::info("Using thumbnail combined prompt for Replicate", [
                    'thumbnail_id' => $thumbnail->id,
                    'prompt_length' => strlen($combinedPrompt)
                ]);
                
                return $combinedPrompt;
            }

            // Use the prompt field if set
            if ($thumbnail->prompt) {
                return $thumbnail->prompt;
            }
        }
        
        // Fallback to the description
        return $description;
    }

    /**
     * Get a visualizable scene from description
     * Falls back to OpenAI for this task since it's text-based
     *
     * @param string $description
     * @return string
     * @throws \Exception
     */
    public function getVisualizableScene(string $description): string
    {
        // Use OpenAI for text processing (scene description generation)
        $openAiService = app(OpenAIService::class);
        return $openAiService->getVisualizableScene($description);
    }
}
