<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class TitleAnalyzer
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;
    private bool $useFake;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key');
        $this->apiUrl = (string) config('services.anthropic.api_url', 'https://api.anthropic.com/v1/messages');
        $this->model = (string) config('services.anthropic.model', 'claude-sonnet-4-5');
        $this->useFake = (bool) config('app.use_fake', false) || env('USE_FAKE', false);
    }

    /**
     * Get the AI-only checks criteria
     *
     * @return array
     */
    public function getAiOnlyChecks(): array
    {
        return [
            'fre' => 'Flesch Reading Ease score return score 1-10 not out of 100',
            'clarity' => 'Does the title express one simple, instantly understandable idea?',
            'stakes' => 'Are the stakes high, unusual, or extreme?',
            'curiosity_gap' => 'Does the title create tension or an unanswered question?',
            'emotional_trigger' => 'Does it evoke awe, fear, excitement, generosity, etc.?',
            'concreteness' => 'Are the words concrete and visual, not abstract?',
            'human_element' => 'Does the title involve people or relatable human experiences?',
            'scale' => 'Is the situation extreme, large-scale, or beyond normal?',
            'visualizability' => 'Can the viewer imagine the thumbnail from reading the title?',
            'specificity' => 'Does it use numbers or specific items to add clarity?',
            'no_cleverness' => 'Does it avoid metaphors, wordplay, or clever phrasing?',
        ];
    }

    /**
     * Build the prompt for title analysis
     *
     * @param string $title
     * @return string
     */
    private function buildPrompt(string $title): string
    {
        $checks = $this->getAiOnlyChecks();
        
        $prompt = "Analyze the following video title and score it on each of these criteria from 1-10:\n\n";
        $prompt .= "Title: \"{$title}\"\n\n";
        $prompt .= "Criteria:\n";
        
        foreach ($checks as $key => $description) {
            $prompt .= "- {$key}: {$description}\n";
        }
        
        $prompt .= "\n";
        $prompt .= "Return ONLY a valid JSON object with the following structure, using the exact keys above:\n";
        $prompt .= "{\n";
        foreach (array_keys($checks) as $key) {
            $prompt .= "  \"{$key}\": <score 1-10>,\n";
        }
        $prompt = rtrim($prompt, ",\n") . "\n";
        $prompt .= "}\n\n";
        $prompt .= "Do not include any explanation, only return the JSON object.";
        
        return $prompt;
    }

    /**
     * Analyze a title using Claude AI
     *
     * @param string $title
     * @return array Array of scores keyed by criteria name
     * @throws \Exception
     */
    public function analyzeTitle(string $title): array
    {
        Log::info("TitleAnalyzer::analyzeTitle started", [
            'title' => $title,
            'title_length' => strlen($title),
            'api_key' => $this->apiKey ? 'set' : 'not set',
            'model' => $this->model,
            'use_fake' => $this->useFake
        ]);

        if ($this->useFake) {
            Log::info("Using fake mode for title analysis");
            sleep(10); // 10 seconds delay to simulate processing
            //  throw new \Exception('Fake mode for title analysis');
            return $this->generateFakeScores();
        }

        if (empty($this->apiKey) || $this->apiKey === 'your-anthropic-api-key-here') {
            Log::error("Anthropic API key not configured properly");
            throw new \Exception('Anthropic API key not configured');
        }

        if (empty($title)) {
            throw new \Exception('Title cannot be empty');
        }

        $prompt = $this->buildPrompt($title);

        Log::info("Title analysis prompt built", [
            'prompt_length' => strlen($prompt),
            'title' => $title
        ]);

        Log::debug($prompt);

        try {
            Log::info("Making HTTP request to Anthropic API for title analysis", [
                'url' => $this->apiUrl,
                'model' => $this->model,
                'title' => $title
            ]);

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->apiUrl, [
                'model' => $this->model,
                'max_tokens' => 500,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3
            ]);

            Log::info("Received response from Anthropic API", [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'response_size' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error("Anthropic API request failed", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'headers' => $response->headers(),
                    'title' => $title
                ]);
                throw new \Exception('Anthropic API request failed: ' . $response->body());
            }

            $result = $response->json();
            
            Log::info("Anthropic response JSON parsed", [
                'has_content' => isset($result['content']),
                'content_count' => isset($result['content']) ? count($result['content']) : 0
            ]);

            if (!isset($result['content'][0]['text'])) {
                Log::error("No text content in Anthropic API response", [
                    'response_data' => $result,
                    'title' => $title
                ]);
                throw new \Exception('No text content in Anthropic API response');
            }

            $responseText = trim($result['content'][0]['text']);
            
            // Try to extract JSON from the response (in case it's wrapped in markdown or other text)
            $jsonMatch = [];
            if (preg_match('/\{[^}]+\}/s', $responseText, $jsonMatch)) {
                $responseText = $jsonMatch[0];
            }

            $scores = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse JSON from Anthropic response", [
                    'json_error' => json_last_error_msg(),
                    'response_text' => $responseText,
                    'title' => $title
                ]);
                throw new \Exception('Failed to parse JSON from Anthropic response: ' . json_last_error_msg());
            }

            // Validate that all required keys are present and scores are 1-10
            $requiredKeys = array_keys($this->getAiOnlyChecks());
            $validatedScores = [];

            foreach ($requiredKeys as $key) {
                if (!isset($scores[$key])) {
                    Log::warning("Missing score for key: {$key}", [
                        'title' => $title,
                        'available_keys' => array_keys($scores)
                    ]);
                    $validatedScores[$key] = 0; // Default to 0 if missing
                } else {
                    $score = (int) $scores[$key];
                    // Clamp score to 1-10 range
                    $validatedScores[$key] = max(1, min(10, $score));
                }
            }

            Log::info("Successfully analyzed title", [
                'title' => $title,
                'scores' => $validatedScores
            ]);

            return $validatedScores;

        } catch (\Exception $e) {
            Log::error('TitleAnalyzer::analyzeTitle error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'title' => $title
            ]);
            
            throw new \Exception('Title analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate fake scores for development
     *
     * @return array
     */
    private function generateFakeScores(): array
    {
        Log::info("Generating fake title scores");

        $checks = $this->getAiOnlyChecks();
        $fakeScores = [];

        foreach ($checks as $key => $description) {
            $fakeScores[$key] = rand(4, 9); // Random score between 4-9 for realistic variation
        }

        Log::info("Generated fake scores", ['score_count' => count($fakeScores)]);

        return $fakeScores;
    }
}

