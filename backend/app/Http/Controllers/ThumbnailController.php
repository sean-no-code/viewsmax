<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCopyThumbnailJob;
use App\Jobs\GenerateFlux2ImageJob;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\ModifyThumbnailJob;
use App\Models\AiModel;
use App\Models\Thumbnail;
use App\Services\CreditService;
use App\Services\ThumbnailHelper;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ThumbnailController extends Controller
{
    protected $thumbnailHelper;

    protected $creditService;

    public function __construct(ThumbnailHelper $thumbnailHelper, CreditService $creditService)
    {
        $this->thumbnailHelper = $thumbnailHelper;
        $this->creditService = $creditService;
    }

    /**
     * Resolve a Thumbnail or CopyThumbnail based on the ?type= query param.
     * Returns [model, type] or null if not found.
     */
    private function findResource(string $id, Request $request): ?array
    {
        $type = $request->query('type', 'generated');

        if ($type === 'copy') {
            $model = Thumbnail::copied()
                ->where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            return $model ? [$model, 'copy'] : null;
        }

        $model = Thumbnail::generated()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        return $model ? [$model, 'generated'] : null;
    }

    /**
     * Normalize a Thumbnail model into a uniform response shape.
     * Includes backward-compatible fields (file_location, visualizable_scene, etc.)
     */
    private function normalizeGenerated(Thumbnail $thumbnail, bool $includeParents = false): array
    {
        $data = [
            'id' => $thumbnail->id,
            'type' => 'generated',
            'description' => $thumbnail->description,
            'prompt' => $thumbnail->prompt,
            'image_url' => $thumbnail->file_location,
            'file_location' => $thumbnail->file_location,
            'status' => $thumbnail->status,
            'error_message' => $thumbnail->error_message,
            'processed_at' => $thumbnail->processed_at,
            'parent_id' => $thumbnail->parent_id,
            'comfy_prompt_id' => $thumbnail->comfy_prompt_id,
            'user_id' => $thumbnail->user_id,
            'visualizable_scene' => $thumbnail->visualizable_scene ?? null,
            'generated_thumbnails' => $thumbnail->generated_thumbnails ?? null,
            'file_locations' => $thumbnail->file_locations ?? null,
            'created_at' => $thumbnail->created_at,
            'updated_at' => $thumbnail->updated_at,
        ];

        if ($includeParents) {
            $data['prompts_used'] = $thumbnail->getPromptsUsed();
            $data['prompt_summary'] = $thumbnail->getPromptSummary();
            $data['combined_prompts'] = $thumbnail->getCombinedPromptText();
            $data['parents'] = $this->getAllParents($thumbnail);
        }

        return $data;
    }

    /**
     * Normalize a copy Thumbnail into a uniform response shape.
     */
    private function normalizeCopied(Thumbnail $copy, bool $includeDetails = false): array
    {
        $data = [
            'id' => $copy->id,
            'type' => 'copied',
            'description' => null,
            'prompt' => $copy->transformed_prompt,
            'image_url' => $copy->result_image_url,
            'status' => $copy->status,
            'error_message' => $copy->error_message,
            'processed_at' => null,
            'parent_id' => null,
            'comfy_prompt_id' => $copy->comfy_prompt_id,
            'created_at' => $copy->created_at,
            'updated_at' => $copy->updated_at,
            'source_image_url' => $copy->source_image_url,
            'result_image_url' => $copy->result_image_url,
            'resolution_width' => $copy->resolution_width,
            'resolution_height' => $copy->resolution_height,
        ];

        if ($includeDetails) {
            $data['extracted_expression'] = $copy->extracted_expression;
            $data['processing_log'] = $copy->processing_log;
            $data['dw_pose_enabled'] = $copy->dw_pose_enabled;
            $data['ai_model_id'] = $copy->ai_model_id;
        }

        return $data;
    }

    /**
     * Get all parents recursively for a thumbnail (flat list)
     * Returns a flat array of all ancestors: [direct parent, grandparent, ...]
     */
    private function getAllParents(Thumbnail $thumbnail): array
    {
        $parents = [];
        $current = $thumbnail;

        while ($current->parent_id) {
            $parent = Thumbnail::with(['negativePrompt', 'stylePrompt', 'generalPrompt'])
                ->find($current->parent_id);

            if (! $parent) {
                break;
            }

            $parents[] = [
                'id' => $parent->id,
                'description' => $parent->description,
                'prompt' => $parent->prompt,
                'user_id' => $parent->user_id,
                'file_location' => $parent->file_location,
                'status' => $parent->status,
                'error_message' => $parent->error_message,
                'processed_at' => $parent->processed_at,
                'parent_id' => $parent->parent_id,
                'created_at' => $parent->created_at,
                'updated_at' => $parent->updated_at,
                'prompts_used' => $parent->getPromptsUsed(),
                'prompt_summary' => $parent->getPromptSummary(),
                'combined_prompts' => $parent->getCombinedPromptText(),
            ];

            $current = $parent;
        }

        return $parents;
    }

    /**
     * Display a listing of the resource — merged, paginated.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);

        try {
            // Get generated thumbnails (leaf nodes only)
            $generatedQuery = Thumbnail::with(['negativePrompt', 'stylePrompt', 'generalPrompt', 'parent'])
                ->generated()
                ->where('user_id', Auth::id())
                ->whereDoesntHave('children')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn (Thumbnail $t) => $this->normalizeGenerated($t, true));

            // Get copied thumbnails
            $copiedQuery = Thumbnail::copied()
                ->where('user_id', Auth::id())
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn (Thumbnail $c) => $this->normalizeCopied($c));

            // Merge and sort by created_at desc
            $merged = $generatedQuery->concat($copiedQuery)
                ->sortByDesc('created_at')
                ->values();

            // Manual pagination
            $page = $request->input('page', 1);
            $total = $merged->count();
            $items = $merged->forPage($page, $perPage)->values();

            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => (int) $page,
                    'per_page' => (int) $perPage,
                    'total' => $total,
                    'last_page' => (int) ceil($total / $perPage),
                ],
                'message' => 'Thumbnails retrieved successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving thumbnails: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve thumbnails: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage (generated thumbnail).
     */
    public function store(Request $request)
    {
        $request->validate([
            'description' => 'required|string|max:1000',
            'number_of_thumbnails' => 'sometimes|integer|min:1|max:10',
            'ai_model_id' => 'sometimes|integer|exists:ai_models,id',
            'service' => 'sometimes|string|in:openai,gemini,flux,flux2',
        ]);

        try {
            $numberOfThumbnails = $request->number_of_thumbnails ?? 1;
            $createdThumbnails = [];

            // Deduct credits for thumbnail generation
            $this->creditService->deductCreditsForOperation(
                Auth::user(),
                CreditService::THUMBNAIL_GENERATION_OPERATION,
                $numberOfThumbnails
            );

            Log::info('Credits deducted for thumbnail generation', [
                'user_id' => Auth::id(),
                'number_of_thumbnails' => $numberOfThumbnails,
            ]);

            // If ai_model_id is provided, get the AI model and extract trigger word
            $triggerWord = null;
            if ($request->ai_model_id) {
                $aiModel = AiModel::find($request->ai_model_id);
                if ($aiModel) {
                    $triggerWord = $aiModel->triggerWord();

                    Log::info('AI model trigger word retrieved', [
                        'ai_model_id' => $request->ai_model_id,
                        'trigger_word' => $triggerWord,
                        'description' => $request->description,
                    ]);
                }
            }

            // Create multiple thumbnail records
            for ($i = 0; $i < $numberOfThumbnails; $i++) {
                // Use the description as the prompt
                $prompt = $request->description;

                // If trigger word is present, append it as the main character with attributes
                if ($triggerWord && $aiModel) {
                    // Build character description parts
                    $characterParts = [];

                    // Add age if available
                    if ($aiModel->age) {
                        $characterParts[] = "a {$aiModel->age} year old";
                    }

                    // Add ethnicity if available
                    if ($aiModel->ethnicity) {
                        $characterParts[] = $aiModel->ethnicity->name;
                    }

                    // Add type (male/female) if available
                    if ($aiModel->aiModelType) {
                        $characterParts[] = $aiModel->aiModelType->name;
                    }

                    // Add bald status if true
                    if ($aiModel->bald) {
                        $characterParts[] = 'bald';
                    }

                    // Build the character description
                    $characterDescription = '';
                    if (! empty($characterParts)) {
                        $characterDescription = implode(', ', $characterParts);
                    }

                    // Build the full prompt
                    if ($characterDescription) {
                        $prompt = "{$request->description} featuring {$triggerWord} {$characterDescription} as the main character";
                    } else {
                        $prompt = "{$request->description} featuring {$triggerWord} as the main character";
                    }

                    Log::info('Enhanced prompt with trigger word', [
                        'thumbnail_index' => $i,
                        'original_description' => $request->description,
                        'trigger_word' => $triggerWord,
                        'enhanced_prompt' => $prompt,
                    ]);
                }

                $thumbnail = Thumbnail::create([
                    'description' => $request->description,
                    'prompt' => $prompt,
                    'user_id' => Auth::id(),
                    'status' => 'pending',
                    'ai_model_id' => $request->ai_model_id,
                ]);

                // Load the thumbnail with prompt relationships for response
                $thumbnailWithPrompts = Thumbnail::with(['negativePrompt', 'stylePrompt', 'generalPrompt'])->find($thumbnail->id);
                $createdThumbnails[] = [
                    'id' => $thumbnailWithPrompts->id,
                    'type' => 'generated',
                    'description' => $thumbnailWithPrompts->description,
                    'prompt' => $thumbnailWithPrompts->prompt,
                    'user_id' => $thumbnailWithPrompts->user_id,
                    'file_location' => $thumbnailWithPrompts->file_location,
                    'status' => $thumbnailWithPrompts->status,
                    'error_message' => $thumbnailWithPrompts->error_message,
                    'processed_at' => $thumbnailWithPrompts->processed_at,
                    'created_at' => $thumbnailWithPrompts->created_at,
                    'updated_at' => $thumbnailWithPrompts->updated_at,
                    'prompts_used' => $thumbnailWithPrompts->getPromptsUsed(),
                    'prompt_summary' => $thumbnailWithPrompts->getPromptSummary(),
                    'combined_prompts' => $thumbnailWithPrompts->getCombinedPromptText(),
                ];

                Log::debug(json_encode($createdThumbnails));
                // Dispatch job for async processing for each thumbnail
                Log::info('Dispatching GenerateThumbnailsJob', [
                    'thumbnail_id' => $thumbnail->id,
                    'user_id' => Auth::id(),
                    'description' => $thumbnail->description,
                    'ai_model_id' => $thumbnail->ai_model_id,
                ]);
                GenerateThumbnailsJob::dispatch($thumbnail->id, $request->service);
            }

            return response()->json([
                'success' => true,
                'data' => $createdThumbnails, // Return array of created thumbnails with prompt info
                'message' => "Thumbnail generation started for {$numberOfThumbnails} thumbnails.",
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating thumbnails: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to start thumbnail generation: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new copy thumbnail request (ComfyUI-based face/style transfer).
     * Accepts either source_image (file upload) or source_image_url (URL to download).
     */
    public function copy(Request $request): JsonResponse
    {
        // Accept both 'base_image' and 'source_image' from frontend
        $imageField = $request->hasFile('base_image') ? 'base_image' : 'source_image';

        $request->validate([
            $imageField => 'required_without:source_image_url|image|max:10240', // Max 10MB
            'source_image_url' => "required_without:{$imageField}|url",
            'reference_image' => 'nullable|image|max:10240', // Optional per-request reference image
            'resolution_width' => 'nullable|integer|min:256|max:2048',
            'resolution_height' => 'nullable|integer|min:256|max:2048',
            'dw_pose_enabled' => 'nullable|boolean',
            'ai_model_id' => 'nullable|exists:ai_models,id',
            'negative_prompt_id' => 'nullable|exists:prompts,id',
            'style_prompt_id' => 'nullable|exists:prompts,id',
            'general_prompt_id' => 'nullable|exists:prompts,id',
        ]);

        // Additional validation for source_image_url - check if accessible
        if ($request->has('source_image_url')) {
            $url = $request->input('source_image_url');

            try {
                $response = \Illuminate\Support\Facades\Http::timeout(10)->head($url);

                if (! $response->successful()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Image URL is not accessible',
                        'details' => "HTTP {$response->status()}: The provided URL returned an error status.",
                    ], 400);
                }

                $contentType = $response->header('Content-Type');
                if ($contentType && ! str_starts_with($contentType, 'image/')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'URL does not point to an image',
                        'details' => "Content-Type: {$contentType}",
                    ], 400);
                }

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot connect to image URL',
                    'details' => 'The URL is unreachable or the server is not responding.',
                ], 400);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or malformed image URL',
                    'details' => $e->getMessage(),
                ], 400);
            }
        }

        Log::info('ThumbnailController::copy - Creating new copy thumbnail', [
            'user_id' => Auth::id(),
            'has_file' => $request->hasFile($imageField),
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
        $referencePath = null;

        // Handle reference image upload (per-request override of user's default)
        if ($request->hasFile('reference_image')) {
            $refFile = $request->file('reference_image');
            $refFilename = 'reference_'.Auth::id().'_'.time().'.'.$refFile->getClientOriginalExtension();
            $referencePath = $refFile->storeAs('copy-thumbnails/'.Auth::id().'/references', $refFilename, 'public');
            Log::info('Reference image uploaded', ['path' => $referencePath]);
        }

        // Handle file upload (supports both 'source_image' and 'base_image' field names)
        if ($request->hasFile($imageField)) {
            $file = $request->file($imageField);
            $filename = 'source_'.Auth::id().'_'.time().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('copy-thumbnails/'.Auth::id().'/sources', $filename, 'public');
        }
        // Handle URL input
        elseif ($request->has('source_image_url')) {
            $sourceUrl = $request->input('source_image_url');

            try {
                $imageContent = file_get_contents($sourceUrl);

                if ($imageContent === false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to download image from URL',
                    ], 400);
                }

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

        if (! $negativePromptId) {
            $negativePrompt = \App\Models\Prompt::whereHas('promptType', function ($q) {
                $q->where('name', 'negative');
            })->where('is_active', true)->first();
            $negativePromptId = $negativePrompt?->id;
        }

        if (! $stylePromptId) {
            $stylePrompt = \App\Models\Prompt::whereHas('promptType', function ($q) {
                $q->where('name', 'style');
            })->where('is_active', true)->first();
            $stylePromptId = $stylePrompt?->id;
        }

        if (! $generalPromptId) {
            $generalPrompt = \App\Models\Prompt::whereHas('promptType', function ($q) {
                $q->where('name', 'general');
            })->where('is_active', true)->first();
            $generalPromptId = $generalPrompt?->id;
        }

        Log::info('Prompt IDs resolved', [
            'negative_prompt_id' => $negativePromptId,
            'style_prompt_id' => $stylePromptId,
            'general_prompt_id' => $generalPromptId,
        ]);

        // Create the copy thumbnail record (in the unified thumbnails table)
        $copyThumbnail = Thumbnail::create([
            'type' => Thumbnail::TYPE_COPY_THUMBNAIL,
            'user_id' => Auth::id(),
            'description' => '',
            'source_image_path' => $path,
            'source_image_url' => $sourceUrl,
            'reference_image_path' => $referencePath,
            'status' => Thumbnail::STATUS_PENDING,
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
            'data' => $this->normalizeCopied($copyThumbnail, true),
        ], 201);
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

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        try {
            $result = $this->findResource($id, $request);

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found',
                ], 404);
            }

            [$model, $type] = $result;

            if ($type === 'copy') {
                $data = $this->normalizeCopied($model, true);
            } else {
                $model->load(['negativePrompt', 'stylePrompt', 'generalPrompt', 'parent']);
                $data = $this->normalizeGenerated($model, true);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Thumbnail retrieved successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving thumbnail: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve thumbnail: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'description' => 'sometimes|required|string|max:1000',
            'prompt' => 'sometimes|required|string|max:2000',
        ]);

        try {
            $result = $this->findResource($id, $request);

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found',
                ], 404);
            }

            [$model, $type] = $result;

            // Handle editing a copied thumbnail
            if ($type === 'copy') {
                return $this->updateCopiedThumbnail($request, $model);
            }

            // Handle editing a generated thumbnail (existing logic)
            return $this->updateGeneratedThumbnail($request, $model);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Thumbnail not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating thumbnail: '.$e->getMessage(), [
                'thumbnail_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update thumbnail: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a generated thumbnail (existing logic).
     */
    private function updateGeneratedThumbnail(Request $request, Thumbnail $thumbnail)
    {
        // If prompt is provided, create a new thumbnail with modifications
        if ($request->has('prompt') && ! empty($request->prompt)) {
            // Check if the original thumbnail has a file location
            if (! $thumbnail->file_location) {
                return response()->json([
                    'success' => false,
                    'message' => 'Original thumbnail does not have an image file. Cannot modify.',
                ], 400);
            }

            // Check if thumbnail is still processing
            if ($thumbnail->status === 'processing' || $thumbnail->status === 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Original thumbnail is still being generated. Please try again later.',
                ], 400);
            }

            // Check if thumbnail generation failed
            if ($thumbnail->status === 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Original thumbnail generation failed. Cannot modify.',
                ], 400);
            }

            Log::info('Creating new thumbnail for modification', [
                'original_thumbnail_id' => $thumbnail->id,
                'prompt' => $request->prompt,
                'file_location' => $thumbnail->file_location,
            ]);

            // Deduct credit for thumbnail modification
            $this->creditService->deductCreditsForOperation(
                Auth::user(),
                CreditService::THUMBNAIL_GENERATION_OPERATION,
                1
            );

            // Create a new thumbnail record with parent_id and status 'processing'
            $newThumbnail = Thumbnail::create([
                'description' => $request->prompt,
                'prompt' => $request->prompt,
                'user_id' => Auth::id(),
                'status' => 'processing',
                'parent_id' => $thumbnail->id,
                'negative_prompt_id' => $thumbnail->negative_prompt_id,
                'style_prompt_id' => $thumbnail->style_prompt_id,
                'general_prompt_id' => $thumbnail->general_prompt_id,
                'ai_model_id' => $thumbnail->ai_model_id,
            ]);

            Log::info('New thumbnail created, dispatching ModifyThumbnailJob', [
                'new_thumbnail_id' => $newThumbnail->id,
                'parent_thumbnail_id' => $thumbnail->id,
                'prompt' => $request->prompt,
            ]);

            ModifyThumbnailJob::dispatch($newThumbnail->id);

            $newThumbnailWithPrompts = Thumbnail::with(['negativePrompt', 'stylePrompt', 'generalPrompt'])->find($newThumbnail->id);

            return response()->json([
                'success' => true,
                'data' => $this->normalizeGenerated($newThumbnailWithPrompts, true),
                'message' => 'Thumbnail modification started. Use the status endpoint to check progress.',
            ], 201);
        }

        // Legacy: Update description (old behavior)
        $updateData = $request->only(['description']);

        if ($request->has('description') && $request->description !== $thumbnail->description) {
            $updateData['status'] = 'pending';
            $updateData['error_message'] = null;
            $updateData['processed_at'] = null;

            $thumbnail->update($updateData);

            Log::info('Dispatching GenerateThumbnailsJob for regeneration', [
                'thumbnail_id' => $thumbnail->id,
                'user_id' => Auth::id(),
                'description' => $thumbnail->description,
                'ai_model_id' => $thumbnail->ai_model_id,
                'reason' => 'description_updated',
            ]);
            GenerateThumbnailsJob::dispatch($thumbnail->id);

            return response()->json([
                'success' => true,
                'data' => $thumbnail,
                'message' => 'Thumbnail regeneration started. Use the status endpoint to check progress.',
            ]);
        }

        $thumbnail->update($updateData);

        return response()->json([
            'success' => true,
            'data' => $thumbnail,
            'message' => 'Thumbnail updated successfully',
        ]);
    }

    /**
     * Update a copied thumbnail — uses Flux2 pipeline to edit the result image
     * with the user's prompt. The existing result image becomes the base image,
     * and the prompt guides the edit.
     */
    private function updateCopiedThumbnail(Request $request, Thumbnail $copyThumbnail): JsonResponse
    {
        if (! $request->has('prompt') || empty($request->prompt)) {
            return response()->json([
                'success' => false,
                'message' => 'A prompt is required to edit a copied thumbnail.',
            ], 400);
        }

        // Ensure the copy is completed
        if ($copyThumbnail->status !== Thumbnail::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'Copied thumbnail is not ready for editing. Current status: '.$copyThumbnail->status,
            ], 400);
        }

        if (! $copyThumbnail->result_image_path) {
            return response()->json([
                'success' => false,
                'message' => 'Copied thumbnail has no result image. Cannot edit.',
            ], 400);
        }

        $flux2Service = app(\App\Services\Flux2Service::class);

        Log::info('Editing copy thumbnail via Flux2', [
            'copy_thumbnail_id' => $copyThumbnail->id,
            'prompt' => $request->prompt,
            'result_image_path' => $copyThumbnail->result_image_path,
        ]);

        // Deduct credit
        $this->creditService->deductCreditsForOperation(
            Auth::user(),
            CreditService::FACE_SWAP_COPY_THUMBNAIL_OPERATION,
            1
        );

        // Get workflow parameters
        $quality = $flux2Service->getDefaultQuality();
        $megapixel = $flux2Service->getQualityMegapixel($quality);
        $steps = $flux2Service->getDefaultSteps();

        // Create a GeneratedImage record using the copy's result as the base image (single image only)
        $generatedImage = \App\Models\GeneratedImage::create([
            'user_id' => $copyThumbnail->user_id,
            'method' => \App\Models\GeneratedImage::METHOD_GENERATE,
            'quality' => $quality,
            'status' => \App\Models\GeneratedImage::STATUS_PENDING,
            'prompt' => $request->prompt,
            'base_image_path' => $copyThumbnail->result_image_path,
            'reference_image_path' => null, // Single image edit — no reference needed
            'megapixel' => $megapixel,
            'steps' => $steps,
            'refine_enabled' => $flux2Service->getDefaultRefineEnabled(),
            'inpainting_enabled' => $flux2Service->getDefaultInpaintingEnabled(),
            'number_of_images' => 1,
        ]);

        $generatedImage->addLog('Created for copy thumbnail edit', [
            'source_copy_thumbnail_id' => $copyThumbnail->id,
            'edit_prompt' => $request->prompt,
        ]);

        // Create a NEW copy thumbnail (keep the original untouched)
        $newCopyThumbnail = Thumbnail::create([
            'user_id' => $copyThumbnail->user_id,
            'type' => Thumbnail::TYPE_COPY_THUMBNAIL,
            'description' => $request->prompt,
            'source_image_path' => $copyThumbnail->result_image_path,
            'source_image_url' => $copyThumbnail->result_image_url,
            'transformed_prompt' => $request->prompt,
            'status' => Thumbnail::STATUS_PROCESSING,
            'generated_image_id' => $generatedImage->id,
            'resolution_width' => $copyThumbnail->resolution_width,
            'resolution_height' => $copyThumbnail->resolution_height,
            'dw_pose_enabled' => $copyThumbnail->dw_pose_enabled,
            'ai_model_id' => $copyThumbnail->ai_model_id,
        ]);

        $newCopyThumbnail->addLog('Edit of copy thumbnail started', [
            'source_copy_thumbnail_id' => $copyThumbnail->id,
            'generated_image_id' => $generatedImage->id,
            'edit_prompt' => $request->prompt,
            'base_image' => $copyThumbnail->result_image_path,
        ]);

        // Dispatch the Flux2 generation job
        GenerateFlux2ImageJob::dispatch($generatedImage->id);

        Log::info('Copy thumbnail edit dispatched as new thumbnail', [
            'source_copy_thumbnail_id' => $copyThumbnail->id,
            'new_copy_thumbnail_id' => $newCopyThumbnail->id,
            'generated_image_id' => $generatedImage->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->normalizeCopied($newCopyThumbnail),
            'message' => 'Image edit started.',
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        try {
            $result = $this->findResource($id, $request);

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found',
                ], 404);
            }

            [$model, $type] = $result;

            if ($type === 'copy') {
                Log::info('Deleting copy thumbnail', [
                    'copy_thumbnail_id' => $model->id,
                    'user_id' => Auth::id(),
                ]);

                // Delete stored files
                if ($model->source_image_path && Storage::disk('public')->exists($model->source_image_path)) {
                    Storage::disk('public')->delete($model->source_image_path);
                }
                if ($model->result_image_path && Storage::disk('public')->exists($model->result_image_path)) {
                    Storage::disk('public')->delete($model->result_image_path);
                }

                $model->delete();
            } else {
                $model->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Thumbnail deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting thumbnail: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete thumbnail: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Enhance description using OpenAI getVisualizableScene function
     */
    public function enhanceDescription(Request $request)
    {
        $request->validate([
            'description' => 'required|string|max:1000',
        ]);

        try {
            $openAIService = app(\App\Services\OpenAIService::class);
            $enhancedDescription = $openAIService->getVisualizableScene($request->description);

            return response()->json([
                'success' => true,
                'data' => [
                    'original_description' => $request->description,
                    'description' => $enhancedDescription,
                ],
                'message' => 'Description enhanced successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error enhancing description: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to enhance description: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the status of thumbnail generation.
     */
    public function status(Request $request, string $id)
    {
        try {
            $result = $this->findResource($id, $request);

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found',
                ], 404);
            }

            [$model, $type] = $result;

            if ($type === 'copy') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $model->id,
                        'type' => 'copied',
                        'status' => $model->status,
                        'is_complete' => $model->isComplete(),
                        'is_successful' => $model->isSuccessful(),
                        'error_message' => $model->error_message,
                        'processing_log' => $model->processing_log,
                        'image_url' => $model->result_image_url,
                        'source_image_url' => $model->source_image_url,
                        'created_at' => $model->created_at,
                        'updated_at' => $model->updated_at,
                    ],
                    'message' => 'Status retrieved successfully',
                ]);
            }

            $model->load(['negativePrompt', 'stylePrompt', 'generalPrompt']);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $model->id,
                    'type' => 'generated',
                    'description' => $model->description,
                    'prompt' => $model->prompt,
                    'status' => $model->status,
                    'file_location' => $model->file_location,
                    'error_message' => $model->error_message,
                    'processed_at' => $model->processed_at,
                    'comfy_prompt_id' => $model->comfy_prompt_id,
                    'visualizable_scene' => $model->visualizable_scene ?? null,
                    'generated_thumbnails' => $model->generated_thumbnails ?? null,
                    'file_locations' => $model->file_locations ?? null,
                    'created_at' => $model->created_at,
                    'updated_at' => $model->updated_at,
                    'prompts_used' => $model->getPromptsUsed(),
                    'prompt_summary' => $model->getPromptSummary(),
                ],
                'message' => 'Status retrieved successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving thumbnail status: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve thumbnail status: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a thumbnail file.
     */
    public function download(Request $request, string $id)
    {
        try {
            $result = $this->findResource($id, $request);

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found',
                ], 404);
            }

            [$model, $type] = $result;

            if ($type === 'copy') {
                return $this->downloadCopyThumbnail($model);
            }

            return $this->downloadGeneratedThumbnail($model);

        } catch (\Exception $e) {
            Log::error('Error downloading thumbnail: '.$e->getMessage(), [
                'thumbnail_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download thumbnail: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a generated thumbnail.
     */
    private function downloadGeneratedThumbnail(Thumbnail $thumbnail)
    {
        if (! $thumbnail->file_location) {
            return response()->json([
                'success' => false,
                'message' => 'Thumbnail file not found or not yet generated',
            ], 404);
        }

        if ($thumbnail->status === 'processing' || $thumbnail->status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Thumbnail is still being generated. Please try again later.',
            ], 202);
        }

        if ($thumbnail->status === 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Thumbnail generation failed',
            ], 404);
        }

        $filePath = $thumbnail->file_location;

        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($filePath);
            $filePath = $parsedUrl['path'] ?? $filePath;
        }

        $filePath = ltrim($filePath, '/');

        if (! Storage::disk('public')->exists($filePath)) {
            Log::error('Thumbnail file not found in storage', [
                'thumbnail_id' => $thumbnail->id,
                'file_path' => $filePath,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Thumbnail file not found in storage',
            ], 404);
        }

        $fileSize = Storage::disk('public')->size($filePath);
        $fileName = basename($filePath);
        $userFriendlyName = 'thumbnail_'.$thumbnail->id.'_'.$fileName;
        $fullPath = Storage::disk('public')->path($filePath);
        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

        return response()->download($fullPath, $userFriendlyName, [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
        ]);
    }

    /**
     * Download a copy thumbnail result image.
     */
    private function downloadCopyThumbnail(Thumbnail $copyThumbnail)
    {
        if ($copyThumbnail->status !== Thumbnail::STATUS_COMPLETED) {
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
        $fullPath = Storage::disk('public')->path($copyThumbnail->result_image_path);
        $mimeType = mime_content_type($fullPath) ?: 'image/png';

        return response()->download($fullPath, $filename, [
            'Content-Type' => $mimeType,
        ]);
    }
}
