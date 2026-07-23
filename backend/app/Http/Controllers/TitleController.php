<?php

namespace App\Http\Controllers;

use App\Models\Title;
use App\Services\CreditService;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TitleController extends Controller
{
    private OpenAIService $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    /**
     * Store a new title with embedding
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:1000'
        ]);

        try {
            // Get embedding from OpenAI (or use dummy for testing)
            $embedding = $this->getEmbedding($request->title);
            
            // Create title record
            $title = Title::create([
                'title' => $request->title,
                'embedding' => $embedding
            ]);

            return response()->json([
                'success' => true,
                'data' => $title,
                'message' => 'Title created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating title: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create title: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all titles with pagination
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $perPage = min($perPage, 100); // Limit to 100 per page
            $groupBy = $request->input('group_by', null);
            
            // Apply grouping if specified
            if ($groupBy) {
                switch ($groupBy) {
                    case 'date':
                        $query = Title::selectRaw('DATE(created_at) as group_date, COUNT(*) as count')
                              ->groupByRaw('DATE(created_at)')
                              ->orderBy('group_date', 'desc');
                        break;
                    case 'day':
                        $query = Title::selectRaw('EXTRACT(DAY FROM created_at) as day, COUNT(*) as count')
                              ->groupByRaw('EXTRACT(DAY FROM created_at)')
                              ->orderBy('day', 'desc');
                        break;
                    case 'month':
                        $query = Title::selectRaw('EXTRACT(MONTH FROM created_at) as month, COUNT(*) as count')
                              ->groupByRaw('EXTRACT(MONTH FROM created_at)')
                              ->orderBy('month', 'desc');
                        break;
                    case 'year':
                        $query = Title::selectRaw('EXTRACT(YEAR FROM created_at) as year, COUNT(*) as count')
                              ->groupByRaw('EXTRACT(YEAR FROM created_at)')
                              ->orderBy('year', 'desc');
                        break;
                    case 'virality':
                        $query = Title::selectRaw('ROUND(virality_score) as virality_range, COUNT(*) as count')
                              ->whereNotNull('virality_score')
                              ->groupByRaw('ROUND(virality_score)')
                              ->orderBy('virality_range', 'desc');
                        break;
                    default:
                        // Invalid group_by parameter, use default query
                        $query = Title::select('id', 'title', 'virality_score', 'created_at')
                              ->orderBy('created_at', 'desc');
                        break;
                }
            } else {
                // Default query when no grouping
                $query = Title::select('id', 'title', 'virality_score', 'created_at')
                      ->orderBy('created_at', 'desc');
            }
            
            $titles = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $titles->items(),
                'pagination' => [
                    'current_page' => $titles->currentPage(),
                    'last_page' => $titles->lastPage(),
                    'per_page' => $titles->perPage(),
                    'total' => $titles->total(),
                    'from' => $titles->firstItem(),
                    'to' => $titles->lastItem()
                ],
                'group_by' => $groupBy
            ]);

        } catch (\Exception $e) {
            Log::error('List titles failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve titles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search for similar titles using vector similarity
     */
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:1000'
        ]);

        try {
            // Get embedding for the search query
            $queryEmbedding = $this->getEmbedding($request->input('query'));
            
            // Convert array to PostgreSQL vector format
            $vectorString = '[' . implode(',', $queryEmbedding) . ']';
            
            // Perform vector similarity search
            $results = DB::select("
                SELECT id, title, embedding <-> ? AS distance
                FROM titles
                ORDER BY embedding <-> ?
                LIMIT 5
            ", [$vectorString, $vectorString]);

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => 'Search completed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error searching titles: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to search titles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate viral titles using ChatGPT based on a query
     */
    public function enhanceTitlesWithChatGPT(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:1000'
        ]);

        try {
            $generatedTitles = $this->openAIService->generateTitles($request->input('query'));
            
            // Save titles to database
            $savedTitles = [];
            foreach ($generatedTitles as $titleData) {
                $title = Title::create([
                    'title' => $titleData['title'],
                    'virality_score' => $titleData['virality_score']
                ]);
                $savedTitles[] = $title;
            }
            
            $creditService = app(CreditService::class);
            $creditService->deductCreditsForOperation($request->user(), CreditService::TITLE_GENERATION_OPERATION);
            
            return response()->json([
                'success' => true,
                'data' => $savedTitles,
                'message' => 'Titles generated and saved successfully with ChatGPT'
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating titles: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate titles: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get embedding from OpenAI
     */
    private function getEmbedding(string $text): array
    {
        $apiKey = config('services.openai.api_key');
        
        // If no API key is set, return a dummy embedding for testing
        if (empty($apiKey) || $apiKey === 'your-openai-api-key-here') {
            return array_fill(0, 3072, 0.0);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'input' => $text,
            'model' => config('services.openai.model')
        ]);

        if (!$response->successful()) {
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['data'][0]['embedding'])) {
            throw new \Exception('Invalid response from OpenAI API');
        }

        return $data['data'][0]['embedding'];
    }



    /**
     * Get ChatGPT enhanced titles in batch
     */
    private function getChatGPTEnhancedTitlesBatch(string $inputQuery, array $originalTitles, string $apiKey): array
    {
        // Create a numbered list of titles for the prompt
        $titlesList = '';
        foreach ($originalTitles as $index => $title) {
            $titlesList .= ($index + 1) . ". \"{$title}\"\n";
        }

        $prompt = "You are a viral title expert. I have several viral titles that perform well, and I want to adapt each one to match a specific project while maintaining their viral appeal. Titles should be under 50 chars.

Target project: \"{$inputQuery}\"

Original viral titles:
{$titlesList}

Please create enhanced versions for each title that:
1. Match the project and intent of the target query
2. Maintain the viral appeal and structure of the original title
3. Use similar emotional triggers and power words
4. Are engaging and click-worthy
5. Keep the same length and style

Return the enhanced titles in the same numbered format, one per line. Only return the enhanced titles, nothing else.

Example format:
1. Enhanced Title 1
2. Enhanced Title 2
3. Enhanced Title 3";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 1000,
            'temperature' => 0.7
        ]);

        if (!$response->successful()) {
            throw new \Exception('ChatGPT API request failed: ' . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid response from ChatGPT API');
        }

        // Parse the response to extract individual enhanced titles
        $responseText = trim($data['choices'][0]['message']['content']);
        $lines = explode("\n", $responseText);
        
        $enhancedTitles = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines
            if (empty($line)) continue;
            
            // Extract title after the number and period
            if (preg_match('/^\d+\.\s*(.+)$/', $line, $matches)) {
                $enhancedTitles[] = trim($matches[1]);
            }
        }

        return $enhancedTitles;
    }
}
