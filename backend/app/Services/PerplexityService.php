<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PerplexityService
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.perplexity.api_key');
        $this->apiUrl = config('services.perplexity.api_url', 'https://api.perplexity.ai/chat/completions');
        $this->model = config('services.perplexity.model', 'sonar-reasoning-pro');
    }

    /**
     * Perform deep research on a topic using Perplexity API.
     *
     * @param string $topic
     * @param string|null $title Optional title for better context
     * @return array
     * @throws \Exception
     */
    public function performResearch(string $topic, ?string $title = null): array
    {
        if (empty($this->apiKey)) {
            Log::error("Perplexity API key not configured");
            throw new \Exception('Perplexity API key not configured');
        }

        $systemPrompt = "You are an expert research assistant for a YouTube video production team.
- Role: Deep Research Analyst.
- Tone: Insightful, data-driven, and engaging.
- Formatting: Strict Markdown. Use headers (##) for sections.
- Requirement: You MUST include a 'Shock Score' (1-10) for key facts, stats, and angles where requested.
- Requirement: Use bullet points for readability.
- Requirement: Keep the total output under 1300 words.
- Goal: Provide a comprehensive breakdown that can be directly used to write a high-retention video script.";

        $context = $title ? "Title: {$title}\n" : "";
        $userPrompt = "{$context}Topic: {$topic}

Research Objective: Provide a detailed structural breakdown for a YouTube video.

Output Structure (Follow Strict Order):

1. **Executive Summary**: 
   - A high-level overview of the topic.
   - Core thesis or 'hook' of the story.

2. **Key Context**:
   - Background info and timeline.
   - Who, what, where, when.

3. **Key Facts**:
   - List 5-10 specific data points/facts.
   - **Crucial**: Assign a (Shock Score X/10) to each fact based on how surprising it is.

4. **Interesting Stats & Findings**:
   - Deep dive into specific numbers, revenue, growth, etc.
   - Include (Shock Score X/10) for each.
   - TOP 5 Stats & Findings.

5. **Common Misconception vs Reality**:
   - Format: 'Misconception: [X] vs Reality: [Y]'.
   - Include (Shock Score X/10).
   - 3-5 Misconception vs Reality.

6. **Analogies & Simple Comparisons**:
   - Use metaphors to explain complex concepts (e.g., 'It's like X but for Y').

7. **How It Works (ELI5)**:
   - Simple, step-by-step explanation for a 5-year-old.

8. **Real-World Use Cases Today**:
   - Examples of this topic in action right now.

9. **Major Trends**:
   - Broader market context and direction.

10. **Future Implications**:
    - Optimistic Scenario
    - Realistic Scenario
    - Skeptical Scenario

11. **Potential Concerns/Downsides**:
    - Risks, challenges, or negative aspects.

12. **Why It Matters (ELI5)**:
    - The 'So What?'. Why should the viewer care?

13. **Video Angles**:
    - 3-5 potential video titles/angles.
    - Include (Shock Score X/10).

14. **Contrast Moments**:
    - Format: 'Most believe [X], the twist is [Y]'.

15. **Open Questions**:
    - Thought-provoking questions left unanswered.

16. **Sources**:
    - List key sources used with a brief note on what they provided.
";

        Log::info("PerplexityService::performResearch started", [
            'topic' => $topic,
            'model' => $this->model
        ]);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(config('services.perplexity.timeout', 600))
                ->post($this->apiUrl, [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt
                        ]
                    ],
                    'temperature' => 0.1, // Low temperature for factual consistency
                ]);
         

            if (!$response->successful()) {
                Log::error("Perplexity API request failed", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception('Perplexity API request failed: ' . $response->body());
            }

            $data = $response->json();
            
            // Extract content
            $content = $data['choices'][0]['message']['content'] ?? '';
            
            // Extract citations (Perplexity returns them in a top-level 'citations' array)
            $citations = $data['citations'] ?? [];
            
            // Extract Usage and Cost
            $usage = $data['usage'] ?? [];
            $cost = $usage['cost'] ?? [];
            $totalCost = $cost['total_cost'] ?? 0;

            // Format citations for our frontend (title, url)
            // Perplexity just gives URLs, so we'll use domain as title if we can't get more
            $formattedReferences = [];
            foreach ($citations as $url) {
                $domain = parse_url($url, PHP_URL_HOST);
                $formattedReferences[] = [
                    'title' => $domain ?: 'Reference',
                    'url' => $url
                ];
            }

            Log::info("Perplexity Research completed", [
                'topic' => $topic,
                'model' => $this->model,
                'citations_count' => count($formattedReferences),
                'content_length' => strlen($content),
                'tokens' => [
                    'prompt' => $usage['prompt_tokens'] ?? 0,
                    'completion' => $usage['completion_tokens'] ?? 0,
                    'total' => $usage['total_tokens'] ?? 0,
                ],
                'cost' => $cost, // Log full breakdown
                'total_cost_usd' => $totalCost
            ]);

            return [
                'body' => $content,
                'references' => $formattedReferences,
                'usage' => $usage,
                'cost' => $cost 
            ];

        } catch (\Exception $e) {
            Log::error('PerplexityService error: ' . $e->getMessage());
            throw $e;
        }
    }
}
