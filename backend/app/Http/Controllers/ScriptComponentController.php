<?php

namespace App\Http\Controllers;

use App\Models\Script;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScriptComponentController extends Controller
{
    /**
     * Save/Sync selected components for a script (Stage 3).
     */
    public function store(Request $request, $scriptId)
    {
        $request->validate([
            'component_ids' => 'required|array',
            'component_ids.*' => 'exists:library_components,id',
        ]);

        $script = Script::where('user_id', Auth::id())->findOrFail($scriptId);
        
        $script->components()->sync($request->component_ids);

        return response()->json([
            'success' => true,
            'message' => 'Components selected successfully',
            'data' => $script->load('components')
        ]);
    }

    public function index($scriptId)
    {
        $script = Script::where('user_id', Auth::id())->findOrFail($scriptId);
        
        return response()->json([
            'success' => true,
            'data' => $script->components
        ]);
    }
}
