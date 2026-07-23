<?php

namespace App\Jobs;

use App\Models\VideoTranscription;
use App\Models\Script;
use App\Services\YouTubeTranscriptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessVideoTranscriptionJob implements ShouldQueue
{
    use Queueable;

    public $videoTranscriptionId;
    public $tries = 3;
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct($videoTranscriptionId)
    {
        $this->videoTranscriptionId = $videoTranscriptionId;
    }

    /**
     * Execute the job.
     */
    public function handle(YouTubeTranscriptService $transcriptService): void
    {
        $startTime = microtime(true);
        
        Log::info('ProcessVideoTranscriptionJob started', [
            'video_transcription_id' => $this->videoTranscriptionId
        ]);

        try {
            // Find the video transcription record
            $videoTranscription = VideoTranscription::findOrFail($this->videoTranscriptionId);
            
            Log::info('Video transcription record found', [
                'video_transcription_id' => $this->videoTranscriptionId,
                'video_id' => $videoTranscription->video_id,
                'has_file_location' => !empty($videoTranscription->file_location),
                'processed_at' => $videoTranscription->processed_at
            ]);

            // If transcription doesn't have file_location, download it
            if (empty($videoTranscription->file_location)) {
                $video = $videoTranscription->video;
                $videoId = $video->youtube_video_id;
                
                Log::info('Downloading transcription for video', [
                    'video_id' => $video->id,
                    'youtube_video_id' => $videoId
                ]);

                // Download transcription
                try {
                    $transcriptionResult = $transcriptService->downloadTranscription($videoId);
                } catch (\Exception $downloadException) {
                    Log::error('Failed to download transcription - exception caught', [
                        'video_transcription_id' => $this->videoTranscriptionId,
                        'video_id' => $video->id,
                        'youtube_video_id' => $videoId,
                        'exception_message' => $downloadException->getMessage(),
                        'exception_file' => $downloadException->getFile(),
                        'exception_line' => $downloadException->getLine()
                    ]);
                    throw new \Exception('Failed to download transcription for video ' . $videoId . ': ' . $downloadException->getMessage(), 0, $downloadException);
                }
                
                if ($transcriptionResult) {
                    // Verify both JSON and text files exist before marking as processed
                    $jsonFileExists = !empty($transcriptionResult['json_file_location']) 
                        && Storage::disk('local')->exists($transcriptionResult['json_file_location']);
                    $textFileExists = !empty($transcriptionResult['text_file_location']) 
                        && Storage::disk('local')->exists($transcriptionResult['text_file_location']);
                    
                    if (!$jsonFileExists || !$textFileExists) {
                        Log::error('Transcription files were not created successfully', [
                            'video_transcription_id' => $this->videoTranscriptionId,
                            'youtube_video_id' => $videoId,
                            'json_file_location' => $transcriptionResult['json_file_location'] ?? null,
                            'json_file_exists' => $jsonFileExists,
                            'text_file_location' => $transcriptionResult['text_file_location'] ?? null,
                            'text_file_exists' => $textFileExists
                        ]);
                        throw new \Exception('Failed to create transcription files for video ' . $videoId);
                    }
                    
                    // Update transcription record with file_location
                    // Only set processed_at after verifying both files exist
                    $videoTranscription->update([
                        'file_location' => $transcriptionResult['text_file_location'] ?? null,
                        'processed_at' => now()
                    ]);

                    Log::info('Transcription downloaded and updated', [
                        'video_transcription_id' => $this->videoTranscriptionId,
                        'youtube_video_id' => $videoId,
                        'has_file_location' => isset($transcriptionResult['text_file_location']),
                        'transcript_count' => isset($transcriptionResult['transcript_json']) && is_array($transcriptionResult['transcript_json']) ? count($transcriptionResult['transcript_json']) : null,
                        'json_file_exists' => $jsonFileExists,
                        'text_file_exists' => $textFileExists
                    ]);
                } else {
                    Log::error('Failed to download transcription - service returned false', [
                        'video_transcription_id' => $this->videoTranscriptionId,
                        'video_id' => $video->id,
                        'youtube_video_id' => $videoId
                    ]);
                    throw new \Exception('Failed to download transcription for video ' . $videoId . ': Service returned false (no transcript found)');
                }
            } else {
                // Verify file exists before marking as processed
                $fileExists = !empty($videoTranscription->file_location) 
                    && Storage::disk('local')->exists($videoTranscription->file_location);
                
                if (!$fileExists && !empty($videoTranscription->file_location)) {
                    Log::error('Transcription file_location exists in database but file not found', [
                        'video_transcription_id' => $this->videoTranscriptionId,
                        'file_location' => $videoTranscription->file_location
                    ]);
                    throw new \Exception('Transcription file not found');
                }
                
                Log::info('Transcription already has data, verifying and marking as processed', [
                    'video_transcription_id' => $this->videoTranscriptionId,
                    'has_file_location' => !empty($videoTranscription->file_location),
                    'file_exists' => $fileExists
                ]);

                // Mark as processed if not already and file exists
                if (!$videoTranscription->processed_at && $fileExists) {
                    $videoTranscription->update([
                        'processed_at' => now()
                    ]);
                } elseif (!$fileExists && empty($videoTranscription->file_location)) {
                    // If no file_location, need to regenerate files
                    Log::warning('Transcription has no file_location, attempting to recreate', [
                        'video_transcription_id' => $this->videoTranscriptionId
                    ]);
                    // Don't mark as processed - let it be re-downloaded
                }
            }

            // Check if there are any scripts waiting for this transcription
            $this->checkAndProcessScripts($videoTranscription);

            $totalTime = microtime(true) - $startTime;
            
            Log::info('ProcessVideoTranscriptionJob completed successfully', [
                'video_transcription_id' => $this->videoTranscriptionId,
                'total_time_seconds' => round($totalTime, 2)
            ]);

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            
            Log::error('ProcessVideoTranscriptionJob failed', [
                'video_transcription_id' => $this->videoTranscriptionId,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'total_time_seconds' => round($totalTime, 2)
            ]);

            throw $e;
        }
    }

    /**
     * Check for scripts waiting for this transcription and dispatch script generation job if needed
     */
    private function checkAndProcessScripts(VideoTranscription $videoTranscription): void
    {
        // Get all scripts linked to this transcription via scripts_videos_transcriptions
        $scripts = DB::table('scripts_videos_transcriptions')
            ->where('video_transcription_id', $videoTranscription->id)
            ->join('scripts', 'scripts_videos_transcriptions.script_id', '=', 'scripts.id')
            ->whereIn('scripts.status', ['pending', 'processing'])
            ->select('scripts.id', 'scripts.status')
            ->get();

        Log::info('Checking scripts waiting for transcription', [
            'video_transcription_id' => $videoTranscription->id,
            'scripts_count' => $scripts->count()
        ]);

        foreach ($scripts as $scriptData) {
            $script = Script::find($scriptData->id);
            
            if (!$script) {
                continue;
            }

            // Check if all transcriptions for this script are processed
            $allTranscriptionsReady = $this->checkAllTranscriptionsReady($script);
            
            if ($allTranscriptionsReady && $script->status === 'processing') {
                Log::info('All transcriptions ready for processing script, dispatching GenerateScriptJob', [
                    'script_id' => $script->id,
                    'video_transcription_id' => $videoTranscription->id
                ]);

                // Dispatch script generation job
                GenerateScriptJob::dispatch($script->id);
            }
        }
    }

    /**
     * Check if all transcriptions for a script are ready (processed)
     */
    private function checkAllTranscriptionsReady(Script $script): bool
    {
        // Get all video transcriptions linked to this script
        $transcriptions = DB::table('scripts_videos_transcriptions')
            ->where('script_id', $script->id)
            ->join('video_transcriptions', 'scripts_videos_transcriptions.video_transcription_id', '=', 'video_transcriptions.id')
            ->select('video_transcriptions.id', 'video_transcriptions.processed_at', 'video_transcriptions.file_location')
            ->get();

        if ($transcriptions->isEmpty()) {
            // If no transcriptions linked, assume ready
            return true;
        }

        // Check if all have file_location and processed_at
        foreach ($transcriptions as $transcription) {
            if (empty($transcription->file_location) || empty($transcription->processed_at)) {
                return false;
            }
        }

        return true;
    }
}
