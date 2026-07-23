<?php

namespace App\Observers;

use App\Models\VideoTranscription;
use App\Jobs\ProcessVideoTranscriptionJob;
use Illuminate\Support\Facades\Log;

class VideoTranscriptionObserver
{
    /**
     * Handle the VideoTranscription "created" event.
     */
    public function created(VideoTranscription $videoTranscription): void
    {
        Log::info('VideoTranscriptionObserver::created', [
            'video_transcription_id' => $videoTranscription->id,
            'video_id' => $videoTranscription->video_id,
            'has_file_location' => !empty($videoTranscription->file_location)
        ]);

        // Only dispatch job if transcription doesn't have file_location yet
        if (empty($videoTranscription->file_location)) {
            // Dispatch job to process the transcription
            ProcessVideoTranscriptionJob::dispatch($videoTranscription->id);
        }
    }

    /**
     * Handle the VideoTranscription "updated" event.
     */
    public function updated(VideoTranscription $videoTranscription): void
    {
        Log::info('VideoTranscriptionObserver::updated', [
            'video_transcription_id' => $videoTranscription->id,
            'video_id' => $videoTranscription->video_id,
            'processed_at' => $videoTranscription->processed_at
        ]);

        // If transcription was just marked as processed or file_location was added
        if ($videoTranscription->wasChanged('processed_at') || $videoTranscription->wasChanged('file_location')) {
            // Dispatch job to check if scripts need to be processed
            ProcessVideoTranscriptionJob::dispatch($videoTranscription->id);
        }
    }
}
