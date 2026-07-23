<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicService
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->apiUrl = config('services.anthropic.api_url', 'https://api.anthropic.com/v1/messages');
        $this->model = config('services.anthropic.model', 'claude-3-5-sonnet-20241022');
    }

    /**
     * Generate a video script using Claude
     *
     * @param string $project The video project/topic
     * @return array
     * @throws \Exception
     */
    public function generateScript(string $project, ?int $maxTokens = null): array
    {
        // Check if we're in production - if not, return fake response to avoid API costs
        if (config('app.env') !== 'production') {
            Log::info('Non-production environment detected - returning fake script response to avoid API costs', [
                'environment' => config('app.env'),
                'project_length' => strlen($project)
            ]);
            
            // Extract a topic from the project/prompt for the fake response
            $topic = $this->extractTopicFromPrompt($project);
            
            $fakeScript = "How to Master {$topic} - Complete Guide

Did you know that 90% of people get {$topic} completely wrong? In the next 5 minutes, I'll show you the exact method that works.

Here are the key points we'll cover:
1. The #1 mistake everyone makes with {$topic}
2. The step-by-step process that actually works
3. Real examples and case studies
4. Common pitfalls to avoid
5. How to measure your success

Let's dive in. First, let's talk about the biggest mistake people make when it comes to {$topic}. Most people think they can just jump in without understanding the fundamentals, but that's where they go wrong.

The correct approach starts with understanding the basics. You need to build a solid foundation before you can master the advanced techniques. This means taking the time to learn the core principles, practicing consistently, and getting feedback from those who have already succeeded.

Now, let's look at some real-world examples. I've seen countless people transform their approach to {$topic} by following this exact process. They started with the fundamentals, practiced daily, and gradually built up their skills.

But here's what most people don't realize - there are common pitfalls that can derail your progress. The biggest one is trying to do too much too fast. You need to pace yourself and focus on quality over quantity.

Finally, how do you measure success? The key is to set clear, measurable goals and track your progress over time. Don't just focus on the end result - celebrate the small wins along the way.

Now you have everything you need to master {$topic}. If this helped you, hit subscribe for more actionable content like this. And remember - consistency is key. Keep practicing, stay focused, and you'll see results.";
            
            return $this->parseScriptResponse($fakeScript);
        }
        
        if (empty($this->apiKey) || $this->apiKey === 'your-anthropic-api-key-here') {
            Log::error("Anthropic API key not configured properly");
            throw new \Exception('Anthropic API key not configured');
        }

        try {
            $prompt = $this->buildScriptPrompt($project);
            
            // Rough estimate: ~4 chars per token
            $estimatedTokens = round(strlen($prompt) / 4);
            
            Log::info('Generating script with Anthropic', [
                'model' => $this->model,
                'prompt_length' => strlen($prompt),
                'estimated_tokens' => $estimatedTokens
            ]);
            
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(config('services.anthropic.timeout', 600))->post($this->apiUrl, [
                'model' => $this->model,
                'max_tokens' => $maxTokens ?? (int) config('services.anthropic.max_output_tokens', 8192),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['content'][0]['text'] ?? '';
                
                if (empty($content)) {
                    Log::error('Anthropic API returned empty content', [
                        'response' => $data
                    ]);
                    throw new \Exception('Anthropic API returned empty content');
                }
                
                return $this->parseScriptResponse($content);
            } else {
                $errorBody = $response->body();
                $errorData = $response->json();
                
                Log::error('Anthropic API error', [
                    'status_code' => $response->status(),
                    'response_body' => $errorBody,
                    'response_json' => $errorData,
                    'model' => $this->model,
                    'api_url' => $this->apiUrl
                ]);
                
                throw new \Exception('Failed to generate script: ' . $errorBody);
            }

        } catch (\Exception $e) {
            Log::error('Script generation error: ' . $e->getMessage());
            throw new \Exception('Failed to generate script: ' . $e->getMessage());
        }
    }

    /**
     * Build the prompt for Claude script generation
     *
     * @param string $project
     * @return string
     */
    private function buildScriptPrompt(string $project): string
    {
        $prompt = $this->loadPrompt('Scripts/script.txt', ['project' => $project]);
        
        return $prompt;
    }

    /**
     * Parse the Claude response into structured data
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
     * Update an existing script using Claude
     *
     * @param string $updatePrompt The prompt containing the current script and update instructions
     * @return array
     * @throws \Exception
     */
    public function updateScript(string $updatePrompt): array
    {
        // Check if we're in production - if not, return fake response to avoid API costs
        if (config('app.env') !== 'production') {
            Log::info('Non-production environment detected - returning fake script update response to avoid API costs', [
                'environment' => config('app.env'),
                'prompt_length' => strlen($updatePrompt)
            ]);
            
            // Extract a topic from the prompt for the fake response
            $topic = $this->extractTopicFromPrompt($updatePrompt);
            
            $fakeScript = "Updated Guide to {$topic}

This is an updated version of the script based on your modifications. The content has been revised to better match your requirements while maintaining the original structure and style.

Key improvements in this updated version:
- Enhanced clarity on the main concepts
- Better examples that illustrate the points
- Improved flow and transitions
- More actionable advice

The updated script maintains the engaging tone and structure you requested, while incorporating all the changes you specified.";
            
            return $this->parseScriptResponse($fakeScript);
        }
        
        if (empty($this->apiKey) || $this->apiKey === 'your-anthropic-api-key-here') {
            Log::error("Anthropic API key not configured properly");
            throw new \Exception('Anthropic API key not configured');
        }

        try {
            // Rough estimate: ~4 chars per token
            $estimatedTokens = round(strlen($updatePrompt) / 4);
            
            Log::info('Updating script with Anthropic', [
                'model' => $this->model,
                'prompt_length' => strlen($updatePrompt),
                'estimated_tokens' => $estimatedTokens
            ]);
            
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(config('services.anthropic.timeout', 600))->post($this->apiUrl, [
                'model' => $this->model,
                'max_tokens' => (int) config('services.anthropic.max_output_tokens', 8192),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $updatePrompt
                    ]
                ],
                'temperature' => 0.7
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['content'][0]['text'] ?? '';
                
                if (empty($content)) {
                    Log::error('Anthropic API returned empty content', [
                        'response' => $data
                    ]);
                    throw new \Exception('Anthropic API returned empty content');
                }
                
                return $this->parseScriptResponse($content);
            } else {
                $errorBody = $response->body();
                $errorData = $response->json();
                
                Log::error('Anthropic API error during script update', [
                    'status_code' => $response->status(),
                    'response_body' => $errorBody,
                    'response_json' => $errorData,
                    'model' => $this->model,
                    'api_url' => $this->apiUrl
                ]);
                
                throw new \Exception('Failed to update script: ' . $errorBody);
            }

        } catch (\Exception $e) {
            Log::error('Script update error: ' . $e->getMessage());
            throw new \Exception('Failed to update script: ' . $e->getMessage());
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

    /**
     * Extract a topic/keyword from a prompt for use in fake responses
     *
     * @param string $prompt
     * @return string
     */
    private function extractTopicFromPrompt(string $prompt): string
    {
        // Try to extract a meaningful topic from the prompt
        // Look for common patterns like "about X", "on X", "for X", etc.
        $patterns = [
            '/about\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/',
            '/on\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/',
            '/for\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/',
            '/topic[:\s]+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches)) {
                return $matches[1];
            }
        }
        
        // If no pattern matches, try to get first few words
        $words = explode(' ', trim($prompt));
        $topic = implode(' ', array_slice($words, 0, 3));
        
        return $topic ?: 'this topic';
    }
}

