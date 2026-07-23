<?php

namespace App\Services;

use App\Services\Contracts\ThumbnailServiceInterface;
use App\Services\PromptService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiService implements ThumbnailServiceInterface
{
    private string $apiKey;
    private string $model;
    private PromptService $promptService;

    public function __construct(PromptService $promptService)
    {
        $this->apiKey = (string) config('services.gemini.api_key');
        $this->model = (string) config('services.gemini.model', 'gemini-3-pro-image-preview');
        $this->promptService = $promptService;
    }

    /**
     * Get the combined prompt from the thumbnail or create a fallback
     *
     * @param \App\Models\Thumbnail|null $thumbnail
     * @param string $visualizableScene
     * @return string|null
     */
    private function buildCombinedPrompt(?\App\Models\Thumbnail $thumbnail, string $visualizableScene): ?string
    {
        if ($thumbnail) {
            // Use the thumbnail's combined prompt text
            $combinedPrompt = $thumbnail->getCombinedPromptText();
            
            if ($combinedPrompt) {
                Log::info("Using thumbnail combined prompt", [
                    'thumbnail_id' => $thumbnail->id,
                    'prompt_length' => strlen($combinedPrompt)
                ]);
                
                return $combinedPrompt;
            }
        }
        
        // Fallback: create a simple prompt with the visualizable scene
        $fallbackPrompt =  $visualizableScene;
        Log::info("Using fallback prompt", [
            'fallback_prompt' => $fallbackPrompt
        ]);
        
        return $fallbackPrompt;
    }


    /**
     * Generate a thumbnail image using Google Gemini API
     *
     * @param string $description The description to base thumbnail on
     * @param \App\Models\Thumbnail|null $thumbnail The thumbnail model with prompt references
     * @return array Array with 'image_url' and 'prompt' keys
     * @throws \Exception
     */
    public function generateThumbnailImage(string $description, ?\App\Models\Thumbnail $thumbnail = null): array
    {
        Log::info("GeminiService::generateThumbnailImage started", [
            'description' => $description,
            'description_length' => strlen($description),
            'api_key' => $this->apiKey,
            'model' => $this->model
        ]);

        if (empty($this->apiKey) || $this->apiKey === 'your-gemini-api-key-here') {
            Log::error("Google Gemini API key not configured properly");
            throw new \Exception('Google Gemini API key not configured');
        }

        // First, check if the description is visualizable and get a visualizable scene
        Log::info("Checking if description is visualizable", [
            'original_description' => $description
        ]);
        
        
        // Combine all prompts from the thumbnail
        $prompt = $this->buildCombinedPrompt($thumbnail, $description);
        
        if (!$prompt) {
            throw new \Exception('Failed to build combined prompt');
        }

        // Read canvas image and convert to base64
        $canvasImagePath = resource_path('images/canvas.png');
        if (!file_exists($canvasImagePath)) {
            throw new \Exception('Canvas image not found at: ' . $canvasImagePath);
        }
        
        $canvasImageData = file_get_contents($canvasImagePath);
        $canvasImageBase64 = base64_encode($canvasImageData);
        
        Log::info("Canvas image loaded and encoded", [
            'image_path' => $canvasImagePath,
            'image_size' => strlen($canvasImageData),
            'base64_size' => strlen($canvasImageBase64)
        ]);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/png',
                                'data' => $canvasImageBase64
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'] // Required for image generation
            ]
        ];

        Log::info("Making HTTP request to Google Gemini API", [
            'url' => $url,
            'model' => $this->model,
            'original_description' => $description,
            'prompt' => $prompt
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($url, $data);

            Log::info("Received response from Gemini API", [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_size' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error("Gemini API request failed", [
                    'status_code' => $response->status(),
                    // 'response_body' => $response->body(),
                    'headers' => $response->headers(),
                    'description' => $description
                ]);
                throw new \Exception('Gemini API request failed: ');
            }

            $result = $response->json();
            
            Log::info("Gemini response JSON parsed", [
                'has_candidates' => isset($result['candidates']),
                'candidates_count' => isset($result['candidates']) ? count($result['candidates']) : 0,
                'response_structure' => array_keys($result)
            ]);
            
            // Process the generated content, including images (exact match to working code)
            $imageUrl = null;
            foreach ($result['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    Log::info("Generated text content", ['text' => $part['text']]);
                } elseif (isset($part['inlineData'])) {
                    $mimeType = $part['inlineData']['mimeType'];
                    $imageData = base64_decode($part['inlineData']['data']); // Decode as in working code
                    
                    Log::info("Found image data", [
                        'mime_type' => $mimeType,
                        'data_length' => strlen($imageData)
                    ]);
                    
                    // Create data URL with decoded data
                    $imageUrl = "data:{$mimeType};base64," . base64_encode($imageData);
                    break; // Use the first image found
                }
            }
            
            if (!$imageUrl) {
                Log::error("No image data found in response", [
                    'response_data' => $result,
                    'description' => $description
                ]);
                throw new \Exception('No image data found in Gemini API response');
            }
            
            Log::info("Successfully generated image with Gemini", [
                'image_url_length' => strlen($imageUrl),
                'description' => $description,
                'prompt_used' => $prompt
            ]);

            return [
                'image_url' => $imageUrl,
                'prompt' => $prompt
            ];

        } catch (\Exception $e) {
            Log::error('GeminiService::generateThumbnailImage error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'description' => $description
            ]);
            
            // Re-throw the exception to let the caller handle it
            throw new \Exception('Gemini API failed: ' . $e->getMessage());
        }
    }

    /**
     * Get a visualizable scene from description
     *
     * @param string $description The description to check
     * @return string The visualizable scene description
     * @throws \Exception
     */
    public function getVisualizableScene(string $description): string
    {
        Log::info("GeminiService::getVisualizableScene started", [
            'description' => $description,
            'description_length' => strlen($description)
        ]);

        if (empty($this->apiKey) || $this->apiKey === 'your-gemini-api-key-here') {
            Log::error("Google Gemini API key not configured properly");
            throw new \Exception('Google Gemini API key not configured');
        }

        // Load visualization check prompt from database
        $prompt = $this->promptService->getPromptWithVariables('visualization_check', 
            ['description' => $description]);
        
        if (!$prompt) {
            throw new \Exception('Visualization check prompt not found in database');
        }
        
        Log::info("Visualization check prompt created", [
            'original_description' => $description,
            'prompt' => $prompt,
            'prompt_length' => strlen($prompt)
        ]);

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

            $data = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 200
                ]
            ];

            Log::info("Making HTTP request to Gemini API for visualization check", [
                'url' => $url,
                'model' => $this->model,
                'timeout' => 60
            ]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($url, $data);

            Log::info("Received response from Gemini API", [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_size' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error("Gemini API request failed", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'headers' => $response->headers(),
                    'description' => $description
                ]);
                throw new \Exception('Gemini API request failed: ' . $response->body());
            }

            $result = $response->json();
            $visualizableScene = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');
            
            if (empty($visualizableScene)) {
                Log::error("Empty response from Gemini API", [
                    'response_data' => $result,
                    'description' => $description
                ]);
                throw new \Exception('Empty response from Gemini API');
            }

            Log::info("Successfully got visualizable scene", [
                'original_description' => $description,
                'visualizable_scene' => $visualizableScene,
                'scene_length' => strlen($visualizableScene)
            ]);

            return $visualizableScene;

        } catch (\Exception $e) {
            Log::error('GeminiService::getVisualizableScene error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'description' => $description
            ]);
            throw $e;
        }
    }

    /**
     * Modify an existing thumbnail image using a prompt
     *
     * @param string $imageUrl The existing image URL or data URL
     * @param string $modificationPrompt The prompt describing the changes to make
     * @return array Array with 'image_url' and 'prompt' keys
     * @throws \Exception
     */
    public function modifyThumbnailImage(string $imageUrl, string $modificationPrompt): array
    {
        Log::info("GeminiService::modifyThumbnailImage started", [
            'image_url_length' => strlen($imageUrl),
            'modification_prompt' => $modificationPrompt,
            'modification_prompt_length' => strlen($modificationPrompt),
            'api_key' => $this->apiKey,
            'model' => $this->model
        ]);

        if (empty($this->apiKey) || $this->apiKey === 'your-gemini-api-key-here') {
            Log::error("Google Gemini API key not configured properly");
            throw new \Exception('Google Gemini API key not configured');
        }

        // Load the image data
        $imageData = null;
        $mimeType = 'image/png';
        
        // Check if it's a data URL
        if (strpos($imageUrl, 'data:') === 0) {
            // Extract MIME type and base64 data from data URL
            preg_match('/data:([^;]+);base64,(.+)/', $imageUrl, $matches);
            if (count($matches) === 3) {
                $mimeType = $matches[1];
                $imageData = base64_decode($matches[2]);
            } else {
                throw new \Exception('Invalid data URL format');
            }
        } else {
            // Try to load from storage or URL
            // Check if it's a storage path
            if (Storage::disk('public')->exists($imageUrl)) {
                $imageData = Storage::disk('public')->get($imageUrl);
                $mimeType = mime_content_type(Storage::disk('public')->path($imageUrl)) ?: 'image/png';
            } else {
                // Try to download from URL
                try {
                    $response = Http::timeout(30)->get($imageUrl);
                    if ($response->successful()) {
                        $imageData = $response->body();
                        $contentType = $response->header('Content-Type');
                        if ($contentType) {
                            $mimeType = $contentType;
                        }
                    } else {
                        throw new \Exception('Failed to download image from URL');
                    }
                } catch (\Exception $e) {
                    throw new \Exception('Failed to load image: ' . $e->getMessage());
                }
            }
        }

        if (!$imageData) {
            throw new \Exception('Failed to load image data');
        }

        $imageBase64 = base64_encode($imageData);
        
        Log::info("Image loaded and encoded", [
            'image_size' => strlen($imageData),
            'base64_size' => strlen($imageBase64),
            'mime_type' => $mimeType
        ]);

        // Build the prompt with the modification request
        $fullPrompt = "Modify this image according to the following instructions: {$modificationPrompt}";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $fullPrompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $imageBase64
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'] // Required for image generation
            ]
        ];

        Log::info("Making HTTP request to Google Gemini API for image modification", [
            'url' => $url,
            'model' => $this->model,
            'modification_prompt' => $modificationPrompt
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($url, $data);

            Log::info("Received response from Gemini API", [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_size' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error("Gemini API request failed", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'headers' => $response->headers(),
                    'modification_prompt' => $modificationPrompt
                ]);
                throw new \Exception('Gemini API request failed: ' . $response->body());
            }

            $result = $response->json();
            
            Log::info("Gemini response JSON parsed", [
                'has_candidates' => isset($result['candidates']),
                'candidates_count' => isset($result['candidates']) ? count($result['candidates']) : 0,
                'response_structure' => array_keys($result)
            ]);
            
            // Process the generated content, including images
            $newImageUrl = null;
            foreach ($result['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    Log::info("Generated text content", ['text' => $part['text']]);
                } elseif (isset($part['inlineData'])) {
                    $partMimeType = $part['inlineData']['mimeType'];
                    $partImageData = base64_decode($part['inlineData']['data']);
                    
                    Log::info("Found image data", [
                        'mime_type' => $partMimeType,
                        'data_length' => strlen($partImageData)
                    ]);
                    
                    // Create data URL with decoded data
                    $newImageUrl = "data:{$partMimeType};base64," . base64_encode($partImageData);
                    break; // Use the first image found
                }
            }
            
            if (!$newImageUrl) {
                Log::error("No image data found in response", [
                    'response_data' => $result,
                    'modification_prompt' => $modificationPrompt
                ]);
                throw new \Exception('No image data found in Gemini API response');
            }
            
            Log::info("Successfully modified image with Gemini", [
                'image_url_length' => strlen($newImageUrl),
                'modification_prompt' => $modificationPrompt
            ]);

            return [
                'image_url' => $newImageUrl,
                'prompt' => $modificationPrompt
            ];

        } catch (\Exception $e) {
            Log::error('GeminiService::modifyThumbnailImage error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'modification_prompt' => $modificationPrompt
            ]);
            
            // Re-throw the exception to let the caller handle it
            throw new \Exception('Gemini API failed: ' . $e->getMessage());
        }
    }

    /**
     * Load a prompt template from file and replace placeholders
     *
     * @param string $filename The prompt filename (relative to app/Prompts/)
     * @param array $replacements Key-value pairs for placeholder replacement
     * @return string The processed prompt
     * @throws \Exception
     */
    private function loadPrompt(string $filename, array $replacements = []): string
    {
        $promptPath = app_path("Prompts/{$filename}");
        
        if (!file_exists($promptPath)) {
            throw new \Exception("Prompt file not found: {$promptPath}");
        }
        
        $template = file_get_contents($promptPath);
        
        // Replace placeholders in the template
        foreach ($replacements as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        
        return $template;
    }

}
