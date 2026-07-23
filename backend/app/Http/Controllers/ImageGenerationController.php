<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateFlux2ImageJob;
use App\Models\GeneratedImage;
use App\Services\CreditService;
use App\Services\Flux2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * @group Image Generation
 *
 * APIs for generating AI images using Flux 2
 */
class ImageGenerationController extends Controller
{
    public function __construct(
        private Flux2Service $flux2Service,
        private CreditService $creditService
    ) {}

    /**
     * Validate and download an image from URL
     *
     * @param string $url The URL to download the image from
     * @param int $userId The user ID for path generation
     * @return array{path: string, url: string}|null Returns path and url, or null on failure
     */
    private function validateAndDownloadImageFromUrl(string $url, int $userId): ?array
    {
        try {
            // Use HEAD request to check if URL is accessible without downloading entire file
            $response = Http::timeout(10)->head($url);

            if (!$response->successful()) {
                Log::warning('Image URL not accessible', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            // Check if content type is an image
            $contentType = $response->header('Content-Type');
            if ($contentType && !str_starts_with($contentType, 'image/')) {
                Log::warning('URL does not point to an image', [
                    'url' => $url,
                    'content_type' => $contentType,
                ]);
                return null;
            }

            // Download the image content
            $imageContent = file_get_contents($url);

            if ($imageContent === false) {
                Log::error('Failed to download image from URL', ['url' => $url]);
                return null;
            }

            // Verify it's actually an image by checking the content
            $imageInfo = @getimagesizefromstring($imageContent);
            if ($imageInfo === false) {
                Log::warning('Downloaded content is not a valid image', ['url' => $url]);
                return null;
            }

            // Detect extension from URL or content type
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (!$extension || !in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                // Try to get extension from mime type
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                ];
                $extension = $mimeToExt[$imageInfo['mime']] ?? 'png';
            }

            $filename = 'base_' . $userId . '_' . time() . '.' . $extension;
            $path = "generated-images/{$userId}/inputs/{$filename}";

            Storage::disk('public')->put($path, $imageContent);

            Log::info('Image downloaded from URL successfully', [
                'url' => $url,
                'path' => $path,
                'size' => strlen($imageContent),
                'mime_type' => $imageInfo['mime'],
            ]);

            return ['path' => $path, 'url' => $url];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Cannot connect to image URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to process image URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get Image Generation Configuration
     *
     * Retrieve configuration options for image generation including available methods, quality presets, defaults, and limits.
     *
     * @response {"success":true,"message":"Configuration retrieved successfully","data":{"enabled":true,"methods":["generate","head_swap"],"qualities":["fast","normal","high","very_high"],"defaults":{"quality":"fast","steps":4,"refine_enabled":false,"inpainting_enabled":true,"number_of_images":1},"limits":{"max_prompt_length":2000,"max_image_size_bytes":10485760,"max_additional_images":3,"max_number_of_images":10}}}
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Configuration retrieved successfully',
            'data' => [
                'enabled' => $this->flux2Service->isEnabled(),
                'methods' => GeneratedImage::getMethodOptions(),
                'qualities' => GeneratedImage::getQualityOptions(),
                'defaults' => [
                    'quality' => $this->flux2Service->getDefaultQuality(),
                    'steps' => $this->flux2Service->getDefaultSteps(),
                    'refine_enabled' => $this->flux2Service->getDefaultRefineEnabled(),
                    'inpainting_enabled' => $this->flux2Service->getDefaultInpaintingEnabled(),
                    'number_of_images' => $this->flux2Service->getDefaultNumberOfImages(),
                ],
                'limits' => [
                    'max_prompt_length' => 2000,
                    'max_image_size_bytes' => 10 * 1024 * 1024, // 10MB
                    'max_additional_images' => 3,
                    'max_number_of_images' => $this->flux2Service->getMaxNumberOfImages(),
                ],
            ],
        ]);
    }

    /**
     * List Generated Images
     *
     * Get a paginated list of the authenticated user's generated images. Can be filtered by status and method.
     *
     * @queryparam status string Filter by status: `pending`, `processing`, `completed`, `failed`. Example: completed
     * @queryparam method string Filter by method: `generate`, `head_swap`. Example: generate
     * @queryparam per_page int Number of results per page. Example: 15
     *
     * @response {"success":true,"message":"Images retrieved successfully","data":{"current_page":1,"data":[...],"per_page":15,"total":100}}
     */
    public function index(Request $request): JsonResponse
    {
        $query = GeneratedImage::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('method')) {
            $query->where('method', $request->method);
        }

        $images = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Images retrieved successfully',
            'data' => $images,
        ]);
    }

    /**
     * Create Image Generation Request
     *
     * Create a new image generation request. Credits will be deducted based on the number of images requested (50 credits per image).
     *
     * @bodyparam method string The generation method. Must be one of: `generate`, `head_swap`. Default: `head_swap`. Example: generate
     * @bodyparam quality string The quality preset. Must be one of: `fast`, `normal`, `high`, `very_high`. Default: `fast`. Example: normal
     * @bodyparam prompt string Text prompt for generation (max 2000 characters). Example: A person standing confidently in front of a mountain
     * @bodyparam base_image file Required for `head_swap`, optional for `generate`. Image file (max 10MB). Provide either `base_image` or `base_image_url`. No-example
     * @bodyparam base_image_url string Required for `head_swap`, optional for `generate`. URL to download the base image from. Provide either `base_image` or `base_image_url`. Example: https://example.com/image.jpg
     * @bodyparam reference_image file Optional reference image file (max 10MB). For `generate` method, this is the person to generate. For `head_swap` method, this is the source face. No-example
     * @bodyparam number_of_images int Number of images to generate (1-10). Default: `1`. Example: 2
     *
     * @response 202 {"success":true,"message":"Image generation started","data":{"id":123,"status":"pending","method":"generate","quality":"normal","estimated_time_seconds":40}}
     *
     * @response 400 {"success":false,"message":"For generate method, please upload a base image, provide an image URL, or set a default reference image at /user/default-image."}
     *
     * @response 400 {"success":false,"message":"Reference image is required for head swap. You can upload a default image at /user/default-image."}
     *
     * @response 400 {"success":false,"message":"Failed to download or validate base image from URL. Please check the URL and try again."}
     *
     * @response 503 {"success":false,"message":"Image generation feature is not enabled"}
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->flux2Service->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Image generation feature is not enabled',
            ], 503);
        }

        $user = Auth::user();

        // Set method to head_swap by default
        $method = $request->input('method', GeneratedImage::METHOD_HEAD_SWAP);

        // Determine validation rules based on method
        // For head_swap: base_image OR base_image_url is required
        // For generate: both are optional (can use default reference image)
        $baseImageRequired = ($method === GeneratedImage::METHOD_HEAD_SWAP);

        // Validate request
        $validated = $request->validate([
            'method' => ['nullable', 'string', Rule::in([
                GeneratedImage::METHOD_GENERATE,
                GeneratedImage::METHOD_HEAD_SWAP,
            ])],
            'quality' => ['nullable', 'string', Rule::in([
                GeneratedImage::QUALITY_FAST,
                GeneratedImage::QUALITY_NORMAL,
                GeneratedImage::QUALITY_HIGH,
                GeneratedImage::QUALITY_VERY_HIGH,
            ])],
            'prompt' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'base_image' => [$baseImageRequired ? 'required_without:base_image_url' : 'nullable', 'image', 'max:10240'],
            'base_image_url' => [$baseImageRequired ? 'required_without:base_image' : 'nullable', 'url', 'max:2048'],
            'reference_image' => [
                'nullable',
                'image',
                'max:10240',
            ],
            'number_of_images' => ['nullable', 'integer', 'min:1', 'max:' . $this->flux2Service->getMaxNumberOfImages()],
        ], [
            'number_of_images.max' => 'Maximum number of images is ' . $this->flux2Service->getMaxNumberOfImages() . '.',
        ]);

        $validated['method'] = $method;

        // Get the number of images to generate
        $numberOfImages = (int) ($request->input('number_of_images', $this->flux2Service->getDefaultNumberOfImages()));

        // Validation for generate method: need either base_image (file or url) or default reference image
        if ($validated['method'] === GeneratedImage::METHOD_GENERATE) {
            $hasBaseImage = $request->hasFile('base_image') || $request->filled('base_image_url');
            $hasDefaultImage = $user->hasDefaultReferenceImage();

            if (!$hasBaseImage && !$hasDefaultImage) {
                return response()->json([
                    'success' => false,
                    'message' => 'For generate method, please upload a base image, provide an image URL, or set a default reference image at /user/default-image.',
                ], 400);
            }
        }

        // Validation for head_swap method: need reference_image or default reference image
        if ($validated['method'] === GeneratedImage::METHOD_HEAD_SWAP) {
            if (!$request->hasFile('reference_image') && !$user->hasDefaultReferenceImage()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reference image is required for head swap. You can upload a default image at /user/default-image.',
                ], 400);
            }
        }

        try {
            // Determine image mapping based on method
            $basePath = null;           // image_1 (base)
            $referencePath = null;      // image_2 (reference)
            $additionalImages = [];     // Additional images (currently disabled)

            // Handle base_image from URL if provided
            $baseImageUrl = null;
            if ($request->filled('base_image_url')) {
                $downloaded = $this->validateAndDownloadImageFromUrl($request->input('base_image_url'), $user->id);
                if ($downloaded === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to download or validate base image from URL. Please check the URL and try again.',
                    ], 400);
                }
                $basePath = $downloaded['path'];
                $baseImageUrl = $downloaded['url'];
            }

            if ($validated['method'] === GeneratedImage::METHOD_GENERATE) {
                // GENERATE method logic:
                // - If base_image uploaded or from URL: image_1 = base_image
                //   - If reference_image uploaded: image_2 = reference_image (takes priority)
                //   - Else if default exists: image_2 = default
                // - If no base_image:
                //   - image_1 = default (must exist)
                //   - If reference_image uploaded: image_2 = reference_image

                if ($request->hasFile('base_image') || $basePath !== null) {
                    // base_image uploaded or downloaded - becomes image_1
                    if ($basePath === null && $request->hasFile('base_image')) {
                        $basePath = $request->file('base_image')->store(
                            "generated-images/{$user->id}/inputs",
                            'public'
                        );
                    }

                    // reference_image takes priority over default for image_2
                    if ($request->hasFile('reference_image')) {
                        $referencePath = $request->file('reference_image')->store(
                            "generated-images/{$user->id}/inputs",
                            'public'
                        );
                    } elseif ($user->hasDefaultReferenceImage()) {
                        $referencePath = $user->default_reference_image_path;
                    }
                } else {
                    // No base_image - use default as image_1
                    $basePath = $user->default_reference_image_path;

                    // If reference_image uploaded, it becomes image_2
                    if ($request->hasFile('reference_image')) {
                        $referencePath = $request->file('reference_image')->store(
                            "generated-images/{$user->id}/inputs",
                            'public'
                        );
                    }
                }
            } else {
                // HEAD_SWAP method logic:
                // - image_1 = base_image (file or URL, required)
                // - image_2 = reference_image OR default

                // If basePath wasn't set from URL, get it from file upload
                if ($basePath === null && $request->hasFile('base_image')) {
                    $basePath = $request->file('base_image')->store(
                        "generated-images/{$user->id}/inputs",
                        'public'
                    );
                }

                // reference_image OR default becomes image_2
                if ($request->hasFile('reference_image')) {
                    $referencePath = $request->file('reference_image')->store(
                        "generated-images/{$user->id}/inputs",
                        'public'
                    );
                } elseif ($user->hasDefaultReferenceImage()) {
                    $referencePath = $user->default_reference_image_path;
                }
            }

            // Determine workflow parameters
            $quality = $validated['quality'] ?? $this->flux2Service->getDefaultQuality();
            $megapixel = $this->flux2Service->getQualityMegapixel($quality);
            $steps = $this->flux2Service->getDefaultSteps();
            $refineEnabled = $this->flux2Service->getDefaultRefineEnabled();
            $inpaintingEnabled = $this->flux2Service->getDefaultInpaintingEnabled();
            $numberOfImages = (int) ($validated['number_of_images'] ?? $this->flux2Service->getDefaultNumberOfImages());

            // Create the generation record
            $image = GeneratedImage::create([
                'user_id' => $user->id,
                'method' => $validated['method'],
                'quality' => $quality,
                'status' => GeneratedImage::STATUS_PENDING,
                'prompt' => $validated['prompt'] ?? null,
                'base_image_path' => $basePath,
                'base_image_url' => $baseImageUrl,
                'reference_image_path' => $referencePath,
                'additional_images' => !empty($additionalImages) ? $additionalImages : null,
                'megapixel' => $megapixel,
                'steps' => $steps,
                'refine_enabled' => $refineEnabled,
                'inpainting_enabled' => $inpaintingEnabled,
                'number_of_images' => $numberOfImages,
            ]);

            $image->addLog('Generation request created');

            // Dispatch the job
            GenerateFlux2ImageJob::dispatch($image->id);

            Log::info('Image generation request created', [
                'image_id' => $image->id,
                'method' => $validated['method'],
                'quality' => $quality,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Image generation started',
                'data' => [
                    'id' => $image->id,
                    'status' => $image->status,
                    'method' => $image->method,
                    'quality' => $image->quality,
                    'estimated_time_seconds' => $this->flux2Service->calculatePollDelay($quality, $steps),
                ],
            ], 202);

        } catch (\Exception $e) {
            Log::error('Image generation request failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create image generation request: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Generated Image Details
     *
     * Retrieve detailed information about a specific generated image.
     *
     * @urlparam id int The ID of the generated image. Example: 123
     *
     * @response {"success":true,"message":"Generated image retrieved successfully","data":{"id":123,"method":"generate","quality":"normal","status":"completed","prompt":"A person standing confidently","base_image_url":"https://...","reference_image_url":"https://...","result_image_url":"https://...","result_image_urls":["https://...","https://..."],"result_image_count":2,"megapixel":0.5,"steps":4,"refine_enabled":false,"inpainting_enabled":true,"number_of_images":2,"error_message":null,"processing_log":[...],"created_at":"2024-01-28T10:00:00Z","updated_at":"2024-01-28T10:01:00Z"}}
     *
     * @response 404 {"success":false,"message":"Generated image not found"}
     */
    public function show(int $id): JsonResponse
    {
        $image = GeneratedImage::where('user_id', Auth::id())
            ->where('id', $id)
            ->first();

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Generated image not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Generated image retrieved successfully',
            'data' => [
                'id' => $image->id,
                'method' => $image->method,
                'quality' => $image->quality,
                'status' => $image->status,
                'prompt' => $image->prompt,
                'base_image_url' => $image->base_image_url,
                'reference_image_url' => $image->reference_image_url,
                'result_image_url' => $image->result_image_url,
                'result_image_urls' => $image->result_image_urls,
                'result_image_count' => $image->result_image_count,
                'megapixel' => $image->megapixel,
                'steps' => $image->steps,
                'refine_enabled' => $image->refine_enabled,
                'inpainting_enabled' => $image->inpainting_enabled,
                'number_of_images' => $image->number_of_images,
                'error_message' => $image->error_message,
                'processing_log' => $image->processing_log,
                'created_at' => $image->created_at,
                'updated_at' => $image->updated_at,
            ],
        ]);
    }

    /**
     * Get Image Generation Status
     *
     * Check the processing status of a generated image. Use this to poll for completion.
     *
     * @urlparam id int The ID of the generated image. Example: 123
     *
     * @response {"success":true,"message":"Status retrieved successfully","data":{"id":123,"status":"processing","is_complete":false,"is_successful":false}}
     *
     * @response {"success":true,"message":"Status retrieved successfully","data":{"id":123,"status":"completed","is_complete":true,"is_successful":true,"result_image_url":"https://...","result_image_urls":["https://..."],"result_image_count":2}}
     *
     * @response {"success":true,"message":"Status retrieved successfully","data":{"id":123,"status":"failed","is_complete":true,"is_successful":false,"error_message":"Error details..."}}
     *
     * @response 404 {"success":false,"message":"Generated image not found"}
     */
    public function status(int $id): JsonResponse
    {
        $image = GeneratedImage::where('user_id', Auth::id())
            ->where('id', $id)
            ->first();

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Generated image not found',
            ], 404);
        }

        $response = [
            'id' => $image->id,
            'status' => $image->status,
            'is_complete' => $image->isComplete(),
            'is_successful' => $image->isSuccessful(),
        ];

        if ($image->isSuccessful()) {
            $response['result_image_url'] = $image->result_image_url;
            $response['result_image_urls'] = $image->result_image_urls;
            $response['result_image_count'] = $image->result_image_count;
        }

        if ($image->status === GeneratedImage::STATUS_FAILED) {
            $response['error_message'] = $image->error_message;
        }

        return response()->json([
            'success' => true,
            'message' => 'Status retrieved successfully',
            'data' => $response,
        ]);
    }

    /**
     * Download Generated Image
     *
     * Download the generated image file. Returns a binary PNG file.
     *
     * @urlparam id int The ID of the generated image. Example: 123
     *
     * @response 404 {"success":false,"message":"Generated image not found"}
     * @response 400 {"success":false,"message":"No result image available for download"}
     */
    public function download(int $id)
    {
        $image = GeneratedImage::where('user_id', Auth::id())
            ->where('id', $id)
            ->first();

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Generated image not found',
            ], 404);
        }

        if (!$image->isSuccessful() || !$image->result_image_path) {
            return response()->json([
                'success' => false,
                'message' => 'No result image available for download',
            ], 400);
        }

        if (!Storage::disk('public')->exists($image->result_image_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Result image file not found',
            ], 404);
        }

        $filename = "generated_{$image->id}.png";

        return Storage::disk('public')->download($image->result_image_path, $filename);
    }

    /**
     * Delete Generated Image
     *
     * Delete a generated image and all associated files.
     *
     * @urlparam id int The ID of the generated image. Example: 123
     *
     * @response {"success":true,"message":"Generated image deleted successfully"}
     *
     * @response 404 {"success":false,"message":"Generated image not found"}
     */
    public function destroy(int $id): JsonResponse
    {
        $image = GeneratedImage::where('user_id', Auth::id())
            ->where('id', $id)
            ->first();

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Generated image not found',
            ], 404);
        }

        // Delete associated files
        if ($image->base_image_path) {
            Storage::disk('public')->delete($image->base_image_path);
        }
        if ($image->reference_image_path && $image->reference_image_path !== Auth::user()->default_reference_image_path) {
            Storage::disk('public')->delete($image->reference_image_path);
        }
        if ($image->additional_images) {
            foreach ($image->additional_images as $path) {
                Storage::disk('public')->delete($path);
            }
        }
        if ($image->result_image_path) {
            Storage::disk('public')->delete($image->result_image_path);
        }

        $image->delete();

        Log::info('Generated image deleted', ['image_id' => $id]);

        return response()->json([
            'success' => true,
            'message' => 'Generated image deleted successfully',
        ]);
    }
}
