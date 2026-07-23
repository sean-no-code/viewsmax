<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

class YouTubeTranscriptService
{
    private string $apiKey;
    private string $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('services.youtube_transcript.api_key', '');
        $this->apiUrl = config('services.youtube_transcript.api_url', 'https://www.youtube-transcript.io');
    }

    /**
     * Extract YouTube video ID from a URL
     *
     * @param string $url YouTube URL
     * @return string|null Video ID or null if not found
     */
    public function extractVideoId(string $url): ?string
    {
        // Handle various YouTube URL formats
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/v\/)([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]{11})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Download transcription for a YouTube video
     *
     * @param string $videoId YouTube video ID
     * @return array|false Array with transcript_json, json_file_location, and text_file_location on success, false on failure
     */
    public function downloadTranscription(string $videoId)
    {
            Log::info('YouTubeTranscriptService::downloadTranscription started', [
            'video_id' => $videoId,
            'api_url' => $this->apiUrl,
            'has_api_key' => !empty($this->apiKey)
        ]);

        // Check if we're in local environment - if so, return fake response to avoid API costs
        if (config('app.env') === 'local') {
            Log::info('Local environment detected - returning fake transcription response to avoid API costs', [
                'environment' => config('app.env'),
                'video_id' => $videoId
            ]);
            
            // Generate fake transcript data
            $fakeTranscript = [
                [
                    'start' => '0',
                    'dur' => '5.0',
                    'text' => 'Welcome to this fake transcript as youre in localhost environment'
                ],
                [
                    'start' => '5',
                    'dur' => '5.0',
                    'text' => 'In this video, we will cover the main topics.'
                ],
                [
                    'start' => '10',
                    'dur' => '5.0',
                    'text' => 'Let\'s get started with the first point.'
                ],
                [
                    'start' => '15',
                    'dur' => '5.0',
                    'text' => 'This is a sample transcription for local development.'
                ],
                [
                    'start' => '20',
                    'dur' => '5.0',
                    'text' => 'Thank you for watching this video.'
                ]
            ];
            
            // Save JSON file
            $jsonFileLocation = $this->saveJsonFile($videoId, $fakeTranscript);
            
            // Create formatted text file with MM:SS timestamps
            $textFileLocation = $this->saveFormattedTextFile($videoId, $fakeTranscript);
            
            Log::info('YouTubeTranscriptService::downloadTranscription completed (fake response)', [
                'video_id' => $videoId,
                'json_file_location' => $jsonFileLocation,
                'text_file_location' => $textFileLocation
            ]);
            
            return [
                'transcript_json' => $fakeTranscript,
                'json_file_location' => $jsonFileLocation,
                'text_file_location' => $textFileLocation
            ];
        }

        // Check if transcript files already exist - if so, load and return them without making API request
        $folderPath = "transcriptions/{$videoId}";
        $jsonFilePath = "{$folderPath}/transcript.json";
        $textFilePath = "{$folderPath}/transcript.txt";
        
        if (Storage::disk('local')->exists($jsonFilePath) && Storage::disk('local')->exists($textFilePath)) {
            Log::info('Transcript files already exist, loading from storage', [
                'video_id' => $videoId,
                'json_file_location' => $jsonFilePath,
                'text_file_location' => $textFilePath
            ]);
            
            try {
                $jsonContent = Storage::disk('local')->get($jsonFilePath);
                $transcriptJson = json_decode($jsonContent, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($transcriptJson)) {
                    Log::info('YouTubeTranscriptService::downloadTranscription completed (loaded from existing files)', [
                        'video_id' => $videoId,
                        'transcript_type' => 'array',
                        'transcript_count' => count($transcriptJson),
                        'json_file_location' => $jsonFilePath,
                        'text_file_location' => $textFilePath
                    ]);
                    
                    return [
                        'transcript_json' => $transcriptJson,
                        'json_file_location' => $jsonFilePath,
                        'text_file_location' => $textFilePath
                    ];
                } else {
                    Log::warning('Existing transcript JSON file is invalid, will re-download', [
                        'video_id' => $videoId,
                        'json_error' => json_last_error_msg()
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load existing transcript files, will re-download', [
                    'video_id' => $videoId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        try {

            // Make API request to youtube-transcript.io
            // API endpoint: /api/transcripts
            // Parameter: ids (array of strings, max 50)
            // Authentication: Basic (not Bearer)
            $headers = [];
            if (!empty($this->apiKey)) {
                $headers['Authorization'] = 'Basic ' . $this->apiKey;
            }
            
            // Construct URL - handle both cases:
            // 1. apiUrl = https://www.youtube-transcript.io -> append /api/transcripts
            // 2. apiUrl = https://www.youtube-transcript.io/api -> append /transcripts
            $baseUrl = rtrim($this->apiUrl, '/');
            if (str_ends_with($baseUrl, '/api')) {
                $requestUrl = $baseUrl . '/transcripts';
            } else {
                $requestUrl = $baseUrl . '/api/transcripts';
            }
            $requestData = [
                'ids' => [$videoId] // Array of strings, limited to 50 at a time
            ];
            
            Log::info('Making API request to download transcription', [
                'video_id' => $videoId,
                'url' => $requestUrl,
                'data' => $requestData,
                'has_api_key' => !empty($this->apiKey),
                'auth_type' => 'Basic',
                'headers' => array_keys($headers),
                'json_body' => json_encode($requestData)
            ]);
            
            // POST request with ids as array
            // Try using Guzzle directly to ensure proper JSON body format
            $client = new Client([
                'timeout' => 60,
            ]);
            
            $guzzleHeaders = [];
            if (!empty($this->apiKey)) {
                $guzzleHeaders['Authorization'] = 'Basic ' . $this->apiKey;
            }
            $guzzleHeaders['Content-Type'] = 'application/json';
            $guzzleHeaders['Accept'] = 'application/json';
            
            Log::info('Sending request via Guzzle', [
                'url' => $requestUrl,
                'headers' => array_keys($guzzleHeaders),
                'body' => json_encode($requestData)
            ]);
            
            try {
                $guzzleResponse = $client->post($requestUrl, [
                    'headers' => $guzzleHeaders,
                    'json' => $requestData,
                ]);
                
                $statusCode = $guzzleResponse->getStatusCode();
                $responseBody = $guzzleResponse->getBody()->getContents();
                $responseHeaders = $guzzleResponse->getHeaders();
                
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
                
                Log::error('Guzzle HTTP Client error', [
                    'video_id' => $videoId,
                    'url' => $requestUrl,
                    'status_code' => $statusCode,
                    'error_message' => $e->getMessage(),
                    'response_body' => substr($responseBody, 0, 50)
                ]);
                
                // Re-throw as our custom exception
                throw new \Exception("API request failed with status {$statusCode}: " . substr($responseBody, 0, 200), $statusCode);
            }
            
            $isSuccessful = $statusCode >= 200 && $statusCode < 300;
            
            Log::info('API response received', [
                'video_id' => $videoId,
                'url' => $requestUrl,
                'status_code' => $statusCode,
                'response_successful' => $isSuccessful,
                'response_body_preview' => substr($responseBody, 0, 50),
            ]);

            if (!$isSuccessful) {
                $isHtml = str_starts_with(trim($responseBody), '<!DOCTYPE') || str_starts_with(trim($responseBody), '<html');
                
                Log::error('YouTube Transcript API error', [
                    'video_id' => $videoId,
                    'status_code' => $statusCode,
                    'is_html_response' => $isHtml,
                    'response_body_preview' => $isHtml ? substr($responseBody, 0, 200) : substr($responseBody, 0, 500),
                    'api_url' => $this->apiUrl,
                    'request_url' => $requestUrl,
                    'request_method' => 'POST',
                    'request_data' => $requestData,
                    'request_headers' => $headers
                ]);
                
                $errorMessage = $isHtml 
                    ? "API returned HTML instead of JSON (status: {$statusCode}). The API endpoint may be incorrect or unavailable."
                    : "API request failed with status {$statusCode}: " . substr($responseBody, 0, 200);
                    
                throw new \Exception($errorMessage);
            }

            // Check if response is actually JSON before trying to parse
            $contentType = $responseHeaders['Content-Type'][0] ?? '';
            // $responseBody already set above
            $isHtml = str_starts_with(trim($responseBody), '<!DOCTYPE') || str_starts_with(trim($responseBody), '<html');
            
            if ($isHtml || (!str_contains($contentType, 'json') && !str_contains($contentType, 'application/json'))) {
                Log::error('YouTube Transcript API returned non-JSON response', [
                    'video_id' => $videoId,
                    'content_type' => $contentType,
                    'is_html' => $isHtml,
                    'response_preview' => substr($responseBody, 0, 200),
                    'api_url' => $this->apiUrl
                ]);
                throw new \Exception('API returned non-JSON response. Expected JSON but received: ' . substr($contentType ?: 'unknown content type', 0, 100));
            }

            $data = json_decode($responseBody, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse JSON response', [
                    'video_id' => $videoId,
                    'json_error' => json_last_error_msg(),
                    'response_preview' => substr($responseBody, 0, 500)
                ]);
                throw new \Exception('Invalid JSON response from API: ' . json_last_error_msg());
            }

            // Extract English transcript from response format
            // API returns array of results (one per video ID in the request)
            // Response format: [{ tracks: [{ language: "en", transcript: [...] }] }]
            // Since we send one video ID, we get an array with one element
            $transcriptJson = null;
            
            Log::debug('API response received', [
                'video_id' => $videoId,
                'response_type' => is_array($data) ? 'array' : gettype($data),
                'response_length' => is_array($data) ? count($data) : null,
                'first_item_structure' => is_array($data) && count($data) > 0 ? array_keys($data[0] ?? []) : null
            ]);
            
            if (is_array($data)) {
                // Check if it's an array of objects with tracks
                foreach ($data as $item) {
                    if (isset($item['tracks']) && is_array($item['tracks'])) {
                        foreach ($item['tracks'] as $track) {
                            if (isset($track['language']) && isset($track['transcript'])) {
                                $language = strtolower($track['language']);
                                // Check if language is 'en' or contains 'english' (handles variations like "English", "English (auto-generated)", etc.)
                                if ($language === 'en' || str_contains($language, 'english')) {
                                    $transcriptJson = $track['transcript'];
                                    break 2; // Break out of both loops
                                }
                            }
                        }
                    }
                }
                
                // If we didn't find it in tracks format, try other formats
                if ($transcriptJson === null) {
                    if (isset($data['transcript'])) {
                        $transcriptJson = $data['transcript'];
                    } elseif (isset($data['text'])) {
                        $transcriptJson = $data['text'];
                    } elseif (isset($data['data'])) {
                        $transcriptJson = $data['data'];
                    } elseif (count($data) > 0 && !isset($data[0]['tracks'])) {
                        // If it's a simple array, use it as transcript
                        $transcriptJson = $data;
                    }
                }
            } elseif (is_string($data)) {
                // Try to decode if it's a JSON string
                $decoded = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = $decoded;
                    // Retry extraction with decoded data
                    foreach ($data as $item) {
                        if (isset($item['tracks']) && is_array($item['tracks'])) {
                            foreach ($item['tracks'] as $track) {
                                if (isset($track['language']) && isset($track['transcript'])) {
                                    $language = strtolower($track['language']);
                                    // Check if language is 'en' or contains 'english' (handles variations like "English", "English (auto-generated)", etc.)
                                    if ($language === 'en' || str_contains($language, 'english')) {
                                        $transcriptJson = $track['transcript'];
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (empty($transcriptJson)) {
                Log::warning('No English transcription found for video', [
                    'video_id' => $videoId,
                    'response_structure' => is_array($data) ? 'array' : gettype($data),
                    'response_sample' => is_array($data) ? array_slice($data, 0, 2) : (is_string($data) ? substr($data, 0, 200) : $data),
                    'api_url' => $this->apiUrl
                ]);
                return false;
            }

            // Round start times to the closest second
            $roundedTranscript = $this->roundStartTimes($transcriptJson);

            // Save JSON file
            $jsonFileLocation = $this->saveJsonFile($videoId, $roundedTranscript);
            
            // Create formatted text file with MM:SS timestamps
            $textFileLocation = $this->saveFormattedTextFile($videoId, $roundedTranscript);

            Log::info('YouTubeTranscriptService::downloadTranscription completed', [
                'video_id' => $videoId,
                'transcript_type' => is_array($transcriptJson) ? 'array' : gettype($transcriptJson),
                'transcript_count' => is_array($transcriptJson) ? count($transcriptJson) : null,
                'json_file_location' => $jsonFileLocation,
                'text_file_location' => $textFileLocation
            ]);

            return [
                'transcript_json' => $roundedTranscript,
                'json_file_location' => $jsonFileLocation,
                'text_file_location' => $textFileLocation
            ];

        } catch (\Exception $e) {
            Log::error('YouTubeTranscriptService::downloadTranscription failed', [
                'video_id' => $videoId,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'api_url' => $this->apiUrl,
                'has_api_key' => !empty($this->apiKey)
            ]);

            // Re-throw the exception so ProcessVideoTranscriptionJob can capture the actual error message
            throw $e;
        }
    }

    /**
     * Round start times to the closest second
     *
     * @param array $transcript Transcript array with start and dur fields
     * @return array Transcript with rounded start times
     */
    private function roundStartTimes(array $transcript): array
    {
        $rounded = [];
        
        foreach ($transcript as $item) {
            if (isset($item['start'])) {
                $startTime = (float) $item['start'];
                $roundedStart = round($startTime);
                $item['start'] = (string) $roundedStart;
            }
            $rounded[] = $item;
        }
        
        return $rounded;
    }

    /**
     * Save transcript JSON to a file
     *
     * @param string $videoId YouTube video ID
     * @param array $transcript Transcript data
     * @return string File location path
     */
    private function saveJsonFile(string $videoId, array $transcript): string
    {
        $folderPath = "transcriptions/{$videoId}";
        
        // Ensure directory exists
        if (!Storage::disk('local')->exists($folderPath)) {
            Storage::disk('local')->makeDirectory($folderPath);
        }
        
        $fileName = "transcript.json";
        $filePath = "{$folderPath}/{$fileName}";
        
        // Save JSON file
        Storage::disk('local')->put($filePath, json_encode($transcript, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $filePath;
    }

    /**
     * Create formatted text file with MM:SS timestamps
     *
     * @param string $videoId YouTube video ID
     * @param array $transcript Transcript data with rounded start times
     * @return string File location path
     */
    private function saveFormattedTextFile(string $videoId, array $transcript): string
    {
        $folderPath = "transcriptions/{$videoId}";
        
        // Ensure directory exists
        if (!Storage::disk('local')->exists($folderPath)) {
            Storage::disk('local')->makeDirectory($folderPath);
        }
        
        $fileName = "transcript.txt";
        $filePath = "{$folderPath}/{$fileName}";
        
        // Format transcript with MM:SS timestamps
        $formattedContent = '';
        foreach ($transcript as $item) {
            if (isset($item['start']) && isset($item['text'])) {
                $startSeconds = (int) $item['start'];
                $minutes = floor($startSeconds / 60);
                $seconds = $startSeconds % 60;
                $timestamp = sprintf('%02d:%02d', $minutes, $seconds);
                $formattedContent .= "{$timestamp} {$item['text']}\n";
            }
        }
        
        // Save formatted text file
        Storage::disk('local')->put($filePath, $formattedContent);
        
        return $filePath;
    }
}

