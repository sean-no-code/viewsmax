<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class ThumbnailAnalyzer
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
            // Original AI Checks
            'face_detection' => 'Number of faces detected (1-10 scale)',
            'expression_analysis' => 'Emotional expression intensity and clarity (1-10 scale)',
            'object_counting' => 'Number and relevance of objects in thumbnail (1-10 scale)',
            'contrast' => 'Visual contrast level (1-10 scale)',
            'brightness' => 'Overall brightness level (1-10 scale)',
            'saturation' => 'Color saturation level (1-10 scale)',
            'clutter_score' => 'Visual clutter and complexity (1-10 scale)',
            'readability_check' => 'How easy it is to read any text (1-10 scale)',
            'curiosity_gap_estimation' => 'How much the thumbnail creates curiosity (1-10 scale)',
            'story_clarity_estimation' => 'How clearly the thumbnail tells a story (1-10 scale)',
            'title_thumbnail_alignment' => 'How well thumbnail matches the title concept (1-10 scale)',
            'safety_classification' => 'Safety and appropriateness rating (1-10 scale)',
            
            // Moved from Computer Vision (AI handles these better)
            'rule_of_thirds' => 'Composition score: Is the subject placed on the thirds grid? (1-10)',
            'text_detection_count' => 'Amount of readable text detected (1-10 scale)',
            'noise' => 'Image noise level (inverse scale - 1-10 where 10 is least noise)',
        ];
    }

    /**
     * Get the computer vision checks criteria
     *
     * @return array
     */
    public function getComputerVisionChecks(): array
    {
        return [
            'dimensions' => 'Thumbnail dimensions quality (1-10 scale)',
            'file_format' => 'File format appropriateness (1-10 scale)',
            'file_size' => 'File size optimization (1-10 scale)',
            'histogram_contrast' => 'Technical contrast measurement (1-10 scale)',
            'sharpness' => 'Image sharpness level (1-10 scale)',
        ];
    }

    /**
     * Download thumbnail from YouTube
     *
     * @param string $youtubeVideoId
     * @return string File path of downloaded thumbnail
     * @throws \Exception
     */
    public function downloadThumbnail(string $youtubeVideoId): string
    {
        $thumbnailUrl = "https://img.youtube.com/vi/{$youtubeVideoId}/maxresdefault.jpg";
        $fileName = "{$youtubeVideoId}.jpg";
        $filePath = "thumbnails/{$fileName}";

        Log::info("Downloading thumbnail", [
            'youtube_video_id' => $youtubeVideoId,
            'url' => $thumbnailUrl,
            'file_path' => $filePath
        ]);

        try {
            $response = Http::timeout(30)->get($thumbnailUrl);

            if (!$response->successful()) {
                throw new \Exception("Failed to download thumbnail: HTTP {$response->status()}");
            }

            // Ensure thumbnails directory exists
            Storage::disk('local')->makeDirectory('thumbnails');

            // Store the thumbnail
            Storage::disk('local')->put($filePath, $response->body());

            Log::info("Thumbnail downloaded successfully", [
                'youtube_video_id' => $youtubeVideoId,
                'file_path' => $filePath,
                'file_size' => strlen($response->body())
            ]);

            return $filePath;

        } catch (\Exception $e) {
            Log::error("Failed to download thumbnail", [
                'youtube_video_id' => $youtubeVideoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Analyze a thumbnail using Claude Vision API
     *
     * @param string $thumbnailPath
     * @param string|null $videoTitle
     * @return array Array of scores keyed by criteria name
     * @throws \Exception
     */
    public function analyzeThumbnail(string $thumbnailPath, ?string $videoTitle = null): array
    {
        Log::info("ThumbnailAnalyzer::analyzeThumbnail started", [
            'thumbnail_path' => $thumbnailPath,
            'video_title' => $videoTitle,
            'use_fake' => $this->useFake
        ]);

        if ($this->useFake) {
            Log::info("Using fake mode for thumbnail analysis");
            sleep(15); // 15 seconds delay to simulate processing
            // throw new \Exception('Fake mode for thumbnail analysis');
            return $this->generateFakeScores();
        }

        if (empty($this->apiKey) || $this->apiKey === 'your-anthropic-api-key-here') {
            Log::error("Anthropic API key not configured properly");
            throw new \Exception('Anthropic API key not configured');
        }

        if (!Storage::disk('local')->exists($thumbnailPath)) {
            throw new \Exception('Thumbnail file not found: ' . $thumbnailPath);
        }

        // 1. Extract Technical Metrics via PHP (Optimized)
        $cvMetrics = $this->extractComputerVisionMetrics($thumbnailPath);

        // 2. Build Prompt for AI
        $prompt = $this->buildPrompt($videoTitle, $cvMetrics);

        Log::info("Making HTTP request to Anthropic Vision API", [
            'model' => $this->model,
            'thumbnail_path' => $thumbnailPath
        ]);

        try {
            // Read image file and convert to base64
            $imageData = Storage::disk('local')->get($thumbnailPath);
            $base64Image = base64_encode($imageData);

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ])->timeout(120)->post($this->apiUrl, [
                'model' => $this->model,
                'max_tokens' => 1000,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'image/jpeg',
                                    'data' => $base64Image
                                ]
                            ],
                            [
                                'type' => 'text',
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'temperature' => 0.3
            ]);

            Log::info("Anthropic Vision API response", [
                'response' => $response->body()
            ]);

            if (!$response->successful()) {
                Log::error("Anthropic Vision API request failed", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'thumbnail_path' => $thumbnailPath
                ]);
                throw new \Exception('Anthropic Vision API request failed: ' . $response->body());
            }

            $result = $response->json();

            if (!isset($result['content'][0]['text'])) {
                Log::error("No text content in Anthropic Vision API response", [
                    'response_data' => $result,
                    'thumbnail_path' => $thumbnailPath
                ]);
                throw new \Exception('No text content in Anthropic Vision API response');
            }

            $responseText = trim($result['content'][0]['text']);

            // Try to extract JSON from the response
            $jsonMatch = [];
            if (preg_match('/\{[^}]+\}/s', $responseText, $jsonMatch)) {
                $responseText = $jsonMatch[0];
            }

            $scores = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to parse JSON from Anthropic Vision response", [
                    'json_error' => json_last_error_msg(),
                    'response_text' => $responseText,
                    'thumbnail_path' => $thumbnailPath
                ]);
                throw new \Exception('Failed to parse JSON from Anthropic Vision response: ' . json_last_error_msg());
            }

            $scores = $this->validateAiScores($scores);

            Log::info("Successfully analyzed thumbnail", [
                'thumbnail_path' => $thumbnailPath,
                'scores_count' => count($scores),
            ]);

            return $scores;

        } catch (\Exception $e) {
            Log::error('ThumbnailAnalyzer::analyzeThumbnail error', [
                'error_message' => $e->getMessage(),
                'thumbnail_path' => $thumbnailPath,
                'error_trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Thumbnail analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Build the prompt for thumbnail analysis
     *
     * @param string|null $videoTitle
     * @return string
     */
    private function buildPrompt(?string $videoTitle = null, array $cvMetrics = []): string
    {
        $aiChecks = $this->getAiOnlyChecks();
        $cvChecks = $this->getComputerVisionChecks();


        $prompt = "Analyze this YouTube thumbnail. \n\n";
        if ($videoTitle) {
            $prompt .= "Video Title: \"{$videoTitle}\"\n\n";
        }

        $prompt .= "TECHNICAL METRICS (Data measured by code, you must score these from 1-10ß):\n";
        foreach ($cvChecks as $key => $desc) {
            $val = $cvMetrics[$key] ?? 'N/A';
            $prompt .= "- {$key}: {$val}\n";
        }
        
        $prompt .= "\nVISUAL ANALYSIS TASKS (You must score these from 1-10):\n";
        foreach ($aiChecks as $key => $desc) {
            $prompt .= "- {$key}: {<score 1-10>}\n";
        }

        $prompt .= "\nINSTRUCTIONS:\n";
        $prompt .= "1. Digest the Technical Metrics provided above. Convert them into a 1-10 score based on YouTube best practices.\n";
        $prompt .= "2. visually analyze the image for the Visual Analysis Tasks and score them into a 1-10 score based on YouTube best practices.\n";
        $prompt .= "3. Return ONLY valid JSON. Like this (x should be replaced with the actual score from 1-10): {\"face_detection\": x, \"expression_analysis\": x, \"object_counting\": x, \"contrast\": x, \"brightness\": x, \"saturation\": x, \"clutter_score\": x, \"readability_check\": x, \"curiosity_gap_estimation\": x, \"story_clarity_estimation\": x, \"title_thumbnail_alignment\": x, \"safety_classification\": x, \"dimensions\": x, \"file_format\": x, \"file_size\": x, \"histogram_contrast\": x, \"sharpness\": x, \"noise\": x, \"rule_of_thirds\": x, \"text_detection_count\": x}\n";

        Log::info("Thumbnail analysis prompt built", [
            'prompt' => $prompt
        ]);
        return $prompt;
    }

    /**
     * Validate AI scores from API response
     *
     * @param array $scores
     * @return array
     */
    private function validateAiScores(array $scores): array
    {
        $allChecks = array_merge($this->getAiOnlyChecks(), $this->getComputerVisionChecks());
        $validatedScores = [];

        foreach ($allChecks as $key => $description) {
            if (!isset($scores[$key])) {
                Log::warning("Missing AI score for key: {$key}");
                $validatedScores[$key] = 0;
            } else {
                $score = (int) $scores[$key];
                $validatedScores[$key] = max(0, min(10, $score));
            }
        }

        return $validatedScores;
    }

    /**
     * Extract computer vision metrics
     *
     * @param string $thumbnailPath
     * @return array
     */
    private function extractComputerVisionMetrics(string $thumbnailPath): array
    {
        Log::info("Extracting computer vision metrics", ['thumbnail_path' => $thumbnailPath]);
        $fullPath = Storage::disk('local')->path($thumbnailPath);

        // Basic Stats
        $size = filesize($fullPath);
        $info = getimagesize($fullPath);
        
        $metrics = [
            'dimensions' => $info ? "{$info[0]}x{$info[1]}" : 'unknown',
            'file_format' => $info ? explode('/', $info['mime'])[1] : 'unknown',
            'file_size' => $this->formatFileSize($size),
        ];
       
        $metrics = array_merge($metrics, $this->analyzeWithOptimizedGD($fullPath));

        Log::info("Computer vision metrics extracted", [
            'metrics' => $metrics
        ]);

        return $metrics;
    }

    /**
     * OPTIMIZATION: Resizes image to 300px before looping to save CPU.
     */
    private function analyzeWithOptimizedGD(string $fullPath): array
    {
        $info = getimagesize($fullPath);
        if (!$info) return $this->getFallbackMetrics();

        $src = imagecreatefromstring(file_get_contents($fullPath));
        if (!$src) return $this->getFallbackMetrics();

        // PERFORMANCE FIX: Scale down to 300px width
        $small = imagescale($src, 300);
        unset($src); // Free original memory

        $width = imagesx($small);
        $height = imagesy($small);
        $totalPixels = $width * $height;

        $totalBrightness = 0;
        $grayValues = [];

        // Single loop to gather data
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // BT.601 Grayscale formula
                $gray = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
                $grayValues[] = $gray;
                $totalBrightness += $gray;
            }
        }

        // 1. Mean Brightness (Required for RMS calculation)
        $meanBrightness = $totalBrightness / $totalPixels;

        // 2. RMS Contrast Calculation (Standard Deviation)
        $sumSquares = 0;
        foreach ($grayValues as $val) {
            $sumSquares += pow($val - $meanBrightness, 2);
        }
        $rms = sqrt($sumSquares / $totalPixels); // Raw Standard Deviation

        // 3. Sharpness Calculation (Edge Density %)
        $edges = 0;
        for ($x = 1; $x < $width - 1; $x++) {
            for ($y = 1; $y < $height - 1; $y++) {
                // Flattened index for speed
                $c = $grayValues[$x * $height + $y] ?? 0;
                $r = $grayValues[($x+1) * $height + $y] ?? 0;
                $d = $grayValues[$x * $height + ($y+1)] ?? 0;
                
                // Gradient check: If neighbor difference > 20, it's an edge
                $diff = abs($c - $r) + abs($c - $d);
                if ($diff > 20) $edges++;
            }
        }
        $edgePercentage = ($edges / $totalPixels) * 100;

        unset($small);

        return [
            'histogram_contrast' => "Value: " . round($rms, 2) . " (Standard Deviation) | Context: Calculated by measuring the spread of pixel brightness (RMS). Value <30 indicates a flat/gray image. Value >60 indicates high contrast.",
            
            'sharpness' => "Value: " . round($edgePercentage, 2) . "% (Edge Density) | Context: Calculated by counting pixels that differ significantly (>20/255) from their neighbors. <5% is blurry, >15% is sharp/detailed."
        ];
    }

    private function getFallbackMetrics(): array
    {
        return [
            'histogram_contrast' => '0',
            'sharpness' => '0',
        ];
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Generate fake scores for development
     *
     * @return array
     */
    private function generateFakeScores(): array
    {
        Log::info("Generating fake thumbnail scores");

        $aiChecks = $this->getAiOnlyChecks();
        $cvChecks = $this->getComputerVisionChecks();

        $fakeScores = [];

        // Generate random AI scores
        foreach ($aiChecks as $key => $description) {
            $fakeScores[$key] = rand(3, 9); // Random score between 3-9
        }

        // Generate random computer vision scores
        foreach ($cvChecks as $key => $description) {
            $fakeScores[$key] = rand(4, 10); // Random score between 4-10
        }

        Log::info("Generated fake scores", ['score_count' => count($fakeScores)]);

        return $fakeScores;
    }
}
