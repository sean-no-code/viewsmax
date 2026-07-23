<?php

namespace App\Services;

use App\Services\Contracts\ThumbnailServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ThumbnailHelper
{
    private ThumbnailServiceInterface $thumbnailService;

    public function __construct(ThumbnailServiceInterface $thumbnailService)
    {
        $this->thumbnailService = $thumbnailService;
    }
    /**
     * Generate multiple thumbnail images using DALL-E
     *
     * @param string $description The description to base thumbnail on
     * @param int $userId The user ID for folder structure
     * @param int $thumbnailId The thumbnail ID for folder structure
     * @param int $numberOfThumbnails The number of thumbnails to generate
     * @return array Array with thumbnail local file paths
     * @throws \Exception
     */
    public function generateThumbnails(string $description, int $userId, int $thumbnailId, int $numberOfThumbnails = 1): array
    {
        Log::info("ThumbnailHelper::generateThumbnails started", [
            'description' => $description,
            'description_length' => strlen($description),
            'user_id' => $userId,
            'thumbnail_id' => $thumbnailId,
            'number_of_thumbnails' => $numberOfThumbnails
        ]);

        $apiKey = config('services.openai.api_key');
        
        if (empty($apiKey) || $apiKey === 'your-openai-api-key-here') {
            Log::error("OpenAI API key not configured properly");
            throw new \Exception('OpenAI API key not configured');
        }

        Log::info("API key validation passed, proceeding with thumbnail generation");

        // Get the visualizable scene from the database (already generated in controller)
        $thumbnail = \App\Models\Thumbnail::find($thumbnailId);
        
        // Generate multiple thumbnail images
        $imagePaths = [];
        $imageUrls = [];
        $prompt = null; // Store the prompt from the first successful generation
        
        Log::info("Starting multiple thumbnail image generation", [
            'description' => $description,
            'number_of_thumbnails' => $numberOfThumbnails
        ]);
        
        for ($i = 0; $i < $numberOfThumbnails; $i++) {
            try {
                Log::info("Generating thumbnail " . ($i + 1) . " of " . $numberOfThumbnails);
                $result = $this->thumbnailService->generateThumbnailImage($description, $thumbnail);
                $imageUrl = $result['image_url'];
                $currentPrompt = $result['prompt'];
                
                // Store the prompt from the first successful generation
                if ($prompt === null) {
                    $prompt = $currentPrompt;
                }
                
                $imageUrls[] = $imageUrl;
                
                // Download and store the image locally
                $localFilePath = $this->downloadAndStoreImage($imageUrl, $userId, $thumbnailId, $i + 1);
                $imagePaths[] = $localFilePath;
                
                Log::info("Thumbnail " . ($i + 1) . " generated and stored", [
                    'local_file_path' => $localFilePath,
                    'original_url' => $imageUrl,
                    'prompt_used' => $currentPrompt
                ]);
                
            } catch (\Exception $e) {
                Log::error('Thumbnail service failed for thumbnail ' . ($i + 1), [
                    'error_message' => $e->getMessage(),
                    'description' => $description,
                    'user_id' => $userId,
                    'thumbnail_id' => $thumbnailId,
                    'thumbnail_number' => $i + 1
                ]);
                // Continue with other thumbnails, but log the error
                continue;
            }
        }
        
        if (empty($imagePaths)) {
            throw new \Exception('Failed to generate any thumbnails');
        }

        Log::info("ThumbnailHelper::generateThumbnails completed successfully", [
            'total_images_generated' => count($imagePaths),
            'requested_thumbnails' => $numberOfThumbnails,
            'local_file_paths' => $imagePaths,
            'prompt_stored' => $prompt
        ]);

        // Store the visualizable scene, file location, and prompt in the thumbnail record
        $thumbnail = \App\Models\Thumbnail::find($thumbnailId);
        if ($thumbnail) {
            $updateData = [
                'file_location' => $imagePaths[0], // Use first thumbnail as primary file location
                'prompt' => $prompt
            ];
            
            $thumbnail->update($updateData);
            
            Log::info("Updated thumbnail with visualizable scene, file location, and prompt", [
                'thumbnail_id' => $thumbnailId,
                'file_location' => $imagePaths[0],
                'prompt' => $prompt
            ]);
        }

        return $imagePaths;
    }


    /**
     * Download image from URL and store locally
     *
     * @param string $imageUrl
     * @param int $userId
     * @param int $thumbnailId
     * @param int $thumbnailNumber
     * @return string Local file path
     * @throws \Exception
     */
    public function downloadAndStoreImage(string $imageUrl, int $userId, int $thumbnailId, int $thumbnailNumber = 1): string
    {
        Log::info("ThumbnailHelper::downloadAndStoreImage started", [
            'user_id' => $userId,
            'thumbnail_id' => $thumbnailId
        ]);

        try {
            // Create folder structure: thumbnails/user_id/thumbnail_id in public storage
            $folderPath = "thumbnails/{$userId}/{$thumbnailId}";
            
            Log::info("Creating folder structure", [
                'folder_path' => $folderPath,
                'storage_disk' => 'public'
            ]);

            // Ensure the directory exists in public storage
            if (!Storage::disk('public')->exists($folderPath)) {
                Storage::disk('public')->makeDirectory($folderPath);
                Log::info("Created directory", [
                    'folder_path' => $folderPath,
                    'storage_disk' => 'public'
                ]);
            }

            // Check if this is a data URL (base64 encoded image)
            if (str_starts_with($imageUrl, 'data:')) {
                Log::info("Processing data URL (base64 encoded image)", [
                    'image_url_length' => strlen($imageUrl),
                    'data_url_prefix' => substr($imageUrl, 0, 50) . '...'
                ]);

                // Parse data URL: data:image/png;base64,iVBORw0KGgo...
                $dataUrlParts = explode(',', $imageUrl, 2);
                if (count($dataUrlParts) !== 2) {
                    throw new \Exception('Invalid data URL format');
                }

                $header = $dataUrlParts[0]; // data:image/png;base64
                $data = $dataUrlParts[1]; // base64 encoded image data

                // Extract MIME type and file extension
                if (preg_match('/data:image\/([^;]+)/', $header, $matches)) {
                    $mimeType = $matches[1];
                    $extension = $mimeType === 'jpeg' ? 'jpg' : $mimeType;
                } else {
                    $extension = 'png'; // default
                }

                // Decode base64 data
                $imageData = base64_decode($data);
                if ($imageData === false) {
                    throw new \Exception('Failed to decode base64 image data');
                }

                // Resize the image to 1280x720 while preserving original format
                Log::info("Resizing data URL image to 1280x720", [
                    'original_format' => $mimeType ?? 'unknown'
                ]);
                $resizedResult = $this->resizeImage($imageData, 1280, 720, $mimeType ?? 'png');
                $resizedImageData = $resizedResult['data'];
                $preservedFormat = $resizedResult['format'];

                $timestamp = now()->format('Y-m-d_H-i-s');
                $fileName = "{$thumbnailId}_{$thumbnailNumber}_{$timestamp}.{$preservedFormat}";
                $filePath = "{$folderPath}/{$fileName}";

                Log::info("Storing resized data URL image file", [
                    'file_path' => $filePath,
                    'original_size' => strlen($imageData),
                    'resized_size' => strlen($resizedImageData),
                    'original_format' => $mimeType ?? 'unknown',
                    'preserved_format' => $preservedFormat,
                    'storage_disk' => 'public'
                ]);

                // Store the resized image data
                Storage::disk('public')->put($filePath, $resizedImageData);

            } else {
                // Regular HTTP URL - download the image
                Log::info("Downloading image from HTTP URL", [
                    'timeout' => 60
                ]);

                $response = Http::timeout(60)->get($imageUrl);

                if (!$response->successful()) {
                    Log::error("Failed to download image", [
                        'status_code' => $response->status(),
                        'image_url' => $imageUrl
                    ]);
                    throw new \Exception('Failed to download image: HTTP ' . $response->status());
                }

                // Get file extension from URL to preserve original format
                $extension = $this->getFileExtensionFromUrl($imageUrl);
                
                // Resize the downloaded image to 1280x720 while preserving original format
                Log::info("Resizing HTTP image to 1280x720", [
                    'original_format' => $extension
                ]);
                $resizedResult = $this->resizeImage($response->body(), 1280, 720, $extension);
                $resizedImageData = $resizedResult['data'];
                $preservedFormat = $resizedResult['format'];

                $timestamp = now()->format('Y-m-d_H-i-s');
                $fileName = "{$thumbnailId}_{$thumbnailNumber}_{$timestamp}.{$preservedFormat}";
                $filePath = "{$folderPath}/{$fileName}";

                Log::info("Storing resized HTTP image file", [
                    'file_path' => $filePath,
                    'original_size' => strlen($response->body()),
                    'resized_size' => strlen($resizedImageData),
                    'original_format' => $extension,
                    'preserved_format' => $preservedFormat,
                    'storage_disk' => 'public'
                ]);

                // Store the resized file in public storage
                Storage::disk('public')->put($filePath, $resizedImageData);
            }

            // Verify the file was stored
            if (!Storage::disk('public')->exists($filePath)) {
                Log::error("File storage verification failed", [
                    'file_path' => $filePath,
                    'storage_disk' => 'public'
                ]);
                throw new \Exception('Failed to store image file');
            }

            $fileSize = Storage::disk('public')->size($filePath);
            
            Log::info("Image downloaded and stored successfully", [
                'file_path' => $filePath,
                'file_size' => $fileSize
            ]);

            return $filePath;

        } catch (\Exception $e) {
            Log::error('ThumbnailHelper::downloadAndStoreImage error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'user_id' => $userId,
                'thumbnail_id' => $thumbnailId
            ]);
            throw $e;
        }
    }

    /**
     * Resize image to specified dimensions while preserving original format
     *
     * @param string $imageData Binary image data
     * @param int $targetWidth Target width (default: 1280)
     * @param int $targetHeight Target height (default: 720)
     * @param string $originalFormat Original image format (jpg, png, gif, webp)
     * @return array Array with 'data' and 'format' keys
     * @throws \Exception
     */
    private function resizeImage(string $imageData, int $targetWidth = 1280, int $targetHeight = 720, string $originalFormat = 'png'): array
    {
        Log::info("ThumbnailHelper::resizeImage started", [
            'target_width' => $targetWidth,
            'target_height' => $targetHeight,
            'original_size' => strlen($imageData),
            'original_format' => $originalFormat
        ]);

        // Create image resource from binary data
        $sourceImage = imagecreatefromstring($imageData);
        if ($sourceImage === false) {
            throw new \Exception('Failed to create image resource from binary data');
        }

        // Get original dimensions
        $originalWidth = imagesx($sourceImage);
        $originalHeight = imagesy($sourceImage);

        Log::info("Original image dimensions", [
            'original_width' => $originalWidth,
            'original_height' => $originalHeight,
            'aspect_ratio' => $originalWidth / $originalHeight
        ]);

        // Create target image with specified dimensions
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($targetImage === false) {
            imagedestroy($sourceImage);
            throw new \Exception('Failed to create target image resource');
        }

        // Preserve transparency for PNG and GIF images
        if (in_array($originalFormat, ['png', 'gif'])) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            imagefill($targetImage, 0, 0, $transparent);
        }

        // Resize the image
        $resizeResult = imagecopyresampled(
            $targetImage,    // destination image
            $sourceImage,    // source image
            0, 0,           // destination x, y
            0, 0,           // source x, y
            $targetWidth,    // destination width
            $targetHeight,   // destination height
            $originalWidth,  // source width
            $originalHeight  // source height
        );

        if (!$resizeResult) {
            imagedestroy($sourceImage);
            imagedestroy($targetImage);
            throw new \Exception('Failed to resize image');
        }

        // Capture the resized image data in original format
        ob_start();
        
        switch (strtolower($originalFormat)) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($targetImage, null, 90); // High quality JPEG
                break;
            case 'png':
                imagepng($targetImage, null, 9); // High quality PNG
                break;
            case 'gif':
                imagegif($targetImage);
                break;
            case 'webp':
                imagewebp($targetImage, null, 90); // High quality WebP
                break;
            default:
                // Fallback to PNG
                imagepng($targetImage, null, 9);
                $originalFormat = 'png';
                break;
        }
        
        $resizedImageData = ob_get_contents();
        ob_end_clean();

        // Clean up memory
        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        Log::info("Image resized successfully", [
            'original_size' => strlen($imageData),
            'resized_size' => strlen($resizedImageData),
            'target_width' => $targetWidth,
            'target_height' => $targetHeight,
            'preserved_format' => $originalFormat
        ]);

        return [
            'data' => $resizedImageData,
            'format' => $originalFormat
        ];
    }

    /**
     * Extract file extension from URL
     *
     * @param string $url
     * @return string
     */
    private function getFileExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // Default to png if no extension found
        return $extension ?: 'png';
    }


    /**
     * Generate thumbnails with fallback if it fails
     *
     * @param string $description
     * @param int $userId
     * @param int $thumbnailId
     * @param int $numberOfThumbnails
     * @return array
     */
    public function generateThumbnailsWithFallback(string $description, int $userId, int $thumbnailId, int $numberOfThumbnails = 1): array
    {
        Log::info("ThumbnailHelper::generateThumbnailsWithFallback started", [
            'description' => $description,
            'description_length' => strlen($description),
            'user_id' => $userId,
            'thumbnail_id' => $thumbnailId
        ]);

        // Just call the main generateThumbnails method - let exceptions bubble up
        // The job will handle setting status to 'failed'
        return $this->generateThumbnails($description, $userId, $thumbnailId, $numberOfThumbnails);
    }

    /**
     * Create a fallback placeholder image
     *
     * @param string $description
     * @param int $userId
     * @param int $thumbnailId
     * @return string
     */
    private function createFallbackImage(string $description, int $userId, int $thumbnailId): string
    {
        Log::info("Creating fallback placeholder image", [
            'description' => $description,
            'user_id' => $userId,
            'thumbnail_id' => $thumbnailId
        ]);

        // Create folder structure: thumbnails/user_id/thumbnail_id in public storage
        $folderPath = "thumbnails/{$userId}/{$thumbnailId}";
        
        // Ensure the directory exists in public storage
        if (!Storage::disk('public')->exists($folderPath)) {
            Storage::disk('public')->makeDirectory($folderPath);
        }

        // Create a simple placeholder file
        $fileName = "thumbnail_placeholder.txt";
        $filePath = "{$folderPath}/{$fileName}";
        
        $placeholderContent = "Thumbnail placeholder for: {$description}\nGenerated at: " . now()->toISOString();
        
        Storage::disk('public')->put($filePath, $placeholderContent);
        
        Log::info("Fallback placeholder created", [
            'file_path' => $filePath,
            'content_length' => strlen($placeholderContent)
        ]);
        
        return $filePath;
    }

    /**
     * Generate thumbnails and return JSON response
     *
     * @param string $description
     * @return array
     */
    public function generateThumbnailsJson(string $description): array
    {
        try {
            $result = $this->thumbnailService->generateThumbnailImage($description);
            $imageUrl = $result['image_url'];
            $prompt = $result['prompt'];
            
            return [
                'success' => true,
                'data' => [
                    'image_urls' => [$imageUrl],
                    'count' => 1,
                    'description' => $description,
                    'prompt' => $prompt,
                    'type' => 'images'
                ],
                'message' => 'Thumbnail image generated successfully with DALL-E'
            ];
        } catch (\Exception $e) {
            Log::error('ThumbnailHelper::generateThumbnailsJson error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'data' => null,
                'message' => 'Failed to generate thumbnail image: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate thumbnails with fallback and return JSON response
     *
     * @param string $description
     * @return array
     */
    public function generateThumbnailsWithFallbackJson(string $description): array
    {
        try {
            $result = $this->thumbnailService->generateThumbnailImage($description);
            $imageUrl = $result['image_url'];
            $prompt = $result['prompt'];
            
            return [
                'success' => true,
                'data' => [
                    'image_urls' => [$imageUrl],
                    'count' => 1,
                    'description' => $description,
                    'prompt' => $prompt,
                    'source' => 'dall-e',
                    'type' => 'images'
                ],
                'message' => 'Thumbnail image generated successfully with DALL-E'
            ];
        } catch (\Exception $e) {
            Log::error('Thumbnail generation failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'data' => null,
                'message' => 'Failed to generate thumbnail image: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
}
