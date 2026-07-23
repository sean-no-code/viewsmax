<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessComfyUIWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ComfyUIWebhookController extends Controller
{
    /**
     * Handle ComfyUI completion webhook - ULTRA FAST VERSION
     *
     * This endpoint MUST return within 5 seconds or ComfyUI will timeout.
     * All heavy processing is done in the background job.
     *
     * Expected payload:
     * {
     *   "prompt_id": "abc-123-def",
     *   "status": "completed|failed",
     *   "outputs": { "9": { "images": [{ "filename": "...", "subfolder": "" }] } },
     *   "error": "Optional error message"
     * }
     */
    public function handleCompletion(Request $request): JsonResponse
    {
        try {
            // Parse quickly - minimal processing
            $promptId = $request->input('prompt_id');
            $status = $request->input('status', 'completed');
            $outputs = $request->input('outputs', []);
            $error = $request->input('error');
            $imageId = $request->input('image_id');

            // Parse custom_data if present (from ComfyUI-Notifications node)
            if ($request->has('custom_data')) {
                $customData = $request->input('custom_data');
                if (is_string($customData)) {
                    $decoded = json_decode($customData, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $customData = $decoded;
                    }
                }

                if (is_array($customData)) {
                    $promptId = $customData['prompt_id'] ?? $promptId;
                    $imageId = $customData['image_id'] ?? $imageId;
                    $status = $customData['status'] ?? $status;
                }
            }

            // Dispatch background job IMMEDIATELY - no blocking operations
            ProcessComfyUIWebhookJob::dispatch(
                $promptId,
                $outputs,
                $imageId,
                $status,
                $error
            );

            // Log AFTER dispatch (async, won't block response)
            Log::info('ComfyUI webhook dispatched', [
                'prompt_id' => $promptId,
                'image_id' => $imageId,
            ]);

            // Return IMMEDIATELY - no further processing
            return response()->json([
                'success' => true,
                'message' => 'OK',
            ]);

        } catch (\Exception $e) {
            // Log and ALWAYS return 200 OK
            Log::error('Webhook error', [
                'error' => $e->getMessage(),
                'payload' => $request->input('image_id'),
            ]);

            // STILL RETURN 200 OK - polling will handle recovery
            return response()->json([
                'success' => true,
                'message' => 'OK',
            ]);
        }
    }
}
