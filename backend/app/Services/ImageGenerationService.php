<?php

namespace App\Services;

use App\Models\GeneratedImage;
use App\Models\Thumbnail;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Image Generation Service - High-level orchestration for Flux 2 image generation
 *
 * This service orchestrates the image generation process:
 * 1. Upload images to ComfyUI
 * 2. Build workflow using Flux2Service
 * 3. Submit workflow
 * 4. Handle completion via webhook or polling
 */
class ImageGenerationService
{
    public function __construct(
        private Flux2Service $flux2Service,
        private ComfyUIService $comfyUIService,
        private CreditService $creditService
    ) {}

    /**
     * Start image generation using Flux 2
     *
     * @param  GeneratedImage  $image  The image generation record
     * @return array Contains 'prompt_id' and other workflow info
     *
     * @throws \Exception
     */
    public function generate(GeneratedImage $image): array
    {
        $image->addLog('Starting image generation');

        // Upload images to ComfyUI
        $uploadedImages = $this->uploadImages($image);

        // Load and configure workflow
        $workflow = $this->flux2Service->loadWorkflow();

        // Set quality (megapixel)
        $megapixel = $this->flux2Service->getQualityMegapixel($image->quality);
        $this->flux2Service->setMegapixel($workflow, $megapixel);

        // Set steps
        $this->flux2Service->setSteps($workflow, $image->steps);

        // Set seed
        $seed = $this->flux2Service->setSeed($workflow);

        // Set refine and inpainting options
        $this->flux2Service->setRefineEnabled($workflow, $image->refine_enabled);
        $this->flux2Service->setInpaintingEnabled($workflow, $image->inpainting_enabled);

        // Set batch size (number of images to generate)
        $this->flux2Service->setBatchSize($workflow, $image->number_of_images ?? 1);

        // Set images
        $this->flux2Service->setBaseImage($workflow, $uploadedImages['base']);

        if (! empty($uploadedImages['reference'])) {
            $this->flux2Service->setReferenceImage($workflow, $uploadedImages['reference']);
        }

        // Set additional images if any
        $additionalCount = count($uploadedImages['additional'] ?? []);
        if ($additionalCount > 0) {
            $this->flux2Service->setAdditionalImages($workflow, $uploadedImages['additional']);
        }

        // Remove unused image nodes (images 3, 4, 5) and rewire conditioning chain
        // This is critical - workflow will fail if LoadImage nodes reference non-existent images
        $this->flux2Service->removeUnusedImageNodes($workflow, $additionalCount);

        // Set LoRA based on method
        if ($image->method === GeneratedImage::METHOD_HEAD_SWAP) {
            $this->flux2Service->setHeadSwapLoRA($workflow, $this->flux2Service->getHeadSwapLoRA());
            $prompt = $this->flux2Service->buildHeadSwapPrompt($image->prompt);
        } else {
            // For generate method, use generation LoRA and build prompt
            $this->flux2Service->setGenerationLoRA($workflow, $this->flux2Service->getGenerationLoRA());
            $prompt = $this->flux2Service->buildGenerationPrompt($image->prompt ?? '');

            // If no reference image, configure workflow for single image only
            if (empty($uploadedImages['reference'])) {
                $this->flux2Service->configureForSingleImage($workflow);
            }
        }

        // Set prompt
        $this->flux2Service->setPrompt($workflow, $prompt);

        // Set filename prefix for output (avoid '/' to prevent subfolder creation)
        $filenamePrefix = "Flux2_{$image->user_id}_{$image->id}_".time();
        $this->flux2Service->setFilenamePrefix($workflow, $filenamePrefix);

        // Set webhook URL for completion notification
        // Note: passing 'PENDING' as prompt_id since we don't have it yet.
        // The webhook controller will use image_id to find the record.
        $webhookUrl = $this->flux2Service->getWebhookUrl();
        $this->flux2Service->setWebhookUrl($workflow, $webhookUrl, 'PENDING', $image->id);

        // If webhooks are disabled, explicitly disable the webhook node
        if (!$this->flux2Service->isWebhookEnabled()) {
            $this->flux2Service->disableWebhookNode($workflow);
            $image->addLog('Webhook disabled, will use polling for completion');
        } else {
            $image->addLog('Webhook enabled for completion notification');
        }

        $image->addLog('Workflow configured', [
            'megapixel' => $megapixel,
            'steps' => $image->steps,
            'seed' => $seed,
            'method' => $image->method,
            'prompt_length' => strlen($prompt),
            'uploaded_images' => $uploadedImages,
            'inpainting_enabled' => $image->inpainting_enabled,
            'refine_enabled' => $image->refine_enabled,
        ]);

        // Log workflow configuration for debugging
        Log::info('Flux2 workflow DEBUG - Full Configuration', [
            'image_id' => $image->id,
            'method' => $image->method,
            'base_image_path' => $image->base_image_path,
            'reference_image_path' => $image->reference_image_path,
            'db_refine_enabled' => $image->refine_enabled,
            'db_inpainting_enabled' => $image->inpainting_enabled,
            'node_151_base_image' => $workflow['151']['inputs']['image'] ?? 'NOT SET',
            'node_121_reference_image' => $workflow['121']['inputs']['image'] ?? 'NOT SET',
            'node_162_inpaint_switch' => $workflow['162']['inputs']['switch'] ?? 'NOT SET',
            'node_161_lora_name' => $workflow['161']['inputs']['lora_name'] ?? 'NOT SET',
            'node_9_exists' => isset($workflow['9']) ? 'YES' : 'NO',
            'node_173_exists' => isset($workflow['173']) ? 'YES' : 'NO',
            'node_185_webhook_exists' => isset($workflow['185']) ? 'YES' : 'NO',
            'node_185_webhook_url' => $workflow['185']['inputs']['webhook_url'] ?? 'NOT SET',
            'node_185_webhook_mode' => $workflow['185']['inputs']['mode'] ?? 'NOT SET',
            'node_185_webhook_any_input' => $workflow['185']['inputs']['any'] ?? 'NOT SET',
            'webhook_url_from_config' => $this->flux2Service->getWebhookUrl(),
            'prompt_used' => substr($prompt, 0, 200).'...',
        ]);
        // Submit workflow
        $result = $this->submitWorkflow($workflow, $image, $filenamePrefix);

        return $result;
    }

    /**
     * Upload images to ComfyUI and return filenames
     */
    private function uploadImages(GeneratedImage $image): array
    {
        $result = [
            'base' => null,
            'reference' => null,
            'additional' => [],
        ];

        // Upload base image
        $baseFilename = "flux2_{$image->id}_base_".time().'.png';
        $uploadedBase = $this->comfyUIService->uploadImageToComfyUI(
            $image->base_image_path,
            $baseFilename
        );

        if (! $uploadedBase) {
            throw new \Exception('Failed to upload base image to ComfyUI');
        }

        $result['base'] = $uploadedBase;
        $image->addLog('Uploaded base image', ['filename' => $uploadedBase]);

        // Upload reference image if present
        if ($image->reference_image_path) {
            $refFilename = "flux2_{$image->id}_ref_".time().'.png';
            $uploadedRef = $this->comfyUIService->uploadImageToComfyUI(
                $image->reference_image_path,
                $refFilename
            );

            if ($uploadedRef) {
                $result['reference'] = $uploadedRef;
                $image->addLog('Uploaded reference image', ['filename' => $uploadedRef]);
            }
        }

        // Upload additional images
        if (! empty($image->additional_images)) {
            foreach ($image->additional_images as $index => $path) {
                $addFilename = "flux2_{$image->id}_add".($index + 3).'_'.time().'.png';
                $uploadedAdd = $this->comfyUIService->uploadImageToComfyUI($path, $addFilename);

                if ($uploadedAdd) {
                    $result['additional'][] = $uploadedAdd;
                    $image->addLog('Uploaded additional image', [
                        'image_number' => $index + 3,
                        'filename' => $uploadedAdd,
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * Submit the workflow to ComfyUI
     */
    private function submitWorkflow(array $workflow, GeneratedImage $image, string $filenamePrefix): array
    {
        $serverUrl = $this->comfyUIService->getServerUrl();

        if (empty($serverUrl)) {
            throw new \Exception('ComfyUI server URL not configured');
        }

        $url = rtrim($serverUrl, '/').'/prompt';
        $requestBody = ['prompt' => $workflow];

        $image->addLog('Submitting workflow to ComfyUI', ['url' => $url]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(300)->post($url, $requestBody);

            if (! $response->successful()) {
                throw new \Exception('ComfyUI API request failed: '.$response->body());
            }

            $result = $response->json();
            $promptId = $result['prompt_id'] ?? null;

            if (! $promptId) {
                throw new \Exception('No prompt_id returned from ComfyUI');
            }

            $image->addLog('Workflow submitted', [
                'prompt_id' => $promptId,
                'number' => $result['number'] ?? null,
            ]);

            return [
                'prompt_id' => $promptId,
                'filename_prefix' => $filenamePrefix,
                'output_node' => $this->flux2Service->getOutputNodeId($image->refine_enabled),
                'server_url' => $serverUrl,
            ];

        } catch (\Exception $e) {
            Log::error('ImageGenerationService::submitWorkflow error', [
                'error' => $e->getMessage(),
                'image_id' => $image->id,
            ]);
            throw $e;
        }
    }

    /**
     * Handle webhook completion from ComfyUI
     */
    public function handleWebhookCompletion(string $promptId, array $outputs): GeneratedImage
    {
        $image = GeneratedImage::where('comfy_prompt_id', $promptId)->first();

        if (! $image) {
            throw new \Exception("No generated image found for prompt_id: {$promptId}");
        }

        if ($image->isComplete()) {
            Log::info('Image already complete, ignoring webhook', ['image_id' => $image->id]);

            return $image;
        }

        // Don't save yet - just track webhook receipt in memory
        $webhookReceivedAt = now();

        // Find output images (can be multiple)
        $outputNodeId = $this->flux2Service->getOutputNodeId($image->refine_enabled);
        $imageFiles = []; // Array of ['filename' => '...', 'subfolder' => '...']

        // Helper to extract filenames from outputs
        $extractFilenames = function ($outputs) use ($outputNodeId, &$imageFiles) {
            // Try expected output node first
            if (isset($outputs[$outputNodeId]['images'])) {
                foreach ($outputs[$outputNodeId]['images'] as $img) {
                    if (isset($img['filename'])) {
                        $imageFiles[] = [
                            'filename' => $img['filename'],
                            'subfolder' => $img['subfolder'] ?? '',
                        ];
                    }
                }
                return ! empty($imageFiles);
            }
            // Try any node with images
            foreach ($outputs as $nodeId => $nodeOutput) {
                if (isset($nodeOutput['images'])) {
                    foreach ($nodeOutput['images'] as $img) {
                        if (isset($img['filename'])) {
                            $imageFiles[] = [
                                'filename' => $img['filename'],
                                'subfolder' => $img['subfolder'] ?? '',
                            ];
                        }
                    }
                }
            }

            return ! empty($imageFiles);
        };

        // Try to extract from provided outputs
        if (! $extractFilenames($outputs)) {
            // If webhook outputs are empty (likely from Notif-Webhook), fetch actual history from ComfyUI
            Log::info('Webhook payload missing outputs, fetching history from ComfyUI', ['prompt_id' => $promptId]);

            // Retry logic to wait for ComfyUI to save history
            $maxRetries = 5;
            $retryDelay = 2; // seconds

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $history = $this->comfyUIService->getHistoryForPrompt($promptId);

                    Log::info('History fetch attempt', [
                        'prompt_id' => $promptId,
                        'attempt' => $attempt,
                        'has_data' => ! empty($history),
                    ]);

                    // History structure: { "prompt_id": { "outputs": { ... } } }
                    if (isset($history[$promptId]['outputs'])) {
                        $extractFilenames($history[$promptId]['outputs']);
                        if (! empty($imageFiles)) {
                            break; // Found the images
                        }
                    }

                    // If this isn't the last attempt, wait before retrying
                    if ($attempt < $maxRetries) {
                        sleep($retryDelay);
                        $retryDelay *= 2; // Exponential backoff
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch history during webhook handling', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep($retryDelay);
                        $retryDelay *= 2;
                    }
                }
            }
        }

        if (empty($imageFiles)) {
            Log::warning('No output images found after retries, dispatching polling job', [
                'image_id' => $image->id,
                'prompt_id' => $promptId,
            ]);

            // Save webhook receipt only - polling will handle completion
            $logs = $image->processing_log ?? [];
            $logs[] = [
                'timestamp' => now()->toIso8601String(),
                'message' => 'Webhook could not find images, polling will handle it',
            ];
            $image->processing_log = $logs;
            $image->webhook_received_at = $webhookReceivedAt;
            $image->save();

            return $image;
        }

        // Download all images from ComfyUI
        Log::info('Downloading output images', [
            'image_id' => $image->id,
            'count' => count($imageFiles),
        ]);

        $localPaths = [];
        foreach ($imageFiles as $imageFile) {
            $imageData = $this->comfyUIService->getImage(
                $imageFile['filename'],
                3,
                $imageFile['subfolder'],
                'output'
            );

            if (! $imageData) {
                Log::warning('Failed to download image, skipping', [
                    'filename' => $imageFile['filename'],
                ]);
                continue;
            }

            $localPaths[] = $this->storeImage($imageData, $image, count($localPaths));
        }

        if (empty($localPaths)) {
            // Mark as failed - images couldn't be downloaded
            $image->markAsFailed('Failed to download any output images from ComfyUI');

            return $image;
        }

        // Store all paths with webhook timestamp - everything saved atomically
        $this->storeMultiplePathsWithWebhook($image, $localPaths, $webhookReceivedAt);

        Log::info('Image generation completed via webhook', [
            'image_id' => $image->id,
            'result_count' => count($localPaths),
        ]);

        return $image;
    }

    /**
     * Poll for image completion (fallback if webhook fails)
     */
    public function pollForCompletion(GeneratedImage $image): bool
    {
        if (! $image->comfy_prompt_id) {
            return false;
        }

        // Check queue status
        $queueStatus = $this->comfyUIService->getQueueStatus($image->comfy_prompt_id);

        if ($queueStatus['in_queue'] || $queueStatus['running']) {
            return false; // Still processing
        }

        // Check history
        $history = $this->comfyUIService->getHistoryForPrompt($image->comfy_prompt_id);

        if (! $history || ! isset($history[$image->comfy_prompt_id])) {
            return false; // No history yet
        }

        $promptData = $history[$image->comfy_prompt_id];

        // Check for errors
        if (isset($promptData['status']['status_str']) && $promptData['status']['status_str'] === 'error') {
            $errorMessages = $promptData['status']['messages'] ?? [];
            $image->markAsFailed('ComfyUI error: '.json_encode($errorMessages));
            $this->syncLinkedCopyThumbnail($image);

            return true;
        }

        // Look for output images (can be multiple)
        $outputs = $promptData['outputs'] ?? [];
        $outputNodeId = $this->flux2Service->getOutputNodeId($image->refine_enabled);

        // Log all available outputs for debugging
        Log::info('ComfyUI outputs available', [
            'image_id' => $image->id,
            'output_node_id' => $outputNodeId,
            'available_nodes' => array_keys($outputs),
            'outputs_structure' => array_map(function ($output) {
                return array_keys($output);
            }, $outputs),
        ]);

        $imageFiles = []; // Array of ['filename' => '...', 'subfolder' => '...']

        // First try the expected output node
        if (isset($outputs[$outputNodeId]['images'])) {
            foreach ($outputs[$outputNodeId]['images'] as $img) {
                if (isset($img['filename'])) {
                    $imageFiles[] = [
                        'filename' => $img['filename'],
                        'subfolder' => $img['subfolder'] ?? '',
                    ];
                }
            }
            if (! empty($imageFiles)) {
                Log::info('Found outputs at expected node', ['node' => $outputNodeId, 'count' => count($imageFiles)]);
            }
        }

        // Fallback: try any SaveImage node output (nodes 9 or 173)
        if (empty($imageFiles)) {
            foreach (['9', '173'] as $saveNode) {
                if (isset($outputs[$saveNode]['images'])) {
                    foreach ($outputs[$saveNode]['images'] as $img) {
                        if (isset($img['filename'])) {
                            $imageFiles[] = [
                                'filename' => $img['filename'],
                                'subfolder' => $img['subfolder'] ?? '',
                            ];
                        }
                    }
                    if (! empty($imageFiles)) {
                        Log::info('Found outputs at fallback SaveImage node', ['node' => $saveNode, 'count' => count($imageFiles)]);
                        break;
                    }
                }
            }
        }

        // Still not found, try any node with images
        if (empty($imageFiles)) {
            foreach ($outputs as $nodeId => $nodeOutput) {
                if (isset($nodeOutput['images'])) {
                    foreach ($nodeOutput['images'] as $img) {
                        if (isset($img['filename'])) {
                            $imageFiles[] = [
                                'filename' => $img['filename'],
                                'subfolder' => $img['subfolder'] ?? '',
                            ];
                        }
                    }
                    if (! empty($imageFiles)) {
                        Log::info('Found outputs at fallback node', ['node' => $nodeId, 'count' => count($imageFiles)]);
                        break;
                    }
                }
            }
        }

        if (empty($imageFiles)) {
            return false; // No output yet
        }

        $image->addLog('Output images found via polling', [
            'count' => count($imageFiles),
        ]);

        // Download all images (type is 'output' for SaveImage nodes)
        Log::info('Downloading output images via polling', [
            'image_id' => $image->id,
            'count' => count($imageFiles),
        ]);

        $localPaths = [];
        foreach ($imageFiles as $imageFile) {
            $imageData = $this->comfyUIService->getImage(
                $imageFile['filename'],
                3,
                $imageFile['subfolder'],
                'output'
            );

            if (! $imageData) {
                Log::warning('Failed to download image, skipping', [
                    'filename' => $imageFile['filename'],
                ]);
                continue;
            }

            $localPaths[] = $this->storeImage($imageData, $image, count($localPaths));
        }

        if (empty($localPaths)) {
            $image->markAsFailed('Failed to download any output images from ComfyUI');
            $this->syncLinkedCopyThumbnail($image);

            return true;
        }

        // Store all paths
        $this->storeMultiplePaths($image, $localPaths);

        Log::info('Image generation completed via polling', [
            'image_id' => $image->id,
            'result_count' => count($localPaths),
        ]);

        return true;
    }

    /**
     * Store downloaded image to local storage
     */
    private function storeImage(string $imageData, GeneratedImage $image, int $index = 0): string
    {
        $timestamp = time();
        $suffix = $index > 0 ? "_{$index}" : '';
        $filename = "generated_{$image->id}_{$timestamp}{$suffix}.png";
        $path = "generated-images/{$image->user_id}/{$filename}";

        Storage::disk('public')->put($path, $imageData);

        Log::info('Stored generated image', [
            'image_id' => $image->id,
            'path' => $path,
            'size' => strlen($imageData),
            'index' => $index,
        ]);

        return $path;
    }

    /**
     * Store multiple image paths with webhook timestamp and mark as completed
     * Everything is saved atomically in one transaction
     */
    private function storeMultiplePathsWithWebhook(GeneratedImage $image, array $paths, $webhookReceivedAt): void
    {
        // Deduct credits for successful image generation (skip for copy thumbnails - already charged)
        if (! $this->isCopyThumbnailImage($image)) {
            $numberOfImages = $image->number_of_images ?? 1;
            $user = User::find($image->user_id);

            if ($user) {
                $this->creditService->deductCreditsForOperation(
                    $user,
                    CreditService::IMAGE_GENERATION_OPERATION,
                    $numberOfImages
                );

                Log::info('Credits deducted for successful image generation', [
                    'user_id' => $image->user_id,
                    'image_id' => $image->id,
                    'number_of_images' => $numberOfImages,
                ]);
            } else {
                Log::warning('User not found for credit deduction', [
                    'user_id' => $image->user_id,
                    'image_id' => $image->id,
                ]);
            }
        }

        // Add webhook received and completion log entries before saving
        $logs = $image->processing_log ?? [];
        $logs[] = [
            'timestamp' => $webhookReceivedAt->toIso8601String(),
            'message' => 'Webhook received',
            'context' => [],
        ];
        $logs[] = [
            'timestamp' => now()->toIso8601String(),
            'message' => 'Completed with images via webhook',
            'context' => [
                'count' => count($paths),
                'paths' => $paths,
            ],
        ];
        $image->processing_log = $logs;

        // Set webhook timestamp
        $image->webhook_received_at = $webhookReceivedAt;

        // Always use result_image_paths array (for single or multiple images)
        $image->result_image_paths = $paths;

        // Also store first path in result_image_path for backward compatibility
        $image->result_image_path = $paths[0] ?? null;

        // Mark as completed - save everything in one transaction
        $image->status = GeneratedImage::STATUS_COMPLETED;
        $image->save();

        // Sync linked CopyThumbnail if applicable
        $this->syncLinkedCopyThumbnail($image);
    }

    /**
     * Store multiple image paths and mark as completed (for polling path)
     * Everything is saved atomically in one transaction
     */
    private function storeMultiplePaths(GeneratedImage $image, array $paths): void
    {
        // Deduct credits for successful image generation (skip for copy thumbnails - already charged)
        if (! $this->isCopyThumbnailImage($image)) {
            $numberOfImages = $image->number_of_images ?? 1;
            $user = User::find($image->user_id);

            if ($user) {
                $this->creditService->deductCreditsForOperation(
                    $user,
                    CreditService::IMAGE_GENERATION_OPERATION,
                    $numberOfImages
                );

                Log::info('Credits deducted for successful image generation', [
                    'user_id' => $image->user_id,
                    'image_id' => $image->id,
                    'number_of_images' => $numberOfImages,
                ]);
            } else {
                Log::warning('User not found for credit deduction', [
                    'user_id' => $image->user_id,
                    'image_id' => $image->id,
                ]);
            }
        }

        // Add completion log entry before saving (to avoid double-save)
        $logs = $image->processing_log ?? [];
        $logs[] = [
            'timestamp' => now()->toIso8601String(),
            'message' => 'Completed with images',
            'context' => [
                'count' => count($paths),
                'paths' => $paths,
            ],
        ];
        $image->processing_log = $logs;

        // Always use result_image_paths array (for single or multiple images)
        $image->result_image_paths = $paths;

        // Also store first path in result_image_path for backward compatibility
        $image->result_image_path = $paths[0] ?? null;

        // Mark as completed - save everything in one transaction
        $image->status = GeneratedImage::STATUS_COMPLETED;
        $image->save();

        // Sync linked CopyThumbnail if applicable
        $this->syncLinkedCopyThumbnail($image);
    }

    /**
     * Check if a GeneratedImage was created from a CopyThumbnail
     */
    private function isCopyThumbnailImage(GeneratedImage $image): bool
    {
        return Thumbnail::copied()->where('generated_image_id', $image->id)->exists();
    }

    /**
     * Sync a linked CopyThumbnail when its GeneratedImage completes or fails
     */
    private function syncLinkedCopyThumbnail(GeneratedImage $image): void
    {
        // Sync copy thumbnails
        $copyThumbnail = Thumbnail::copied()->where('generated_image_id', $image->id)->first();

        if ($copyThumbnail) {
            if ($image->status === GeneratedImage::STATUS_COMPLETED && $image->result_image_path) {
                $copyThumbnail->status = Thumbnail::STATUS_COMPLETED;
                $copyThumbnail->result_image_path = $image->result_image_path;
                $copyThumbnail->comfy_prompt_id = $image->comfy_prompt_id;
                $copyThumbnail->save();
                $copyThumbnail->addLog('Completed via Flux2 pipeline', [
                    'generated_image_id' => $image->id,
                    'result_path' => $image->result_image_path,
                ]);

                Log::info('CopyThumbnail synced as completed', [
                    'copy_thumbnail_id' => $copyThumbnail->id,
                    'generated_image_id' => $image->id,
                ]);
            } elseif ($image->status === GeneratedImage::STATUS_FAILED) {
                $copyThumbnail->markAsFailed('Flux2 generation failed: ' . ($image->error_message ?? 'Unknown error'));

                Log::info('CopyThumbnail synced as failed', [
                    'copy_thumbnail_id' => $copyThumbnail->id,
                    'generated_image_id' => $image->id,
                ]);
            }
        }

        // Sync regular generated thumbnails (linked via generated_image_id)
        $generatedThumbnail = Thumbnail::generated()->where('generated_image_id', $image->id)->first();

        if ($generatedThumbnail) {
            if ($image->status === GeneratedImage::STATUS_COMPLETED && $image->result_image_path) {
                // Copy result image to thumbnails/ path format so getFileLocationAttribute works
                // The Thumbnail accessor expects: thumbnails/{userId}/{thumbnailId}/{filename}
                $originalPath = $image->result_image_path;
                $extension = pathinfo($originalPath, PATHINFO_EXTENSION) ?: 'png';
                $thumbnailPath = "thumbnails/{$generatedThumbnail->user_id}/{$generatedThumbnail->id}/{$generatedThumbnail->id}_flux2_" . time() . ".{$extension}";

                if (Storage::disk('public')->exists($originalPath)) {
                    Storage::disk('public')->copy($originalPath, $thumbnailPath);
                    Log::info('Copied Flux2 result to thumbnails path', [
                        'from' => $originalPath,
                        'to' => $thumbnailPath,
                    ]);
                } else {
                    // Fallback: use original path if copy fails
                    $thumbnailPath = $originalPath;
                    Log::warning('Could not copy Flux2 result, using original path', [
                        'original_path' => $originalPath,
                    ]);
                }

                $generatedThumbnail->update([
                    'file_location' => $thumbnailPath,
                    'status' => 'completed',
                    'comfy_prompt_id' => $image->comfy_prompt_id,
                ]);

                Log::info('Generated Thumbnail synced as completed via Flux2', [
                    'thumbnail_id' => $generatedThumbnail->id,
                    'generated_image_id' => $image->id,
                    'file_location' => $thumbnailPath,
                ]);
            } elseif ($image->status === GeneratedImage::STATUS_FAILED) {
                $generatedThumbnail->update([
                    'status' => 'failed',
                    'error_message' => 'Flux2 generation failed: ' . ($image->error_message ?? 'Unknown error'),
                ]);

                Log::info('Generated Thumbnail synced as failed via Flux2', [
                    'thumbnail_id' => $generatedThumbnail->id,
                    'generated_image_id' => $image->id,
                ]);
            }
        }
    }

    /**
     * Calculate initial poll delay for this image
     */
    public function calculatePollDelay(GeneratedImage $image): int
    {
        return $this->flux2Service->calculatePollDelay(
            $image->quality,
            $image->steps,
            $image->number_of_images ?? 1
        );
    }

    /**
     * Check if the image has timed out
     */
    public function hasTimedOut(GeneratedImage $image): bool
    {
        $timeout = $this->flux2Service->getTimeout();
        $startTime = $image->updated_at ?? $image->created_at;

        return now()->diffInSeconds($startTime) > $timeout;
    }
}
