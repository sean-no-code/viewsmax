<?php

namespace App\Services;

use App\Services\Contracts\ThumbnailServiceInterface;
use App\Services\PromptService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements ThumbnailServiceInterface
{
    private string $apiKey;
    private string $chatApiUrl;
    private string $imageApiUrl;
    private PromptService $promptService;

    public function __construct(PromptService $promptService)
    {
        $this->apiKey = config('services.openai.api_key');
        $this->chatApiUrl = config('services.openai.chat_api_url', 'https://api.openai.com/v1/chat/completions');
        $this->imageApiUrl = config('services.openai.image_api_url', 'https://api.openai.com/v1/images/generations');
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
     * Generate a video script using ChatGPT
     *
     * @param string $project The video project/topic
     * @return array
     * @throws \Exception
     */
    public function generateScript(string $project): array
    {
        try {
            $prompt = $this->buildScriptPrompt($project);
            
            // Use model from config, default to gpt-4-turbo for larger context window (128k tokens)
            $model = config('services.openai.model', 'gpt-4-turbo');
            
            // Rough estimate: ~4 chars per token
            $estimatedTokens = round(strlen($prompt) / 4);
            
            Log::info('Generating script with OpenAI', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
                'estimated_tokens' => $estimatedTokens
            ]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->chatApiUrl, [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 2000
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? '';
                
                return $this->parseScriptResponse($content);
            } else {
                Log::error('OpenAI API error: ' . $response->body());
                throw new \Exception('Failed to generate script: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('Script generation error: ' . $e->getMessage());
            throw new \Exception('Failed to generate script: ' . $e->getMessage());
        }
    }

    /**
     * Check if a description is visualizable and get a visualizable scene
     *
     * @param string $description The description to check
     * @return string The visualizable scene description
     * @throws \Exception
     */
    public function getVisualizableScene(string $description): string
    {
        Log::info("OpenAIService::getVisualizableScene started", [
            'description' => $description,
            'description_length' => strlen($description)
        ]);

        if (empty($this->apiKey) || $this->apiKey === 'your-openai-api-key-here') {
            Log::error("OpenAI API key not configured properly");
            throw new \Exception('OpenAI API key not configured');
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
            Log::info("Making HTTP request to OpenAI Chat API for visualization check", [
                'url' => $this->chatApiUrl,
                'model' => 'gpt-4',
                'timeout' => 60
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->chatApiUrl, [
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 200
            ]);

            Log::info("Received response from OpenAI Chat API", [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_size' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error("OpenAI Chat API request failed", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'headers' => $response->headers(),
                    'description' => $description
                ]);
                throw new \Exception('OpenAI Chat API request failed: ' . $response->body());
            }

            $data = $response->json();
            $visualizableScene = trim($data['choices'][0]['message']['content'] ?? '');
            
            if (empty($visualizableScene)) {
                Log::error("Empty response from OpenAI Chat API", [
                    'response_data' => $data,
                    'description' => $description
                ]);
                throw new \Exception('Empty response from OpenAI Chat API');
            }

            Log::info("Successfully got visualizable scene", [
                'original_description' => $description,
                'visualizable_scene' => $visualizableScene,
                'scene_length' => strlen($visualizableScene)
            ]);

            return $visualizableScene;

        } catch (\Exception $e) {
            Log::error('OpenAIService::getVisualizableScene error', [
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
     * Generate a thumbnail image using DALL-E
     *
     * @param string $description The description to base thumbnail on
     * @param \App\Models\Thumbnail|null $thumbnail The thumbnail model with prompt references
     * @return array Array with 'image_url' and 'prompt' keys
     * @throws \Exception
     */
    public function generateThumbnailImage(string $description, ?\App\Models\Thumbnail $thumbnail = null): array
    {
        Log::info("OpenAIService::generateThumbnailImage started", [
            'description' => $description,
            'description_length' => strlen($description)
        ]);

        if (empty($this->apiKey) || $this->apiKey === 'your-openai-api-key-here') {
            Log::error("OpenAI API key not configured properly");
            throw new \Exception('OpenAI API key not configured');
        }

        // First, check if the description is visualizable and get a visualizable scene
        Log::info("Checking if description is visualizable", [
            'original_description' => $description
        ]);
        
        // Combine all prompts from the thumbnail
        $enhancedPrompt = $this->buildCombinedPrompt($thumbnail, $description);
        
        if (!$enhancedPrompt) {
            throw new \Exception('Failed to build combined prompt');
        }
        
        Log::info("Enhanced prompt created for DALL-E", [
            'original_description' => $description,
            'enhanced_prompt' => $enhancedPrompt,
            'enhanced_prompt_length' => strlen($enhancedPrompt)
        ]);

        try {
            Log::info("Making HTTP request to OpenAI DALL-E API", [
                'url' => $this->imageApiUrl,
                'model' => 'dall-e-3',
                'size' => '1792x1024',
                'quality' => 'hd',
                'style' => 'vivid',
                'timeout' => 120
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($this->imageApiUrl, [
                'model' => 'dall-e-3',
                'prompt' => $enhancedPrompt,
                'n' => 1,
                'size' => '1792x1024', // 16:9 aspect ratio for YouTube thumbnails
                'quality' => 'hd',
                'style' => 'vivid'
            ]);

            Log::info("Received response from DALL-E API", [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_size' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error("DALL-E API request failed", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'headers' => $response->headers(),
                    'description' => $description
                ]);
                throw new \Exception('DALL-E API request failed: ' . $response->body());
            }

            Log::info("Parsing DALL-E response JSON", [
                'response_size' => strlen($response->body())
            ]);

            $data = $response->json();
            
            Log::info("DALL-E response JSON parsed", [
                'has_data' => isset($data['data']),
                'data_count' => isset($data['data']) ? count($data['data']) : 0,
                'has_first_url' => isset($data['data'][0]['url']),
                'response_structure' => array_keys($data)
            ]);
            
            if (!isset($data['data'][0]['url'])) {
                Log::error("Invalid response structure from DALL-E API", [
                    'response_data' => $data,
                    'description' => $description
                ]);
                throw new \Exception('Invalid response from DALL-E API');
            }

            $imageUrl = $data['data'][0]['url'];
            
            Log::info("Successfully generated image with DALL-E", [
                'image_url' => $imageUrl,
                'description' => $description,
                'url_length' => strlen($imageUrl)
            ]);

            return [
                'image_url' => $imageUrl,
                'prompt' => $enhancedPrompt
            ];

        } catch (\Exception $e) {
            Log::error('OpenAIService::generateThumbnailImage error', [
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
     * Build the prompt for ChatGPT script generation
     *
     * @param string $project
     * @return string
     */
    private function buildScriptPrompt(string $project): string
    {
        return $this->loadPrompt('Scripts/script.txt', ['project' => $project]);
    }

    /**
     * Parse the ChatGPT response into structured data
     *
     * @param string $content
     * @return array
     */
    private function parseScriptResponse(string $content): array
    {
        // Clean up the response - remove any extra whitespace and formatting
        $scriptText = trim($content);
        
        // Calculate the length of the script (word count / 130)
        $wordCount = str_word_count($scriptText);
        $scriptLength = round($wordCount / 130);
        
        return [
            'text' => $scriptText,
            'length' => $scriptLength
        ];
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

    /**
     * Generate viral titles using ChatGPT based on a project
     *
     * @param string $project The project to generate titles for
     * @param int $number The number of titles to generate (default: 5)
     * @return array Array of generated titles with metadata
     * @throws \Exception
     */
    public function generateTitles(string $project, int $number = 5): array
    {
        // Check if API key is configured
        if (empty($this->apiKey) || $this->apiKey === 'your-openai-api-key-here') {
            throw new \Exception('OpenAI API key not configured');
        }

        // Generate titles with ChatGPT
        $prompt = "You are a viral title expert. Generate {$number} highly viral, engaging titles based on this description. Titles should be under 50 characters and use proven viral patterns.

Description: \"{$project}\"

Requirements:
1. Use emotional triggers and power words
2. Create curiosity gaps and click-worthy hooks
3. Include numbers, questions, or bold statements when appropriate
4. Make them engaging and shareable
5. Keep under 50 characters each
6. Refactor this title keeping the core project

Return exactly {$number} titles, numbered 1-{$number}, one per line. Only return the titles, nothing else.

Example format:
1. Title 1
2. Title 2
3. Title 3
4. Title 4
5. Title 5";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post($this->chatApiUrl, [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 500,
            'temperature' => 0.8
        ]);

        if (!$response->successful()) {
            throw new \Exception('ChatGPT API request failed: ' . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response from ChatGPT API');
        }

        // Parse the response to extract individual titles
        $responseText = trim($data['choices'][0]['message']['content']);
        $lines = explode("\n", $responseText);
        
        $titles = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines
            if (empty($line)) continue;
            
            // Extract title after the number and period
            if (preg_match('/^\d+\.\s*(.+)$/', $line, $matches)) {
                $titles[] = [
                    'title' => trim($matches[1]),
                    'virality_score' => 100,
                    'generated' => true
                ];
            }
        }

        return $titles;
    }
}
