<?php

namespace App\Console\Commands;

use App\Services\YouTubeTranscriptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TestYouTubeTranscription extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:youtube-transcription 
                            {url : YouTube URL or video ID (e.g., https://www.youtube.com/watch?v=g45NqxJ34-Y or g45NqxJ34-Y)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the YouTube transcription service by downloading a transcription for a YouTube video';

    /**
     * Execute the console command.
     */
    public function handle(YouTubeTranscriptService $transcriptService): int
    {
        $input = $this->argument('url');
        
        $this->info('Testing YouTube Transcription Service');
        $this->newLine();
        
        // Extract video ID from URL or use as-is
        $videoId = $transcriptService->extractVideoId($input);
        
        if (!$videoId) {
            // If extraction failed, try using input directly as video ID
            if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
                $videoId = $input;
                $this->info("Using input as video ID: {$videoId}");
            } else {
                $this->error("Failed to extract video ID from: {$input}");
                $this->info("Please provide a valid YouTube URL or video ID (11 characters)");
                return Command::FAILURE;
            }
        } else {
            $this->info("Extracted video ID: {$videoId}");
        }
        
        $this->newLine();
        $this->info("Video ID: {$videoId}");
        $this->info("Attempting to download transcription...");
        $this->newLine();
        
        try {
            $startTime = microtime(true);
            
            // Download transcription
            $result = $transcriptService->downloadTranscription($videoId);
            
            $duration = round(microtime(true) - $startTime, 2);
            
            if ($result === false) {
                $this->error("Failed to download transcription for video ID: {$videoId}");
                $this->newLine();
                $this->info("Possible reasons:");
                $this->line("  - API key not configured (check YOUTUBE_TRANSCRIPT_API_KEY)");
                $this->line("  - API endpoint incorrect (check YOUTUBE_TRANSCRIPT_API_URL)");
                $this->line("  - Video doesn't have transcriptions available");
                $this->line("  - API rate limit or error");
                $this->newLine();
                $this->info("Check the logs for more details: storage/logs/laravel.log");
                return Command::FAILURE;
            }
            
            $this->info("✓ Transcription downloaded successfully!");
            $this->newLine();
            
            // Display results
            $transcriptJson = $result['transcript_json'] ?? null;
            $transcriptCount = is_array($transcriptJson) ? count($transcriptJson) : 0;
            $jsonFileLocation = $result['json_file_location'] ?? null;
            $textFileLocation = $result['text_file_location'] ?? null;
            
            $this->table(
                ['Field', 'Value'],
                [
                    ['Video ID', $videoId],
                    ['Transcript JSON', $transcriptJson ? 'Present' : 'Not found'],
                    ['Transcript Items', $transcriptCount],
                    ['JSON File Location', $jsonFileLocation ?? 'Not set'],
                    ['Text File Location', $textFileLocation ?? 'Not set'],
                    ['Duration', "{$duration} seconds"],
                ]
            );
            
            // Show preview of transcription content
            if ($transcriptJson && is_array($transcriptJson)) {
                $this->newLine();
                $this->info("Transcription Preview (showing first few items):");
                $this->line("─" . str_repeat("─", 70));
                
                $previewItems = array_slice($transcriptJson, 0, 5);
                foreach ($previewItems as $index => $item) {
                    if (isset($item['text'])) {
                        $text = substr($item['text'], 0, 100);
                        $this->line("Item " . ($index + 1) . ": " . $text);
                        if (strlen($item['text']) > 100) {
                            $this->line("... (truncated)");
                        }
                    } else {
                        $this->line("Item " . ($index + 1) . ": " . json_encode($item));
                    }
                }
                
                if ($transcriptCount > 5) {
                    $this->line("... (showing 5 of {$transcriptCount} items)");
                }
                $this->line("─" . str_repeat("─", 70));
                
                // Show JSON structure info
                $this->newLine();
                $this->info("Transcript Structure:");
                if (isset($transcriptJson[0])) {
                    $sampleKeys = array_keys($transcriptJson[0]);
                    $this->line("  Keys per item: " . implode(', ', $sampleKeys));
                }
            } elseif ($transcriptJson) {
                $this->newLine();
                $this->warn("Transcript JSON is not in expected array format");
                $this->line("Type: " . gettype($transcriptJson));
            } else {
                $this->newLine();
                $this->warn("No transcript JSON found in result");
            }
            
            $this->newLine();
            $this->info("Test completed successfully!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->newLine();
            $this->info("Error details:");
            $this->line("  File: " . $e->getFile());
            $this->line("  Line: " . $e->getLine());
            $this->newLine();
            $this->info("Check the logs for more details: storage/logs/laravel.log");
            
            return Command::FAILURE;
        }
    }
    
    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
