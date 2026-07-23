<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCopyThumbnailJob;
use App\Models\CopyThumbnail;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CopyThumbnailController extends Controller
{
    protected CreditService $creditService;

    public function __construct(CreditService $creditService)
    {
        $this->creditService = $creditService;
    }
    /**
     * List all copy thumbnails for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);

        $copyThumbnails = CopyThumbnail::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $copyThumbnails,
        ]);
    }

    /**
     * Create a new copy thumbnail request.
     * Accepts either source_image (file upload) or source_image_url (URL to download)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'source_image' => 'required_without:source_image_url|image|max:10240', // Max 10MB
            'source_image_url' => 'required_without:source_image|url',
            'resolution_width' => 'nullable|integer|min:256|max:2048',
            'resolution_height' => 'nullable|integer|min:256|max:2048',
            'dw_pose_enabled' => 'nullable|boolean',
            'ai_model_id' => 'required|exists:ai_models,id', // Required for trigger word and model characteristics
            'negative_prompt_id' => 'nullable|exists:prompts,id',
            'style_prompt_id' => 'nullable|exists:prompts,id',
            'general_prompt_id' => 'nullable|exists:prompts,id',
        ]);

        // Additional validation for source_image_url - check if accessible
        if ($request->has('source_image_url')) {
            $url = $request->input('source_image_url');
            
            try {
                // Use HEAD request to check if URL is accessible without downloading entire file
                $response = \Illuminate\Support\Facades\Http::timeout(10)->head($url);
                
                if (!$response->successful()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Image URL is not accessible',
                        'details' => "HTTP {$response->status()}: The provided URL returned an error status."
                    ], 400);
                }
                
                // Check if content type is an image
                $contentType = $response->header('Content-Type');
                if ($contentType && !str_starts_with($contentType, 'image/')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'URL does not point to an image',
                        'details' => "Content-Type: {$contentType}"
                    ], 400);
                }
                
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot connect to image URL',
                    'details' => 'The URL is unreachable or the server is not responding.'
                ], 400);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or malformed image URL',
                    'details' => $e->getMessage()
                ], 400);
            }
        }

        Log::info('CopyThumbnailController::store - Creating new copy thumbnail', [
            'user_id' => Auth::id(),
            'has_file' => $request->hasFile('source_image'),
            'has_url' => $request->has('source_image_url'),
            'resolution' => $request->input('resolution_width').'x'.$request->input('resolution_height'),
            'dw_pose_enabled' => $request->input('dw_pose_enabled', true),
        ]);

        // Deduct credits for copy thumbnail generation
        $this->creditService->deductCreditsForOperation(
            Auth::user(),
            CreditService::FACE_SWAP_COPY_THUMBNAIL_OPERATION,
            1
        );

        Log::info('Credits deducted for copy thumbnail generation', [
            'user_id' => Auth::id(),
        ]);

        $path = null;
        $sourceUrl = null;

        // Handle file upload
        if ($request->hasFile('source_image')) {
            $file = $request->file('source_image');
            $filename = 'source_'.Auth::id().'_'.time().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('copy-thumbnails/'.Auth::id().'/sources', $filename, 'public');
        }
        // Handle URL input
        elseif ($request->has('source_image_url')) {
            $sourceUrl = $request->input('source_image_url');

            try {
                // Download image from URL
                $imageContent = file_get_contents($sourceUrl);

                if ($imageContent === false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to download image from URL',
                    ], 400);
                }

                // Detect extension from URL or content type
                $extension = pathinfo(parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
                $filename = 'source_'.Auth::id().'_'.time().'.'.$extension;
                $path = 'copy-thumbnails/'.Auth::id().'/sources/'.$filename;

                Storage::disk('public')->put($path, $imageContent);

                Log::info('Downloaded image from URL', [
                    'url' => $sourceUrl,
                    'path' => $path,
                    'size' => strlen($imageContent),
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to download image from URL', [
                    'url' => $sourceUrl,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to download image from URL: '.$e->getMessage(),
                ], 400);
            }
        }

        if (! $path) {
            return response()->json([
                'success' => false,
                'message' => 'No image source provided',
            ], 400);
        }

        // Get config defaults
        $config = config('services.copy_thumbnail');

        // Get prompt IDs (use provided or fetch defaults)
        $negativePromptId = $request->input('negative_prompt_id');
        $stylePromptId = $request->input('style_prompt_id');
        $generalPromptId = $request->input('general_prompt_id');

        // If not provided, fetch default active prompts
        if (!$negativePromptId) {
            $negativePrompt = \App\Models\Prompt::whereHas('promptType', function($q) {
                $q->where('name', 'negative');
            })->where('is_active', true)->first();
            $negativePromptId = $negativePrompt?->id;
        }

        if (!$stylePromptId) {
            $stylePrompt = \App\Models\Prompt::whereHas('promptType', function($q) {
                $q->where('name', 'style');
            })->where('is_active', true)->first();
            $stylePromptId = $stylePrompt?->id;
        }

        if (!$generalPromptId) {
            $generalPrompt = \App\Models\Prompt::whereHas('promptType', function($q) {
                $q->where('name', 'general');
            })->where('is_active', true)->first();
            $generalPromptId = $generalPrompt?->id;
        }

        Log::info('Prompt IDs resolved', [
            'negative_prompt_id' => $negativePromptId,
            'style_prompt_id' => $stylePromptId,
            'general_prompt_id' => $generalPromptId
        ]);

        // Create the copy thumbnail record
        $copyThumbnail = CopyThumbnail::create([
            'user_id' => Auth::id(),
            'source_image_path' => $path,
            'source_image_url' => $sourceUrl,
            'status' => CopyThumbnail::STATUS_PENDING,
            'resolution_width' => $request->input('resolution_width', $config['default_width'] ?? 1024),
            'resolution_height' => $request->input('resolution_height', $config['default_height'] ?? 1024),
            'dw_pose_enabled' => $request->has('dw_pose_enabled') 
                ? filter_var($request->input('dw_pose_enabled'), FILTER_VALIDATE_BOOLEAN)
                : ($config['dw_pose_enabled'] ?? true),
            'ai_model_id' => $request->input('ai_model_id'),
            'negative_prompt_id' => $negativePromptId,
            'style_prompt_id' => $stylePromptId,
            'general_prompt_id' => $generalPromptId,
            'processing_log' => [
                [
                    'timestamp' => now()->toIso8601String(),
                    'message' => 'Copy thumbnail request created',
                    'context' => [
                        'source_image' => $path,
                        'source_url' => $sourceUrl,
                        'resolution' => $request->input('resolution_width', $config['default_width']).'x'.$request->input('resolution_height', $config['default_height']),
                    ],
                ],
            ],
        ]);

        Log::info('CopyThumbnail created', [
            'copy_thumbnail_id' => $copyThumbnail->id,
            'source_path' => $path,
            'source_url' => $sourceUrl,
        ]);

        // Dispatch the generation job
        GenerateCopyThumbnailJob::dispatch($copyThumbnail->id);

        return response()->json([
            'success' => true,
            'message' => 'Copy thumbnail generation started',
            'data' => $copyThumbnail,
        ], 201);
    }

    /**
     * Get a single copy thumbnail.
     */
    public function show(string $id): JsonResponse
    {
        $copyThumbnail = CopyThumbnail::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $copyThumbnail) {
            return response()->json([
                'success' => false,
                'message' => 'Copy thumbnail not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($copyThumbnail->toArray(), [
                'source_image_url' => $copyThumbnail->source_image_url,
                'result_image_url' => $copyThumbnail->result_image_url,
            ]),
        ]);
    }

    /**
     * Get the status of a copy thumbnail with detailed logs.
     */
    public function status(string $id): JsonResponse
    {
        $copyThumbnail = CopyThumbnail::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $copyThumbnail) {
            return response()->json([
                'success' => false,
                'message' => 'Copy thumbnail not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $copyThumbnail->id,
                'status' => $copyThumbnail->status,
                'is_complete' => $copyThumbnail->isComplete(),
                'is_successful' => $copyThumbnail->isSuccessful(),
                'error_message' => $copyThumbnail->error_message,
                'processing_log' => $copyThumbnail->processing_log,
                'source_image_url' => $copyThumbnail->source_image_url,
                'result_image_url' => $copyThumbnail->result_image_url,
                'created_at' => $copyThumbnail->created_at,
                'updated_at' => $copyThumbnail->updated_at,
            ],
        ]);
    }

    /**
     * Download the result image.
     */
    public function download(string $id): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $copyThumbnail = CopyThumbnail::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $copyThumbnail) {
            return response()->json([
                'success' => false,
                'message' => 'Copy thumbnail not found',
            ], 404);
        }

        if ($copyThumbnail->status !== CopyThumbnail::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'Copy thumbnail is not ready for download',
                'status' => $copyThumbnail->status,
            ], 400);
        }

        if (! $copyThumbnail->result_image_path) {
            return response()->json([
                'success' => false,
                'message' => 'No result image available',
            ], 404);
        }

        if (! Storage::disk('public')->exists($copyThumbnail->result_image_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Result image file not found',
            ], 404);
        }

        $filename = 'copy_thumbnail_'.$copyThumbnail->id.'.png';

        return Storage::disk('public')->download($copyThumbnail->result_image_path, $filename);
    }

    /**
     * Delete a copy thumbnail.
     */
    public function destroy(string $id): JsonResponse
    {
        $copyThumbnail = CopyThumbnail::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $copyThumbnail) {
            return response()->json([
                'success' => false,
                'message' => 'Copy thumbnail not found',
            ], 404);
        }

        Log::info('Deleting copy thumbnail', [
            'copy_thumbnail_id' => $copyThumbnail->id,
            'user_id' => Auth::id(),
        ]);

        // Delete stored files
        if ($copyThumbnail->source_image_path && Storage::disk('public')->exists($copyThumbnail->source_image_path)) {
            Storage::disk('public')->delete($copyThumbnail->source_image_path);
        }

        if ($copyThumbnail->result_image_path && Storage::disk('public')->exists($copyThumbnail->result_image_path)) {
            Storage::disk('public')->delete($copyThumbnail->result_image_path);
        }

        $copyThumbnail->delete();

        return response()->json([
            'success' => true,
            'message' => 'Copy thumbnail deleted successfully',
        ]);
    }

    /**
     * Get the current configuration for copy thumbnails.
     */
    public function config(): JsonResponse
    {
        $config = config('services.copy_thumbnail');

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $config['enabled'] ?? true,
                'default_width' => $config['default_width'] ?? 512,
                'default_height' => $config['default_height'] ?? 512,
                'dw_pose_enabled' => $config['dw_pose_enabled'] ?? true,
                'min_resolution' => 256,
                'max_resolution' => 2048,
            ],
        ]);
    }
}
