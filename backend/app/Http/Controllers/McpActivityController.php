<?php

namespace App\Http\Controllers;

use App\Models\McpToolInvocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read access to the MCP audit log (mcp_tool_invocations): what the user's
 * connected AI assistants actually did on their account.
 */
class McpActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $activity = McpToolInvocation::where('user_id', $request->user()->id)
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['id', 'tool', 'arguments', 'is_error', 'auth_mode', 'created_at']);

        return response()->json([
            'success' => true,
            'message' => 'MCP activity retrieved',
            'data' => $activity,
        ]);
    }
}
