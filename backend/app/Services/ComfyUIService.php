<?php

namespace App\Services;

use App\Models\AiModel;
use App\Services\Contracts\ThumbnailServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ComfyUIService implements ThumbnailServiceInterface
{
    private string $serverUrl;

    private bool $enabled;

    private string $loraPath;

    private int $defaultSteps;

    private float $defaultCfg;

    private int $defaultWidth;

    private int $defaultHeight;

    private PromptService $promptService;

    public function __construct(PromptService $promptService)
    {
        $this->serverUrl = (string) config('services.comfyui.server_url');
        $this->enabled = (bool) config('services.comfyui.enabled', false);
        $this->loraPath = (string) config('services.comfyui.lora_path', 'models/loras');
        $this->defaultSteps = (int) config('services.comfyui.default_steps', 30);
        $this->defaultCfg = (float) config('services.comfyui.default_cfg', 3.5);
        $this->defaultWidth = (int) config('services.comfyui.default_width', 1280);
        $this->defaultHeight = (int) config('services.comfyui.default_height', 720);
        $this->promptService = $promptService;

        // Validate server URL
        if (!empty($this->serverUrl) && !filter_var($this->serverUrl, FILTER_VALIDATE_URL) && !preg_match('/^https?:\/\//', $this->serverUrl)) {
            Log::warning('ComfyUI server URL may be invalid', [
                'server_url' => $this->serverUrl,
            ]);
        }
    }

    /**
     * Check if ComfyUI is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->serverUrl);
    }

    /**
     * Get the server URL
     */
    public function getServerUrl(): string
    {
        return $this->serverUrl;
    }

    /**
     * Get the LoRA filename for an AI model
     * This should match the filename uploaded to ComfyUI server
     *
     * Format: {model_name}-{model_id}.safetensors
     * Example: example-8.safetensors, john-12.safetensors
     */
    public function getLoraFilename(\App\Models\AiModel $aiModel): string
    {
        // Use simple format: {name}-{id}.safetensors
        // Example: example-8.safetensors
        $safeName = preg_replace('/[^a-zA-Z0-9-_]/', '-', strtolower($aiModel->name));

        return $safeName . '-' . $aiModel->id . '.safetensors';
    }

    /**
     * Get the expected LoRA path for an AI model
     * Useful for displaying where to upload the file
     */
    public function getLoraUploadPath(\App\Models\AiModel $aiModel): string
    {
        $filename = $this->getLoraFilename($aiModel);

        return "/home/ubuntu/ComfyUI/models/loras/{$filename}";
    }

    /**
     * Get the combined prompt from the thumbnail or create a fallback
     */
    private function buildCombinedPrompt(?\App\Models\Thumbnail $thumbnail, string $visualizableScene): ?string
    {
        Log::info('ComfyUIService::buildCombinedPrompt started', [
            'thumbnail_id' => $thumbnail->id,
        ]);
        if ($thumbnail) {
            // Use the thumbnail's combined prompt text
            $combinedPrompt = $thumbnail->getCombinedPromptText();

            if ($combinedPrompt) {
                Log::info('Using thumbnail combined prompt', [
                    'thumbnail_id' => $thumbnail->id,
                    'prompt_length' => strlen($combinedPrompt),
                ]);

                return $combinedPrompt;
            }
        }

        // Fallback: create a simple prompt with the visualizable scene
        $fallbackPrompt = $visualizableScene;
        Log::info('Using fallback prompt', [
            'fallback_prompt' => $fallbackPrompt,
        ]);

        return $fallbackPrompt;
    }

    /**
     * Split prompts into positive and negative
     *
     * @return array ['positive' => string, 'negative' => string]
     */
    private function splitPrompts(\App\Models\Thumbnail $thumbnail): array
    {
        Log::info('ComfyUIService::splitPrompts started', [
            'thumbnail_id' => $thumbnail->id,
        ]);

        $prompts = $thumbnail->getPromptsUsed();

        $positiveText = '';
        $negativeText = '';

        // Add prompt with prefix
        if ($thumbnail->prompt) {
            $positiveText .= $thumbnail->prompt . ".\n\n";
        }

        // Add style prompt (main prompt)
        if (isset($prompts['style'])) {
            $positiveText .= $prompts['style']['text'] . "\n\n";
        }

        // Add general prompt
        if (isset($prompts['general'])) {
            $positiveText .= $prompts['general']['text'] . "\n\n";
        }

        // Add ComfyUI-specific quality enhancers for sharp, clear images
        // These are essential for Flux model to produce high-quality, non-blurry output
        $qualityEnhancers = 'sharp focus, highly detailed, professional photography, high resolution, 8k, HDR, well-lit, clear facial features, crisp details';
        $positiveText .= $qualityEnhancers . "\n\n";

        // Add negative prompt
        if (isset($prompts['negative'])) {
            $negativeText = $prompts['negative']['text'];
        }

        // Add ComfyUI-specific negative prompts for better quality
        $qualityNegatives = 'blurry, out of focus, low quality, pixelated, grainy, noisy, soft focus, motion blur, bad anatomy, wrong gender, gender swap';
        if (!empty($negativeText)) {
            $negativeText .= ', ' . $qualityNegatives;
        } else {
            $negativeText = $qualityNegatives;
        }

        return [
            'positive' => trim($positiveText),
            'negative' => trim($negativeText),
        ];
    }

    /**
     * Load workflow template from JSON file
     *
     * @throws \Exception
     */
    private function loadWorkflowTemplate(): array
    {
        $templatePath = app_path('Prompts/flux1-dev-q8_0.json');

        if (!file_exists($templatePath)) {
            throw new \Exception("ComfyUI workflow template not found at: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        $workflow = json_decode($template, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse ComfyUI workflow template: ' . json_last_error_msg());
        }

        return $workflow;
    }

    /**
     * Generate a thumbnail image using ComfyUI
     *
     * @param  string  $description  The description to base thumbnail on
     * @param  \App\Models\Thumbnail|null  $thumbnail  The thumbnail model with prompt references
     * @return array Array with 'image_url' and 'prompt' keys
     *
     * @throws \Exception
     */
    public function generateThumbnailImage(string $description, ?\App\Models\Thumbnail $thumbnail = null): array
    {
        Log::info('ComfyUIService::generateThumbnailImage started', [
            'description' => $description,
            'description_length' => strlen($description),
            'thumbnail_id' => $thumbnail?->id,
            'server_url' => $this->serverUrl,
        ]);

        if (empty($this->serverUrl)) {
            Log::error('ComfyUI server URL not configured');
            throw new \Exception('ComfyUI server URL not configured');
        }

        if (!$thumbnail) {
            throw new \Exception('Thumbnail model is required for ComfyUI service');
        }

        // Split prompts into positive and negative
        $prompts = $this->splitPrompts($thumbnail);

        Log::info('Prompts split for ComfyUI', [
            'thumbnail_id' => $thumbnail->id,
            'positive_length' => strlen($prompts['positive']),
            'negative_length' => strlen($prompts['negative']),
        ]);

        // Load workflow template
        $workflow = $this->loadWorkflowTemplate();

        // Determine if workflow has 'prompt' wrapper or is flat structure
        $workflowNodes = isset($workflow['prompt']) ? $workflow['prompt'] : $workflow;
        $hasPromptWrapper = isset($workflow['prompt']);

        // Get AI model info for LoRA and trigger word
        $loraName = null;
        $triggerWord = null;
        $aiModel = null;

        if ($thumbnail->ai_model_id) {
            $aiModel = AiModel::find($thumbnail->ai_model_id);
            if ($aiModel) {
                $loraName = $this->getLoraFilename($aiModel);
                $triggerWord = $aiModel->triggerWord() ?: $aiModel->name;

                Log::info('Using custom LoRA for ComfyUI', [
                    'thumbnail_id' => $thumbnail->id,
                    'ai_model_id' => $aiModel->id,
                    'lora_name' => $loraName,
                    'trigger_word' => $triggerWord,
                ]);
            }
        }

        // Update seed in node 3 (KSampler)
        // Use a truly random seed to avoid blank image issues with certain seed patterns
        // Flux models are sensitive to seed values - some seeds produce blank/near-blank output
        $seed = random_int(1, 2147483647);
        if (isset($workflowNodes['3'])) {
            $workflowNodes['3']['inputs']['seed'] = $seed;
            $workflowNodes['3']['inputs']['steps'] = $this->defaultSteps;
            // For Flux, the KSampler cfg is less important - FluxGuidance node handles this
            // But we set it anyway for compatibility
            $workflowNodes['3']['inputs']['cfg'] = $this->defaultCfg;
        }

        // Update negative prompt in node 7
        if (isset($workflowNodes['7'])) {
            $workflowNodes['7']['inputs']['text'] = $prompts['negative'];
        }

        // Update filename_prefix in node 9 (SaveImage)
        $timestamp = time();
        $filenamePrefix = "{$thumbnail->user_id}_{$thumbnail->id}_{$timestamp}";
        if (isset($workflowNodes['9'])) {
            $workflowNodes['9']['inputs']['filename_prefix'] = $filenamePrefix;
        }

        // Update image dimensions in node 72 (EmptySD3LatentImage)
        if (isset($workflowNodes['72'])) {
            $workflowNodes['72']['inputs']['width'] = $this->defaultWidth;
            $workflowNodes['72']['inputs']['height'] = $this->defaultHeight;
        }

        // Update node 130 - Power Lora Loader (rgthree) with user's LoRA
        // Use 0.85 strength for better detail preservation while maintaining likeness
        $loraStrength = 0.85;
        if (isset($workflowNodes['130']) && $loraName) {
            // New structure: Power Lora Loader (rgthree)
            if (isset($workflowNodes['130']['inputs']['lora_1'])) {
                $workflowNodes['130']['inputs']['lora_1']['lora'] = $loraName;
                $workflowNodes['130']['inputs']['lora_1']['on'] = true;
                $workflowNodes['130']['inputs']['lora_1']['strength'] = $loraStrength;

                Log::info('Updated Power Lora Loader with user LoRA', [
                    'lora_name' => $loraName,
                    'strength' => $loraStrength,
                ]);
            }
            // Old structure: standard LoraLoader
            elseif (isset($workflowNodes['130']['inputs']['lora_name'])) {
                $workflowNodes['130']['inputs']['lora_name'] = $loraName;
                if (isset($workflowNodes['130']['inputs']['strength_model'])) {
                    $workflowNodes['130']['inputs']['strength_model'] = $loraStrength;
                }
            }
        } elseif (isset($workflowNodes['130'])) {
            // No AI model — disable the LoRA node so the template default doesn't run
            if (isset($workflowNodes['130']['inputs']['lora_1'])) {
                $workflowNodes['130']['inputs']['lora_1']['on'] = false;
                $workflowNodes['130']['inputs']['lora_1']['strength'] = 0;
                Log::info('Disabled Power Lora Loader (no AI model, text-only generation)');
            } elseif (isset($workflowNodes['130']['inputs']['strength_model'])) {
                $workflowNodes['130']['inputs']['strength_model'] = 0;
                Log::info('Set LoRA strength to 0 (no AI model, text-only generation)');
            }
        }

        // Update node 11 - FluxGuidance with proper guidance value for sharper output
        if (isset($workflowNodes['11'])) {
            $workflowNodes['11']['inputs']['guidance'] = $this->defaultCfg;
        }

        // Update node 131 - Lora Trigger Words with user's trigger word
        if (isset($workflowNodes['131']) && $triggerWord) {
            $workflowNodes['131']['inputs']['positive'] = $triggerWord;

            Log::info('Updated trigger word node', [
                'trigger_word' => $triggerWord,
            ]);
        } elseif (isset($workflowNodes['131'])) {
            // No trigger word — clear it so template default doesn't leak
            $workflowNodes['131']['inputs']['positive'] = '';
            Log::info('Cleared trigger word node (text-only generation)');
        }

        // Update node 132 - Positive Prompt with user's prompt
        if (isset($workflowNodes['132'])) {
            // Build the positive prompt - use the prompts from thumbnail
            $positivePrompt = $prompts['positive'];

            // Only add trigger word / gender when we have an AI model
            if ($aiModel) {
                $genderTerm = '';
                if ($aiModel->aiModelType) {
                    $genderTerm = strtolower($aiModel->aiModelType->name);
                }

                if ($triggerWord && strpos($positivePrompt, $triggerWord) === false) {
                    if ($genderTerm) {
                        $positivePrompt = "portrait of {$triggerWord}, {$genderTerm} person, " . $positivePrompt;
                    } else {
                        $positivePrompt = "portrait of {$triggerWord}, " . $positivePrompt;
                    }
                }

                if ($genderTerm && strpos(strtolower($positivePrompt), $genderTerm) === false) {
                    $positivePrompt .= ", {$genderTerm} person";
                }
            }

            $workflowNodes['132']['inputs']['positive'] = $positivePrompt;

            Log::info('Updated positive prompt node', [
                'prompt_length' => strlen($positivePrompt),
                'has_ai_model' => (bool) $aiModel,
            ]);
        }

        // For old workflow structure: Update node 6 (positive prompt) directly
        if (isset($workflowNodes['6']) && isset($workflowNodes['6']['inputs']['text']) && is_string($workflowNodes['6']['inputs']['text'])) {
            $workflowNodes['6']['inputs']['text'] = $prompts['positive'];
        }

        // Put the nodes back in the correct structure
        if ($hasPromptWrapper) {
            $workflow['prompt'] = $workflowNodes;
        } else {
            $workflow = $workflowNodes;
        }

        Log::info('Workflow prepared for ComfyUI', [
            'thumbnail_id' => $thumbnail->id,
            'filename_prefix' => $filenamePrefix,
            'seed' => $seed,
            'lora_name' => $loraName,
            'trigger_word' => $triggerWord,
            'width' => $this->defaultWidth,
            'height' => $this->defaultHeight,
            'steps' => $this->defaultSteps,
            'cfg' => $this->defaultCfg,
            'has_prompt_wrapper' => $hasPromptWrapper,
            'server_url' => $this->serverUrl,
        ]);

        try {
            $url = rtrim($this->serverUrl, '/') . '/prompt';

            Log::info('Making HTTP request to ComfyUI API', [
                'url' => $url,
                'thumbnail_id' => $thumbnail->id,
            ]);

            // Wrap workflow in 'prompt' key for ComfyUI API if not already wrapped
            $requestBody = $hasPromptWrapper ? $workflow : ['prompt' => $workflow];

            // Log the JSON workflow being sent to ComfyUI
            Log::debug('ComfyUI workflow JSON being sent', [
                'thumbnail_id' => $thumbnail->id,
                'workflow_json' => json_encode($requestBody, JSON_PRETTY_PRINT),
            ]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post($url, $requestBody);

            Log::info('Received response from ComfyUI API', [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_size' => strlen($response->body()),
            ]);

            if (!$response->successful()) {
                Log::error('ComfyUI API request failed', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'headers' => $response->headers(),
                    'thumbnail_id' => $thumbnail->id,
                ]);
                throw new \Exception('ComfyUI API request failed: ' . $response->body());
            }

            $result = $response->json();

            Log::info('ComfyUI response JSON parsed', [
                'has_prompt_id' => isset($result['prompt_id']),
                'thumbnail_id' => $thumbnail->id,
                'prompt_id' => $result['prompt_id'] ?? null,
                'number' => $result['number'] ?? null,
                'node_errors' => $result['node_errors'] ?? [],
            ]);

            $promptId = $result['prompt_id'] ?? null;

            // Store the prompt_id in the thumbnail
            if ($promptId) {
                $thumbnail->comfy_prompt_id = $promptId;
                $thumbnail->save();

                Log::info('Stored ComfyUI prompt_id in thumbnail', [
                    'thumbnail_id' => $thumbnail->id,
                    'comfy_prompt_id' => $promptId,
                ]);
            }

            // Return the prompt_id and the workflow
            return [
                'prompt_id' => $promptId,
                'workflow' => $workflow,
                'filename_prefix' => $filenamePrefix,
                'server_url' => $this->serverUrl,
            ];

        } catch (\Exception $e) {
            Log::error('ComfyUIService::generateThumbnailImage error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'thumbnail_id' => $thumbnail->id,
            ]);

            throw new \Exception('ComfyUI API failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the status of a ComfyUI prompt
     *
     * @throws \Exception
     */
    public function getPromptStatus(string $promptId): ?array
    {
        if (empty($this->serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/prompt/' . $promptId;

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::error('ComfyUI getPromptStatus failed', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'prompt_id' => $promptId,
                ]);

                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('ComfyUIService::getPromptStatus error', [
                'error_message' => $e->getMessage(),
                'prompt_id' => $promptId,
            ]);
            throw $e;
        }
    }

    /**
     * Get the history of ComfyUI prompts
     *
     * @throws \Exception
     */
    public function getHistory(int $limit = 1): ?array
    {
        if (empty($this->serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/history/' . $limit;

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::error('ComfyUI getHistory failed', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('ComfyUIService::getHistory error', [
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get history for a specific prompt_id
     *
     * @throws \Exception
     */
    public function getHistoryForPrompt(string $promptId): ?array
    {
        if (empty($this->serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        if (empty($promptId)) {
            Log::error('ComfyUIService::getHistoryForPrompt called with empty prompt_id');

            return null;
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/history/' . $promptId;

            Log::debug('ComfyUIService::getHistoryForPrompt querying', [
                'url' => $url,
                'prompt_id' => $promptId,
            ]);

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::error('ComfyUI getHistoryForPrompt failed', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'prompt_id' => $promptId,
                ]);

                return null;
            }

            $result = $response->json();

            // Log the full response for debugging
            Log::debug('ComfyUIService::getHistoryForPrompt full response', [
                'prompt_id' => $promptId,
                'response_keys' => array_keys($result),
                'has_prompt_id_key' => isset($result[$promptId]),
                'raw_response' => json_encode($result),
            ]);

            // Check if the result contains data for our prompt_id
            if (isset($result[$promptId])) {
                $promptData = $result[$promptId];
                Log::debug('ComfyUIService::getHistoryForPrompt found prompt data', [
                    'prompt_id' => $promptId,
                    'has_status' => isset($promptData['status']),
                    'status' => $promptData['status'] ?? null,
                    'has_outputs' => isset($promptData['outputs']),
                    'output_keys' => isset($promptData['outputs']) ? array_keys($promptData['outputs']) : [],
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('ComfyUIService::getHistoryForPrompt error', [
                'error_message' => $e->getMessage(),
                'prompt_id' => $promptId,
            ]);
            throw $e;
        }
    }

    /**
     * Check if a prompt is still in the queue or being processed
     *
     * @return array ['in_queue' => bool, 'running' => bool]
     */
    public function getQueueStatus(string $promptId): array
    {
        if (empty($this->serverUrl)) {
            return ['in_queue' => false, 'running' => false];
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/queue';
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                return ['in_queue' => false, 'running' => false];
            }

            $queue = $response->json();

            $inQueue = false;
            $running = false;

            // Check queue_pending
            if (isset($queue['queue_pending']) && is_array($queue['queue_pending'])) {
                foreach ($queue['queue_pending'] as $item) {
                    if (isset($item[1]) && $item[1] === $promptId) {
                        $inQueue = true;
                        break;
                    }
                }
            }

            // Check queue_running
            if (isset($queue['queue_running']) && is_array($queue['queue_running'])) {
                foreach ($queue['queue_running'] as $item) {
                    if (isset($item[1]) && $item[1] === $promptId) {
                        $running = true;
                        break;
                    }
                }
            }

            Log::debug('ComfyUIService::getQueueStatus', [
                'prompt_id' => $promptId,
                'in_queue' => $inQueue,
                'running' => $running,
            ]);

            return ['in_queue' => $inQueue, 'running' => $running];

        } catch (\Exception $e) {
            Log::warning('ComfyUIService::getQueueStatus error', [
                'error' => $e->getMessage(),
            ]);

            return ['in_queue' => false, 'running' => false];
        }
    }

    /**
     * Get image from ComfyUI output
     *
     * @param  int  $maxRetries  Maximum number of retry attempts (default: 3)
     * @return string|null Image data or null
     *
     * @throws \Exception
     */
    public function getImage(string $filename, int $maxRetries = 3, string $subfolder = '', string $type = 'output'): ?string
    {
        if (empty($this->serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        if (empty($filename)) {
            Log::error('ComfyUIService::getImage called with empty filename', [
                'server_url' => $this->serverUrl,
            ]);
            throw new \Exception('Filename cannot be empty');
        }

        // Ensure serverUrl is valid
        $baseUrl = rtrim($this->serverUrl, '/');
        if (empty($baseUrl)) {
            throw new \Exception('ComfyUI server URL is empty');
        }

        // Build URL with subfolder and type parameters
        $url = $baseUrl . '/view?filename=' . urlencode($filename);
        if (!empty($subfolder)) {
            $url .= '&subfolder=' . urlencode($subfolder);
        }
        $url .= '&type=' . urlencode($type);

        // Validate the constructed URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Log::error('ComfyUIService::getImage constructed invalid URL', [
                'server_url' => $this->serverUrl,
                'base_url' => $baseUrl,
                'filename' => $filename,
                'constructed_url' => $url,
            ]);
            throw new \Exception('Invalid URL constructed: ' . $url);
        }

        Log::info('ComfyUIService::getImage constructing URL', [
            'server_url' => $this->serverUrl,
            'filename' => $filename,
            'constructed_url' => $url,
        ]);

        $lastException = null;

        // Retry logic with increasing timeouts for large image downloads
        // Timeouts are generous to handle slow network connections
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Increase timeout based on attempt number: 180s, 300s, 600s
                // These longer timeouts account for slow connections (~6KB/s for 1MB image = 170s)
                $timeout = match ($attempt) {
                    1 => 180,  // 3 minutes - should handle most images
                    2 => 300,  // 5 minutes - for slower connections
                    default => 600,  // 10 minutes - last resort for very slow connections
                };

                Log::info('ComfyUIService::getImage attempting download', [
                    'filename' => $filename,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'timeout' => $timeout,
                ]);

                // Use streaming with sink to handle large files more reliably
                // This prevents memory issues and handles slow connections better
                $response = Http::timeout($timeout)
                    ->withOptions([
                        'connect_timeout' => 60,  // 60 seconds to establish connection
                        'read_timeout' => $timeout,  // Match the overall timeout
                        'stream' => true,  // Enable streaming for large files
                    ])
                    ->get($url);

                if (!$response->successful()) {
                    Log::error('ComfyUI getImage failed', [
                        'status_code' => $response->status(),
                        'response_body' => substr($response->body(), 0, 500),
                        'filename' => $filename,
                        'attempt' => $attempt,
                    ]);

                    return null;
                }

                $imageData = $response->body();

                Log::info('ComfyUIService::getImage download successful', [
                    'filename' => $filename,
                    'attempt' => $attempt,
                    'bytes_received' => strlen($imageData),
                ]);

                return $imageData;

            } catch (\Exception $e) {
                $lastException = $e;
                $isTimeout = strpos($e->getMessage(), 'Operation timed out') !== false
                    || strpos($e->getMessage(), 'cURL error 28') !== false
                    || strpos($e->getMessage(), 'timed out') !== false;

                Log::warning('ComfyUIService::getImage attempt failed', [
                    'filename' => $filename,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error_message' => $e->getMessage(),
                    'is_timeout' => $isTimeout,
                ]);

                // Only retry on timeout errors, throw immediately for other errors
                if (!$isTimeout) {
                    throw $e;
                }

                // Wait before retry (exponential backoff: 5s, 10s, 20s)
                // Longer wait times to allow network to recover
                if ($attempt < $maxRetries) {
                    $waitTime = 5 * pow(2, $attempt - 1);
                    Log::info('ComfyUIService::getImage waiting before retry', [
                        'filename' => $filename,
                        'wait_seconds' => $waitTime,
                    ]);
                    sleep($waitTime);
                }
            }
        }

        // All retries exhausted
        Log::error('ComfyUIService::getImage all retries exhausted', [
            'filename' => $filename,
            'max_retries' => $maxRetries,
            'last_error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new \Exception("Failed to download image after {$maxRetries} attempts");
    }

    /**
     * Get a visualizable scene from description
     * (Not used by ComfyUI, but required by interface)
     *
     * @param  string  $description  The description to base scene on
     * @return string The visualizable scene
     *
     * @throws \Exception
     */
    public function getVisualizableScene(string $description): string
    {
        // ComfyUI uses prompts directly, so we just return the description
        return $description;
    }

    /**
     * Generate thumbnail synchronously by polling for completion
     *
     * @return array Array with 'image_url' and 'prompt' keys
     *
     * @throws \Exception
     */
    /**
     * Generate thumbnail synchronously - waits for ComfyUI to complete
     * Default timeout: 120 attempts × 5 seconds = 10 minutes
     */
    public function generateThumbnailSync(string $description, \App\Models\Thumbnail $thumbnail, int $maxAttempts = 120, int $delaySeconds = 5): array
    {
        Log::info('ComfyUIService::generateThumbnailSync started', [
            'thumbnail_id' => $thumbnail->id,
            'max_attempts' => $maxAttempts,
        ]);

        // Start the generation
        $result = $this->generateThumbnailImage($description, $thumbnail);

        $promptId = $result['prompt_id'] ?? null;
        $filenamePrefix = $result['filename_prefix'] ?? null;

        if (!$promptId) {
            throw new \Exception('Failed to get prompt_id from ComfyUI');
        }

        Log::info('Waiting for ComfyUI generation to complete', [
            'prompt_id' => $promptId,
            'thumbnail_id' => $thumbnail->id,
            'filename_prefix' => $filenamePrefix,
        ]);

        // Poll for completion
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            sleep($delaySeconds);

            try {
                // First check if still in queue/running
                $queueStatus = $this->getQueueStatus($promptId);

                if ($queueStatus['in_queue'] || $queueStatus['running']) {
                    Log::debug('ComfyUI still processing', [
                        'prompt_id' => $promptId,
                        'attempt' => $attempt,
                        'in_queue' => $queueStatus['in_queue'],
                        'running' => $queueStatus['running'],
                    ]);

                    continue;
                }

                // Check history for completed results
                $history = $this->getHistoryForPrompt($promptId);

                if ($history && isset($history[$promptId])) {
                    $promptHistory = $history[$promptId];
                    $status = $promptHistory['status'] ?? null;

                    Log::debug('ComfyUI history found', [
                        'prompt_id' => $promptId,
                        'attempt' => $attempt,
                        'status' => $status,
                        'has_outputs' => isset($promptHistory['outputs']),
                    ]);

                    // Check if completed - look for outputs
                    if (isset($promptHistory['outputs']) && !empty($promptHistory['outputs'])) {
                        // Find the image output from SaveImage node (node 9)
                        foreach ($promptHistory['outputs'] as $nodeId => $output) {
                            Log::debug('Checking output node', [
                                'node_id' => $nodeId,
                                'has_images' => isset($output['images']),
                                'output_keys' => array_keys($output),
                            ]);

                            if (isset($output['images']) && !empty($output['images'])) {
                                $imageInfo = $output['images'][0];
                                $filename = $imageInfo['filename'] ?? null;
                                $subfolder = $imageInfo['subfolder'] ?? '';
                                $type = $imageInfo['type'] ?? 'output';

                                Log::info('Found image in ComfyUI output', [
                                    'node_id' => $nodeId,
                                    'filename' => $filename,
                                    'subfolder' => $subfolder,
                                    'type' => $type,
                                ]);

                                if ($filename) {
                                    // Build the correct view URL with subfolder if present
                                    $imageUrl = $this->getImageUrl($filename, $subfolder, $type);

                                    Log::info('ComfyUI generation completed successfully', [
                                        'prompt_id' => $promptId,
                                        'thumbnail_id' => $thumbnail->id,
                                        'filename' => $filename,
                                        'image_url' => $imageUrl,
                                        'attempts' => $attempt,
                                    ]);

                                    return [
                                        'image_url' => $imageUrl,
                                        'prompt' => $thumbnail->prompt ?: $description,
                                        'filename' => $filename,
                                        'subfolder' => $subfolder,
                                    ];
                                }
                            }
                        }
                    }

                    // Check for errors in status
                    if (isset($promptHistory['status']['status_str']) && $promptHistory['status']['status_str'] === 'error') {
                        $errorMessages = $promptHistory['status']['messages'] ?? [];
                        Log::error('ComfyUI generation failed with error', [
                            'prompt_id' => $promptId,
                            'error_messages' => $errorMessages,
                        ]);
                        throw new \Exception('ComfyUI generation failed: ' . json_encode($errorMessages));
                    }
                } else {
                    Log::debug('ComfyUI history not yet available', [
                        'prompt_id' => $promptId,
                        'attempt' => $attempt,
                        'history_keys' => $history ? array_keys($history) : [],
                    ]);
                }
            } catch (\Exception $e) {
                // Only log as warning if it's a timeout-related issue, not a fatal error
                if (strpos($e->getMessage(), 'ComfyUI generation failed') !== false) {
                    throw $e;
                }
                Log::warning('Error polling ComfyUI', [
                    'prompt_id' => $promptId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If we got here, try one last time to find the file by prefix
        Log::warning('ComfyUI polling exhausted, attempting fallback file search', [
            'prompt_id' => $promptId,
            'filename_prefix' => $filenamePrefix,
        ]);

        throw new \Exception("ComfyUI generation timed out after {$maxAttempts} attempts. prompt_id: {$promptId}, filename_prefix: {$filenamePrefix}");
    }

    /**
     * Get the full URL for an image on ComfyUI server
     *
     * @param  string  $subfolder  Optional subfolder path
     * @param  string  $type  Image type (output, input, temp)
     */
    public function getImageUrl(string $filename, string $subfolder = '', string $type = 'output'): string
    {
        $url = rtrim($this->serverUrl, '/') . '/view?filename=' . urlencode($filename);

        if (!empty($subfolder)) {
            $url .= '&subfolder=' . urlencode($subfolder);
        }

        $url .= '&type=' . urlencode($type);

        return $url;
    }

    /**
     * Upload a LoRA file to the ComfyUI server
     * Note: This requires the ComfyUI server to have an upload endpoint enabled
     *
     * @param  string  $localPath  Path to the local LoRA file
     * @param  string  $targetFilename  The filename to save as on the server
     */
    public function uploadLora(string $localPath, string $targetFilename): bool
    {
        if (empty($this->serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        if (!file_exists($localPath)) {
            throw new \Exception("LoRA file not found: {$localPath}");
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/upload/image';

            Log::info('Uploading LoRA to ComfyUI', [
                'local_path' => $localPath,
                'target_filename' => $targetFilename,
                'upload_url' => $url,
            ]);

            // Read the file
            $fileContent = file_get_contents($localPath);

            // ComfyUI uses a specific upload format
            $response = Http::attach(
                'image',
                $fileContent,
                $targetFilename
            )->timeout(300)->post($url, [
                        'subfolder' => 'loras',
                        'type' => 'input',
                    ]);

            if (!$response->successful()) {
                Log::error('Failed to upload LoRA to ComfyUI', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                ]);

                return false;
            }

            Log::info('LoRA uploaded to ComfyUI successfully', [
                'target_filename' => $targetFilename,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Exception uploading LoRA to ComfyUI', [
                'error' => $e->getMessage(),
                'local_path' => $localPath,
            ]);

            return false;
        }
    }

    /**
     * Check if a LoRA exists on the ComfyUI server
     */
    public function loraExists(string $loraName): bool
    {
        if (empty($this->serverUrl)) {
            return false;
        }

        try {
            // Get list of available LoRAs from ComfyUI
            $url = rtrim($this->serverUrl, '/') . '/object_info/LoraLoader';

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                return false;
            }

            $data = $response->json();

            // Check if the LoRA is in the list
            $availableLoras = $data['LoraLoader']['input']['required']['lora_name'][0] ?? [];

            return in_array($loraName, $availableLoras);

        } catch (\Exception $e) {
            Log::warning('Error checking LoRA existence on ComfyUI', [
                'lora_name' => $loraName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get list of available LoRAs on the ComfyUI server
     */
    public function getAvailableLoras(): array
    {
        if (empty($this->serverUrl)) {
            return [];
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/object_info/LoraLoader';

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                return [];
            }

            $data = $response->json();

            return $data['LoraLoader']['input']['required']['lora_name'][0] ?? [];

        } catch (\Exception $e) {
            Log::warning('Error getting available LoRAs from ComfyUI', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ========================================
    // Copy Thumbnail Feature Methods
    // ========================================

    /**
     * Load the copy thumbnail workflow template from JSON file
     *
     * @throws \Exception
     */
    public function loadCopyThumbnailWorkflow(): array
    {
        $templatePath = app_path('Prompts/copy_thumbnail.json');

        if (!file_exists($templatePath)) {
            throw new \Exception("Copy thumbnail workflow template not found at: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        $workflow = json_decode($template, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse copy thumbnail workflow template: ' . json_last_error_msg());
        }

        Log::info('Loaded copy thumbnail workflow template', [
            'node_count' => count($workflow),
        ]);

        return $workflow;
    }

    /**
     * Upload an image to ComfyUI server for use in workflows
     *
     * @param  string  $imagePath  Local path to the image
     * @param  string  $filename  Filename to use on the server
     * @return string|null The filename on the server, or null on failure
     */
    public function uploadImageToComfyUI(string $imagePath, string $filename): ?string
    {
        if (empty($this->serverUrl)) {
            Log::error('ComfyUI server URL not configured');

            return null;
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/upload/image';

            // Check if it's a storage path or absolute path
            if (Storage::disk('public')->exists($imagePath)) {
                $imageContent = Storage::disk('public')->get($imagePath);
                $mimeType = Storage::disk('public')->mimeType($imagePath) ?: 'image/png';
            } elseif (file_exists($imagePath)) {
                $imageContent = file_get_contents($imagePath);
                $mimeType = mime_content_type($imagePath) ?: 'image/png';
            } else {
                Log::error('Image file not found for upload', [
                    'image_path' => $imagePath,
                ]);

                return null;
            }

            Log::info('Uploading image to ComfyUI', [
                'filename' => $filename,
                'url' => $url,
                'size' => strlen($imageContent),
            ]);

            $response = Http::timeout(340)
                ->attach('image', $imageContent, $filename)
                ->post($url, [
                    'overwrite' => 'true',
                    'type' => 'input',
                ]);

            if (!$response->successful()) {
                Log::error('Failed to upload image to ComfyUI', [
                    'status_code' => $response->status(),
                    'response' => $response->body(),
                ]);

                return null;
            }

            $result = $response->json();
            $uploadedFilename = $result['name'] ?? $filename;

            Log::info('Successfully uploaded image to ComfyUI', [
                'original_filename' => $filename,
                'uploaded_filename' => $uploadedFilename,
            ]);

            return $uploadedFilename;

        } catch (\Exception $e) {
            Log::error('Exception uploading image to ComfyUI', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath,
            ]);

            return null;
        }
    }

    /**
     * Generate a copy thumbnail using ComfyUI
     *
     * @param  string  $uploadedImageFilename  The filename of the uploaded image on ComfyUI
     * @param  string|null  $transformedPrompt  Optional transformed prompt to use
     * @return array Array with 'prompt_id' key
     *
     * @throws \Exception
     */
    public function generateCopyThumbnail(\App\Models\CopyThumbnail $copyThumbnail, string $uploadedImageFilename, ?string $transformedPrompt = null): array
    {
        Log::info('ComfyUIService::generateCopyThumbnail started', [
            'copy_thumbnail_id' => $copyThumbnail->id,
            'uploaded_image' => $uploadedImageFilename,
            'resolution' => "{$copyThumbnail->resolution_width}x{$copyThumbnail->resolution_height}",
            'dw_pose_enabled' => $copyThumbnail->dw_pose_enabled,
            'has_transformed_prompt' => !empty($transformedPrompt),
        ]);

        if (empty($this->serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        // Load the copy thumbnail workflow
        $workflow = $this->loadCopyThumbnailWorkflow();

        // Get config values
        $config = config('services.copy_thumbnail');
        $width = $copyThumbnail->resolution_width ?? $config['default_width'] ?? 512;
        $height = $copyThumbnail->resolution_height ?? $config['default_height'] ?? 512;
        $dwPoseEnabled = $copyThumbnail->dw_pose_enabled ?? $config['dw_pose_enabled'] ?? true;
        $steps = $config['default_steps'] ?? 30;
        $cfg = $config['default_cfg'] ?? 1;
        $denoise = $config['default_denoise'] ?? 0.9;
        $guidance = $config['guidance'] ?? 3.8;

        $copyThumbnail->addLog('Preparing workflow', [
            'width' => $width,
            'height' => $height,
            'dw_pose_enabled' => $dwPoseEnabled,
            'steps' => $steps,
            'guidance' => $guidance,
        ]);

        // Update node 436 - LoadImage with the uploaded image filename
        if (isset($workflow['436'])) {
            $workflow['436']['inputs']['image'] = $uploadedImageFilename;
            Log::info('Updated LoadImage node', ['filename' => $uploadedImageFilename]);
        }

        // Update resolution in node 175 (ImageResize+)
        if (isset($workflow['175'])) {
            $workflow['175']['inputs']['width'] = $width;
        }

        // Update resolution in node 399 (ImageResize+)
        if (isset($workflow['399'])) {
            $workflow['399']['inputs']['width'] = $width;
            $workflow['399']['inputs']['height'] = $height;
        }

        // Update resolution in node 420 (InpaintCropImproved)
        if (isset($workflow['420'])) {
            $workflow['420']['inputs']['output_target_width'] = $width;
            $workflow['420']['inputs']['output_target_height'] = $height;
        }

        // Update KSampler node 346
        $seed = random_int(1, 2147483647);
        if (isset($workflow['346'])) {
            $workflow['346']['inputs']['seed'] = $seed;
            $workflow['346']['inputs']['steps'] = $steps;
            $workflow['346']['inputs']['cfg'] = $cfg;
            $workflow['346']['inputs']['denoise'] = $denoise;
        }

        // Update FluxGuidance node 345
        if (isset($workflow['345'])) {
            $workflow['345']['inputs']['guidance'] = $guidance;
        }

        // Handle DW Pose Estimator (node 475)
        if (!$dwPoseEnabled && isset($workflow['475'])) {
            // Disable DW Pose by removing the node and its connections
            // We need to bypass the ControlNet nodes too
            Log::info('DW Pose Estimator disabled, removing pose nodes');

            // Remove node 475 (DWPreprocessor)
            unset($workflow['475']);

            // Remove node 476 (ControlNetLoader for Pose)
            if (isset($workflow['476'])) {
                unset($workflow['476']);
            }

            // Remove node 477 (ControlNetApplyAdvanced)
            // And connect the prompt directly to FluxGuidance instead
            if (isset($workflow['477'])) {
                // Update node 345 (FluxGuidance) to get conditioning from CLIP directly
                if (isset($workflow['345'])) {
                    $workflow['345']['inputs']['conditioning'] = ['468', 0];
                }
                unset($workflow['477']);
            }

            // Remove pose preview node 478
            if (isset($workflow['478'])) {
                unset($workflow['478']);
            }

            $copyThumbnail->addLog('Disabled DW Pose Estimator nodes');
        } elseif ($dwPoseEnabled && isset($workflow['475'])) {
            // Configure DW Pose for HEAD-FOCUSED detection only
            // Disable hand and body detection, keep only face for head pose
            $workflow['475']['inputs']['detect_hand'] = 'disable';
            $workflow['475']['inputs']['detect_body'] = 'disable';
            $workflow['475']['inputs']['detect_face'] = 'enable';
            // Match resolution to output height from config
            $workflow['475']['inputs']['resolution'] = $height;

            Log::info('DW Pose configured for head-only detection', [
                'detect_hand' => 'disable',
                'detect_body' => 'disable',
                'detect_face' => 'enable',
                'resolution' => $height,
            ]);

            // Check if model is bald to adjust ControlNet strength
            $isBaldModel = false;
            if ($copyThumbnail->ai_model_id) {
                $checkAiModel = AiModel::find($copyThumbnail->ai_model_id);
                $isBaldModel = $checkAiModel && $checkAiModel->bald;
            }

            // Set ControlNet strength based on bald status
            // For bald models: LOW strength (0.3) - only capture face position, NOT hair
            // For non-bald models: HIGH strength (0.95) - preserve hair style from reference
            if (isset($workflow['477'])) {
                $controlNetStrength = $isBaldModel ? 0.3 : 0.95;
                $workflow['477']['inputs']['strength'] = $controlNetStrength;
                Log::info('Set ControlNet strength', [
                    'strength' => $controlNetStrength,
                    'is_bald' => $isBaldModel,
                ]);
            }

            // For bald models: increase denoise to fully regenerate the head
            // This prevents the model from copying ANY features from reference image hair
            if ($isBaldModel && isset($workflow['346'])) {
                $workflow['346']['inputs']['denoise'] = 1.0;  // Full regeneration
                Log::info('Increased denoise to 1.0 for bald model');
            }

            $copyThumbnail->addLog('Configured DW Pose for head-only detection', [
                'detect_face_only' => true,
                'controlnet_strength' => $isBaldModel ? 0.3 : 0.95,
                'is_bald_model' => $isBaldModel,
                'denoise' => $isBaldModel ? 1.0 : $denoise,
            ]);
        }

        // Update LoRA if AI model is specified
        if ($copyThumbnail->ai_model_id) {
            $aiModel = AiModel::find($copyThumbnail->ai_model_id);
            if ($aiModel && isset($workflow['337'])) {
                $loraName = $this->getLoraFilename($aiModel);
                $workflow['337']['inputs']['lora_3']['lora'] = $loraName;
                $workflow['337']['inputs']['lora_3']['on'] = true;

                Log::info('Updated LoRA in workflow', [
                    'ai_model_id' => $aiModel->id,
                    'lora_name' => $loraName,
                ]);

                $copyThumbnail->addLog('Applied custom LoRA', [
                    'ai_model' => $aiModel->name,
                    'lora' => $loraName,
                ]);
            }
        }

        // Inject transformed prompt if provided
        // This bypasses the workflow's internal Florence2 caption and uses our pre-transformed prompt
        if (!empty($transformedPrompt)) {
            // Update node 472 (Text Multiline) which is the base for the positive prompt
            if (isset($workflow['472'])) {
                $workflow['472']['inputs']['text'] = $transformedPrompt;
                Log::info('Injected transformed prompt into node 472', [
                    'prompt_length' => strlen($transformedPrompt),
                ]);
            }

            // Also update node 467 (Final Positive Prompt - ShowText) as fallback
            if (isset($workflow['467']) && isset($workflow['467']['inputs']['text_0'])) {
                $workflow['467']['inputs']['text_0'] = $transformedPrompt;
            }

            $copyThumbnail->addLog('Injected transformed prompt', [
                'prompt_preview' => substr($transformedPrompt, 0, 100),
            ]);
        }

        // Update negative prompt based on bald status
        // Merge with the negative prompt from prompt model if it exists
        // Ensure the relationship is loaded
        $copyThumbnail->load(['negativePrompt']);

        $baseNegativePrompt = '';
        if ($copyThumbnail->negativePrompt) {
            $baseNegativePrompt = $copyThumbnail->negativePrompt->text;
            Log::info('Loaded base negative prompt', [
                'negative_prompt_id' => $copyThumbnail->negative_prompt_id,
                'text_length' => strlen($baseNegativePrompt),
            ]);
        }

        if ($copyThumbnail->ai_model_id) {
            $aiModel = AiModel::find($copyThumbnail->ai_model_id);
            if ($aiModel && isset($workflow['404'])) {
                if ($aiModel->bald) {
                    // For bald models: Add MAXIMUM STRENGTH hair-blocking terms
                    // Use very high emphasis weights (2.0) to ensure complete hair blocking
                    // This is critical when reference images contain hair
                    $hairBlockingTerms = '(hair:2.0), (any hair on head:2.0), (head with hair:2.0), (hairy:2.0), (wig:2.0), (toupee:2.0), (stubble:1.8), (buzzcut:1.8), (hairline:1.8), (short hair:1.8), (crew cut:1.8), (wavy hair:1.8), (curly hair:1.8), (straight hair:1.8), (dark hair:1.8), (hair strands:1.6), flowing hair, styled hair, bangs, long hair, receding hairline, full head of hair, thick hair, thin hair, messy hair, spiky hair, blonde hair, brown hair, visible hair, head stubble, hair stubble, scalp hair, peach fuzz, shaved head with stubble, any visible hair';

                    // Combine base negative prompt with hair-blocking terms AT THE START for priority
                    $finalNegativePrompt = !empty($baseNegativePrompt)
                        ? "{$hairBlockingTerms}, {$baseNegativePrompt}"
                        : $hairBlockingTerms;

                    $workflow['404']['inputs']['text'] = $finalNegativePrompt;

                    Log::info('Updated negative prompt for bald model', [
                        'hair_terms_added' => true,
                        'final_length' => strlen($finalNegativePrompt),
                    ]);

                    $copyThumbnail->addLog('Updated negative prompt for bald model', [
                        'includes_base_prompt' => !empty($baseNegativePrompt),
                        'negative_preview' => substr($finalNegativePrompt, 0, 150),
                    ]);
                } else {
                    // For non-bald models: NO hair-blocking terms, use only base negative prompt
                    // Make sure no hair-related blocking terms are present
                    $finalNegativePrompt = !empty($baseNegativePrompt)
                        ? $baseNegativePrompt
                        : 'deformed head, nsfw, deformed eye, deformed ear, deformed nose, distorted features, mutated, low quality, blurry';

                    $workflow['404']['inputs']['text'] = $finalNegativePrompt;
                    $copyThumbnail->addLog('Updated negative prompt for non-bald model (NO hair blocking)', [
                        'includes_base_prompt' => !empty($baseNegativePrompt),
                        'negative_preview' => substr($finalNegativePrompt, 0, 100),
                    ]);
                }
            }
        }

        // Update filename prefix for output (node 488 = SaveImage after FaceDetailer)
        $timestamp = time();
        $filenamePrefix = "CopyThumbnail/{$copyThumbnail->user_id}_{$copyThumbnail->id}_{$timestamp}";
        if (isset($workflow['488'])) {
            $workflow['488']['inputs']['filename_prefix'] = $filenamePrefix;
        }

        Log::info('Workflow prepared for copy thumbnail', [
            'copy_thumbnail_id' => $copyThumbnail->id,
            'filename_prefix' => $filenamePrefix,
            'seed' => $seed,
            'dw_pose_enabled' => $dwPoseEnabled,
        ]);

        // Submit to ComfyUI
        try {
            $url = rtrim($this->serverUrl, '/') . '/prompt';
            $requestBody = ['prompt' => $workflow];

            Log::debug('Submitting copy thumbnail workflow to ComfyUI', [
                'url' => $url,
                'copy_thumbnail_id' => $copyThumbnail->id,
            ]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post($url, $requestBody);

            if (!$response->successful()) {
                Log::error('ComfyUI API request failed for copy thumbnail', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'copy_thumbnail_id' => $copyThumbnail->id,
                ]);
                throw new \Exception('ComfyUI API request failed: ' . $response->body());
            }

            $result = $response->json();
            $promptId = $result['prompt_id'] ?? null;

            Log::info('ComfyUI copy thumbnail workflow submitted', [
                'copy_thumbnail_id' => $copyThumbnail->id,
                'prompt_id' => $promptId,
                'number' => $result['number'] ?? null,
            ]);

            $copyThumbnail->addLog('Workflow submitted to ComfyUI', [
                'prompt_id' => $promptId,
            ]);

            return [
                'prompt_id' => $promptId,
                'filename_prefix' => $filenamePrefix,
                'server_url' => $this->serverUrl,
            ];

        } catch (\Exception $e) {
            Log::error('ComfyUIService::generateCopyThumbnail error', [
                'error_message' => $e->getMessage(),
                'copy_thumbnail_id' => $copyThumbnail->id,
            ]);

            $copyThumbnail->addLog('ComfyUI submission failed', [
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('ComfyUI API failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if copy thumbnail feature is enabled
     */
    public function isCopyThumbnailEnabled(): bool
    {
        return (bool) config('services.copy_thumbnail.enabled', true) && $this->isEnabled();
    }

    // ========================================
    // Expression Extraction Methods
    // ========================================

    /**
     * Load the expression extraction workflow template
     *
     * @throws \Exception
     */
    public function loadExpressionWorkflow(): array
    {
        $templatePath = app_path('Prompts/expression_from_photo.json');

        if (!file_exists($templatePath)) {
            throw new \Exception("Expression workflow template not found at: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        $workflow = json_decode($template, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse expression workflow template: ' . json_last_error_msg());
        }

        Log::info('Loaded expression extraction workflow template', [
            'node_count' => count($workflow),
        ]);

        return $workflow;
    }

    /**
     * Extract expression/description from an image using Florence2
     *
     * @param  string  $uploadedImageFilename  The filename of the image on ComfyUI server
     * @return string|null The extracted expression text
     *
     * @throws \Exception
     */
    public function extractExpressionFromImage(string $uploadedImageFilename): ?string
    {
        Log::info('ComfyUIService::extractExpressionFromImage starting', [
            'image_filename' => $uploadedImageFilename,
        ]);

        if (empty($this->serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        $workflow = $this->loadExpressionWorkflow();

        if (isset($workflow['1'])) {
            $workflow['1']['inputs']['image'] = $uploadedImageFilename;
        }

        if (isset($workflow['3'])) {
            $workflow['3']['inputs']['seed'] = random_int(1, PHP_INT_MAX);
        }

        try {
            $url = rtrim($this->serverUrl, '/') . '/prompt';
            $requestBody = ['prompt' => $workflow];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post($url, $requestBody);

            if (!$response->successful()) {
                throw new \Exception('ComfyUI expression request failed: ' . $response->body());
            }

            $result = $response->json();
            $promptId = $result['prompt_id'] ?? null;

            if (!$promptId) {
                throw new \Exception('No prompt_id returned for expression extraction');
            }

            Log::info('Expression extraction workflow submitted', ['prompt_id' => $promptId]);

            return $this->pollExpressionResult($promptId);

        } catch (\Exception $e) {
            Log::error('extractExpressionFromImage error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Poll for expression text result from ComfyUI
     */
    public function pollExpressionResult(string $promptId, int $maxAttempts = 60, int $delaySeconds = 2): ?string
    {
        Log::info('Polling for expression result', ['prompt_id' => $promptId]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $queueStatus = $this->getQueueStatus($promptId);

            if ($queueStatus['in_queue'] || $queueStatus['running']) {
                sleep($delaySeconds);

                continue;
            }

            $history = $this->getHistoryForPrompt($promptId);
            if (!$history || !isset($history[$promptId])) {
                sleep($delaySeconds);

                continue;
            }

            $promptData = $history[$promptId];
            $outputs = $promptData['outputs'] ?? [];

            if (isset($outputs['5']['text'][0])) {
                return $outputs['5']['text'][0];
            }
            if (isset($outputs['3']['text'][0])) {
                return $outputs['3']['text'][0];
            }

            sleep($delaySeconds);
        }

        return null;
    }
}
