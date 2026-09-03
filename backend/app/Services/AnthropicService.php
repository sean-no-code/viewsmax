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
     * Canned responses in local/development (avoids API costs) and testing (the
     * suite must never hit the real API); real Claude calls everywhere else.
     */
    private function usesCannedResponses(): bool
    {
        return in_array(config('app.env'), ['local', 'development', 'testing'], true);
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
        if ($this->usesCannedResponses()) {
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
        if ($this->usesCannedResponses()) {
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
     * Generate a structured breakdown of an outlier video (idea / hook /
     * storytelling structure / visual layout / annotated transcript).
     *
     * @param array $video   Meta: title, channel, platform, duration_seconds, views, like_count, comment_count
     * @param string $transcriptText Full transcript text
     * @param array $segments Timestamped transcript segments ([{start, text}, ...]) when available
     * @return array The decoded breakdown payload
     * @throws \Exception
     */
    public function generateOutlierBreakdown(array $video, string $transcriptText, array $segments = []): array
    {
        // Non-production: return a fake breakdown to avoid API costs (same pattern as generateScript).
        if ($this->usesCannedResponses()) {
            Log::info('Non-production environment detected - returning fake outlier breakdown', [
                'environment' => config('app.env'),
                'title' => $video['title'] ?? null,
            ]);

            return $this->fakeBreakdown($video, $transcriptText);
        }

        if (empty($this->apiKey) || $this->apiKey === 'your-anthropic-api-key-here') {
            Log::error('Anthropic API key not configured properly');
            throw new \Exception('Anthropic API key not configured');
        }

        $segmentLines = collect($segments)
            ->map(function ($s) {
                $start = (float) ($s['start'] ?? 0);
                $stamp = sprintf('%d:%02d', floor($start / 60), (int) $start % 60);

                return "[{$stamp}] ".($s['text'] ?? '');
            })
            ->implode("\n");

        $prompt = $this->loadPrompt('Outliers/breakdown.txt', [
            'title' => $video['title'] ?? '',
            'channel' => $video['channel'] ?? 'Unknown',
            'platform' => $video['platform'] ?? 'youtube',
            'duration_seconds' => (string) ($video['duration_seconds'] ?? 'unknown'),
            'views' => (string) ($video['views'] ?? 'unknown'),
            'transcript' => $segmentLines !== '' ? $segmentLines : $transcriptText,
        ]);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(config('services.anthropic.timeout', 600))->post($this->apiUrl, [
            'model' => $this->model,
            'max_tokens' => (int) config('services.anthropic.max_output_tokens', 8192),
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
        ]);

        if (! $response->successful()) {
            Log::error('Anthropic API error during outlier breakdown', [
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'model' => $this->model,
            ]);
            throw new \Exception('Failed to generate breakdown: '.$response->status());
        }

        $content = $response->json()['content'][0]['text'] ?? '';

        return $this->parseBreakdownResponse($content);
    }

    /** Decode Claude's breakdown JSON (tolerating markdown code fences). */
    private function parseBreakdownResponse(string $content): array
    {
        $json = trim($content);
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $json);

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            Log::error('Could not parse breakdown response as JSON', ['content' => mb_substr($content, 0, 500)]);
            throw new \Exception('Breakdown response was not valid JSON');
        }

        foreach (['idea', 'hook', 'structure', 'visual', 'transcript'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new \Exception("Breakdown response is missing the '{$key}' section");
            }
        }

        return $payload;
    }

    /** Deterministic fake payload for local/staging (mirrors the real payload shape). */
    private function fakeBreakdown(array $video, string $transcriptText): array
    {
        $title = $video['title'] ?? 'This video';
        $firstLine = trim(mb_substr($transcriptText, 0, 120)) ?: $title;

        return [
            'idea' => [
                'topic' => $title,
                'idea_seed' => 'The thing everyone dismisses is actually a serious, high-performing subject. The joke is the way in; the receipts are the payoff.',
                'unique_angle' => 'Defends the thing viewers expect to be roasted, forcing a reaction: agree, argue, or share it at someone.',
            ],
            'hook' => [
                ['time' => '0.0s', 'line' => $firstLine, 'note' => 'Stat shock. A claim nobody expects, attached to a thing everybody has an opinion about. No greeting, no setup.'],
                ['time' => '1.4s', 'line' => 'The contradiction lands immediately.', 'note' => 'Flips the viewer\'s prior within two seconds — the comment section is pre-loaded with disagreement.'],
                ['time' => '2.6s', 'line' => 'Cut to b-roll, captions on', 'note' => 'Visual reset before second 3 — the pattern break lands where most viewers decide to swipe.'],
            ],
            'structure' => [
                'summary' => '5 beats',
                'beats' => [
                    ['label' => 'HOOK', 'start' => '0s', 'end' => '3s', 'pct' => 8, 'title' => 'Hook — stat shock', 'note' => 'Big claim in one breath. No intro, no channel branding.', 'highlight' => 'red'],
                    ['label' => 'FLIP', 'start' => '3s', 'end' => '9s', 'pct' => 16, 'title' => 'Flip — attack the prior', 'note' => 'Names the thing the viewer believes, then contradicts it directly.', 'highlight' => null],
                    ['label' => 'ESCALATION', 'start' => '9s', 'end' => '22s', 'pct' => 34, 'title' => 'Escalation — how it works', 'note' => 'Each fact raises the stakes of the opening claim instead of repeating it.', 'highlight' => null],
                    ['label' => 'RECEIPTS', 'start' => '22s', 'end' => '32s', 'pct' => 26, 'title' => 'Receipts — the numbers', 'note' => 'Proof lands after curiosity peaks, not before.', 'highlight' => null],
                    ['label' => 'PAYOFF', 'start' => '32s', 'end' => '38s', 'pct' => 16, 'title' => 'Payoff — the loop back', 'note' => 'Ends on the opening claim restated with the evidence behind it. Clean loop point for rewatches.', 'highlight' => 'volt'],
                ],
            ],
            'visual' => [
                ['title' => 'Talking head · ~40% of runtime', 'note' => 'Centered, chest-up, plain background. Face carries the hook and the payoff; b-roll carries everything in between.'],
                ['title' => 'Captions · always on', 'note' => '3–4 words per line, lower third, keyword highlighted per line.'],
                ['title' => 'Cut rate · every ~2s', 'note' => 'Angle or subject changes on every cut; never two identical clips back to back.'],
                ['title' => 'Zoom punches on numbers', 'note' => 'Every stat lands with a punch-in. The rhythm trains the viewer: zoom means "this is the point."'],
            ],
            'transcript' => [
                ['time' => '0:00', 'text' => $firstLine, 'label' => 'hook'],
                ['time' => '0:10', 'text' => 'Middle section of the fake transcript for local development.', 'label' => null],
                ['time' => '0:30', 'text' => 'Closing line that loops back to the opening claim.', 'label' => 'loop point'],
            ],
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

