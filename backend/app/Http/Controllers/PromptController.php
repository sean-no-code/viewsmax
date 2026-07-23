<?php

namespace App\Http\Controllers;

use App\Models\Prompt;
use App\Models\PromptType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PromptController extends Controller
{
    /**
     * Display a listing of prompts.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Prompt::with(['promptType']);

            // Filter by prompt type if provided
            if ($request->has('prompt_type_id')) {
                $query->where('prompt_type_id', $request->prompt_type_id);
            }

            // Filter by active status if provided
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Get latest version only if requested
            if ($request->boolean('latest_only')) {
                $query->latestVersion();
            }

            $prompts = $query->orderBy('prompt_type_id')
                ->orderBy('version', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $prompts,
                'count' => $prompts->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('PromptController::index error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve prompts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified prompt.
     */
    public function show(Prompt $prompt): JsonResponse
    {
        try {
            $prompt->load(['promptType']);

            return response()->json([
                'success' => true,
                'data' => $prompt,
            ]);

        } catch (\Exception $e) {
            Log::error('PromptController::show error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'prompt_id' => $prompt->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve prompt',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get prompts by type.
     */
    public function getByType(Request $request, string $typeName): JsonResponse
    {
        try {
            $promptType = PromptType::where('name', $typeName)->first();

            if (! $promptType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Prompt type not found',
                ], 404);
            }

            $query = $promptType->prompts();

            // Get active only if requested
            if ($request->boolean('active_only')) {
                $query->where('is_active', true);
            }

            // Get latest version only if requested
            if ($request->boolean('latest_only')) {
                $query->latest('version');
            }

            $prompts = $query->orderBy('version', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $prompts,
                'prompt_type' => $promptType,
                'count' => $prompts->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('PromptController::getByType error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'type_name' => $typeName,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve prompts by type',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all prompt types.
     */
    public function getTypes(): JsonResponse
    {
        try {
            $promptTypes = PromptType::with(['prompts' => function ($query) {
                $query->where('is_active', true)->latest('version');
            }])->get();

            return response()->json([
                'success' => true,
                'data' => $promptTypes,
                'count' => $promptTypes->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('PromptController::getTypes error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve prompt types',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified prompt.
     */
    public function update(Request $request, Prompt $prompt): JsonResponse
    {
        try {
            $request->validate([
                'text' => 'sometimes|string|max:10000',
                'is_active' => 'sometimes|boolean',
                'version' => 'sometimes|integer|min:1',
            ]);

            $updateData = $request->only(['text', 'is_active', 'version']);

            // Only update fields that are provided
            if (! empty($updateData)) {
                $prompt->update($updateData);

                Log::info('Prompt updated successfully', [
                    'prompt_id' => $prompt->id,
                    'updated_fields' => array_keys($updateData),
                    'user_id' => Auth::id(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Prompt updated successfully',
                'data' => $prompt->load('promptType'),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('PromptController::update validation error', [
                'prompt_id' => $prompt->id,
                'validation_errors' => $e->errors(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('PromptController::update error', [
                'prompt_id' => $prompt->id,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update prompt',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
