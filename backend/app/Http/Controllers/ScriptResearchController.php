<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateScriptResearchJob;
use App\Models\Script;
use App\Models\ScriptResearch;
use App\Services\PerplexityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScriptResearchController extends Controller
{
    /**
     * Generate research for a script.
     * This is usually triggered automatically on creation, but can be manually re-triggered.
     */

    /**
     * Generate research for a script using Perplexity API (Queued).
     */
    public function store(Request $request, $scriptId)
    {
        $script = Script::where('user_id', Auth::id())->findOrFail($scriptId);

        // Update/Create status to processing immediately
        $research = $script->research()->updateOrCreate(
            ['script_id' => $script->id],
            [
                'status' => 'processing',
            ]
        );

        // Dispatch the job
        GenerateScriptResearchJob::dispatch($script->id);

        return response()->json([
            'success' => true,
            'message' => 'Research generation started',
            'data' => $research
        ]);
    }

    /**
     * Update the research body (Manual User Edit).
     */
    public function update(Request $request, $scriptId)
    {
        $request->validate([
            'body' => 'required|string',
        ]);

        $script = Script::where('user_id', Auth::id())->findOrFail($scriptId);
        
        $research = $script->research;
        
        if (!$research) {
            return response()->json(['message' => 'Research not found. Please generate first.'], 404);
        }

        $research->update([
            'body' => $request->body,
        ]);

        return response()->json([
            'success' => true,
            'data' => $research
        ]);
    }
}
