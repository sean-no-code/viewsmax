<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateScriptJob;
use App\Jobs\ProcessVideoTranscriptionJob;
use App\Models\Script;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScriptGenerationController extends Controller
{
    /**
     * Trigger Script Generation.
     */
    public function store(Request $request, $scriptId)
    {
        $script = Script::where('user_id', Auth::id())->findOrFail($scriptId);

        // Ensure status is processing
        $script->update(['status' => 'processing']);

        Log::info('ScriptGenerationController::store - Starting generation process', [
            'script_id' => $script->id,
            'user_id' => Auth::id()
        ]);

        // check for pending transcripts and dispatch jobs if needed
        $pendingTranscriptions = 0;
        
        // Get all transcripts linked to this script
        $transcriptions = DB::table('scripts_videos_transcriptions')
            ->where('script_id', $script->id)
            ->join('video_transcriptions', 'scripts_videos_transcriptions.video_transcription_id', '=', 'video_transcriptions.id')
            ->select('video_transcriptions.id', 'video_transcriptions.file_location')
            ->get();
            
        foreach ($transcriptions as $transcription) {
            if (empty($transcription->file_location)) {
                $pendingTranscriptions++;
                Log::info('Dispatching delayed ProcessVideoTranscriptionJob', [
                    'script_id' => $script->id,
                    'video_transcription_id' => $transcription->id
                ]);
                ProcessVideoTranscriptionJob::dispatch($transcription->id);
            }
        }

        // Only dispatch GenerateScriptJob if no transcripts are pending
        // If transcripts ARE pending, ProcessVideoTranscriptionJob will dispatch GenerateScriptJob when done
        if ($pendingTranscriptions === 0) {
            Log::info('No pending transcripts, dispatching GenerateScriptJob immediately', [
                'script_id' => $script->id
            ]);
            GenerateScriptJob::dispatch($script->id);
        } else {
            Log::info('Pending transcripts found, GenerateScriptJob will be dispatched by callback', [
                'script_id' => $script->id,
                'pending_count' => $pendingTranscriptions
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Script generation started.',
            'data' => $script
        ]);
    }
}
