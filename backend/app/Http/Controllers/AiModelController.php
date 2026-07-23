<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\AiModelType;
use App\Models\Ethnicity;
use App\Models\FileUpload;
use App\Models\FileCategory;
use App\Jobs\CreateAiModelJob;
use App\Http\Resources\AiModelResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AiModelController extends Controller
{
    /**
     * Display a listing of AI models for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $aiModels = AiModel::forUser($userId)
                ->with(['user', 'fileUploads.fileCategory', 'thumbnail.fileCategory'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => AiModelResource::collection($aiModels),
                'count' => $aiModels->count()
            ]);

        } catch (\Exception $e) {
            Log::error('AiModelController::index error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve AI models',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created AI model.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate the request using Laravel's automatic validation
            $request->validate([
                'name' => 'required|string|max:255',
                'images' => 'required|array|min:10|max:25',
                'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max per image
                'age' => 'sometimes|integer|min:0|max:120',
                'bald' => 'sometimes|boolean',
                'ai_model_type_id' => 'sometimes|exists:ai_model_types,id',
                'ethnicity_id' => 'sometimes|exists:ethnicities,id',
            ]);

            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Get file categories
            $thumbnailCategory = FileCategory::where('name', 'AI Model Thumbnail')->first();
            $uploadCategory = FileCategory::where('name', 'AI Upload Image')->first();

            if (!$thumbnailCategory || !$uploadCategory) {
                return response()->json([
                    'success' => false,
                    'message' => 'File categories not found. Please run database seeders.'
                ], 500);
            }

            // Prepare model data
            $modelData = [
                'name' => $request->input('name'),
                'user_id' => $userId,
                'status' => 'pending'
            ];

            // Add optional fields if provided
            if ($request->has('age')) {
                $modelData['age'] = $request->input('age');
            }

            if ($request->has('bald')) {
                $modelData['bald'] = $request->boolean('bald');
            }

            if ($request->has('ai_model_type_id')) {
                $modelData['ai_model_type_id'] = $request->input('ai_model_type_id');
            }

            if ($request->has('ethnicity_id')) {
                $modelData['ethnicity_id'] = $request->input('ethnicity_id');
            }

            // Create the AI model record first
            $aiModel = AiModel::create($modelData);

            // Store uploaded images
            $fileUploads = [];
            $thumbnailFileUpload = null;

            foreach ($request->file('images') as $index => $image) {
                $fileName = time() . '_' . $index . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('ai-models/' . $userId, $fileName, 'public');

                // Create file upload record for training images
                $fileUpload = FileUpload::create([
                    'name' => $fileName,
                    'original_name' => $image->getClientOriginalName(),
                    'location' => $path,
                    'mime_type' => $image->getMimeType(),
                    'file_size' => $image->getSize(),
                    'file_category_id' => $uploadCategory->id,
                    'user_id' => $userId,
                    'ai_model_id' => $aiModel->id,
                ]);

                $fileUploads[] = $fileUpload;

                // Use the first image as thumbnail - create a separate thumbnail file
                if ($index === 0) {
                    $thumbnailFileName = time() . '_thumbnail.' . $image->getClientOriginalExtension();
                    $thumbnailPath = $image->storeAs('ai-models/' . $userId, $thumbnailFileName, 'public');

                    $thumbnailFileUpload = FileUpload::create([
                        'name' => $thumbnailFileName,
                        'original_name' => 'thumbnail_' . $image->getClientOriginalName(),
                        'location' => $thumbnailPath,
                        'mime_type' => $image->getMimeType(),
                        'file_size' => $image->getSize(),
                        'file_category_id' => $thumbnailCategory->id,
                        'user_id' => $userId,
                        'ai_model_id' => $aiModel->id,
                    ]);
                }
            }

            // Attach file uploads to the AI model (for backward compatibility with pivot table)
            $aiModel->fileUploads()->attach(collect($fileUploads)->pluck('id'));

            // Attach thumbnail to the AI model (for backward compatibility with pivot table)
            if ($thumbnailFileUpload) {
                $aiModel->fileUploads()->attach($thumbnailFileUpload->id);
            }

            Log::info('AI Model created successfully', [
                'ai_model_id' => $aiModel->id,
                'user_id' => $userId,
                'name' => $aiModel->name,
                'age' => $aiModel->age,
                'bald' => $aiModel->bald,
                'ai_model_type_id' => $aiModel->ai_model_type_id,
                'ethnicity_id' => $aiModel->ethnicity_id,
                'file_upload_count' => count($fileUploads),
                'file_upload_ids' => collect($fileUploads)->pluck('id')->toArray()
            ]);

            // Dispatch the job to process the AI model
            CreateAiModelJob::dispatch($aiModel->id);
            Log::info('AI Model dispatched');
            return response()->json([
                'success' => true,
                'message' => 'AI model created successfully and processing started',
                'data' => new AiModelResource($aiModel)
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Let validation exceptions bubble up to the global exception handler
            throw $e;
        } catch (\Exception $e) {
            Log::error('AiModelController::store error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'request_data' => $request->except(['images'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create AI model',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified AI model.
     */
    public function show(AiModel $aiModel): JsonResponse
    {
        try {
            // Check if user owns this AI model
            if ($aiModel->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to AI model'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => new AiModelResource($aiModel->load(['user', 'fileUploads.fileCategory', 'thumbnail.fileCategory']))
            ]);

        } catch (\Exception $e) {
            Log::error('AiModelController::show error', [
                'error_message' => $e->getMessage(),
                'ai_model_id' => $aiModel->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve AI model',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified AI model (soft delete).
     */
    public function destroy(AiModel $aiModel): JsonResponse
    {
        try {
            // Check if user owns this AI model
            if ($aiModel->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to AI model'
                ], 403);
            }

            // Soft delete the AI model
            $aiModel->delete();

            Log::info('AI Model soft deleted', [
                'ai_model_id' => $aiModel->id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'AI model deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('AiModelController::destroy error', [
                'error_message' => $e->getMessage(),
                'ai_model_id' => $aiModel->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete AI model',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a list of AI model types.
     */
    public function getModelTypes(): JsonResponse
    {
        try {
            $modelTypes = AiModelType::orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $modelTypes
            ]);

        } catch (\Exception $e) {
            Log::error('AiModelController::getModelTypes error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve AI model types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a list of ethnicities.
     */
    public function getEthnicities(): JsonResponse
    {
        try {
            $ethnicities = Ethnicity::orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $ethnicities
            ]);

        } catch (\Exception $e) {
            Log::error('AiModelController::getEthnicities error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve ethnicities',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
