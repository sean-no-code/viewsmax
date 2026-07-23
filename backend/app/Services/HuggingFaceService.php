<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HuggingFaceService
{
    private ?string $apiKey;
    private string $apiUrl;
    private string $namespace;

    public function __construct()
    {
        $this->apiKey = config('services.huggingface.api_key');
        $this->apiUrl = config('services.huggingface.api_url') ?: 'https://huggingface.co/api';
        $this->namespace = config('services.huggingface.namespace');
    }

    /**
     * Create a new model on Hugging Face
     *
     * @param string $modelName
     * @param string $description
     * @param array $tags
     * @return array|null
     */
    public function createModel(string $modelName, string $description = '', array $tags = []): ?array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Hugging Face API key not configured');
        }

        $modelId = $this->namespace . '/' . $modelName;
        
        Log::info('Creating Hugging Face model', [
            'model_id' => $modelId,
            'description' => $description,
            'tags' => $tags
        ]);

        $payload = [
            'name' => $modelName,
            'description' => $description ?: "AI model created by ViewsMax",
            'tags' => array_merge(['lora', 'ai-model'], $tags),
            'private' => true, // Create as private repository
            'type' => 'model', // Explicitly set as model type
            'card_data' => [
                'language' => ['en'],
                'license' => 'mit',
                'library_name' => 'diffusers',
                'pipeline_tag' => 'text-to-image'
            ]
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->apiUrl . '/repos/create', $payload);

            if (!$response->successful()) {
                Log::error('Hugging Face model creation failed', [
                    'model_id' => $modelId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                
                // If model already exists, that's okay
                if ($response->status() === 409) {
                    Log::info('Hugging Face model already exists', [
                        'model_id' => $modelId
                    ]);
                    return [
                        'id' => $modelId,
                        'url' => 'https://huggingface.co/' . $modelId,
                        'status' => 'exists'
                    ];
                }
                
                throw new \Exception('Hugging Face model creation failed: ' . $response->body());
            }

            $responseData = $response->json();
            
            Log::info('Hugging Face model created successfully', [
                'model_id' => $modelId,
                'response' => $responseData
            ]);

            return [
                'id' => $modelId,
                'url' => 'https://huggingface.co/' . $modelId,
                'status' => 'created',
                'data' => $responseData
            ];

        } catch (\Exception $e) {
            Log::error('Hugging Face service error', [
                'model_id' => $modelId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if a model exists on Hugging Face
     *
     * @param string $modelName
     * @return bool
     */
    public function modelExists(string $modelName): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        $modelId = $this->namespace . '/' . $modelName;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get("https://huggingface.co/api/models/{$modelId}");

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Error checking Hugging Face model existence', [
                'model_id' => $modelId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get model information from Hugging Face
     *
     * @param string $modelName
     * @return array|null
     */
    public function getModel(string $modelName): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $modelId = $this->namespace . '/' . $modelName;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get("https://huggingface.co/api/models/{$modelId}");

            if (!$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::warning('Error fetching Hugging Face model', [
                'model_id' => $modelId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate a unique model name based on AI model ID and user ID
     *
     * @param int $aiModelId
     * @param int $userId
     * @return string
     */
    public function generateModelName(int $aiModelId, int $userId): string
    {
        return 'ai-model-' . $userId . '-' . $aiModelId . '-' . time();
    }

    /**
     * Verify that a model repository exists and is accessible
     *
     * @param string $modelName
     * @return bool
     */
    public function verifyModelAccess(string $modelName): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        $modelId = $this->namespace . '/' . $modelName;

        try {
            // Try to access the model
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->timeout(30)->get("https://huggingface.co/api/models/{$modelId}");

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Hugging Face model verified', [
                    'model_id' => $modelId,
                    'private' => $data['private'] ?? 'unknown',
                    'type' => $data['type'] ?? 'unknown'
                ]);
                return true;
            }

            Log::warning('Hugging Face model verification failed', [
                'model_id' => $modelId,
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);
            return false;

        } catch (\Exception $e) {
            Log::warning('Error verifying Hugging Face model access', [
                'model_id' => $modelId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get the full model ID (namespace/model-name)
     *
     * @param string $modelName
     * @return string
     */
    public function getModelId(string $modelName): string
    {
        return $this->namespace . '/' . $modelName;
    }

    /**
     * List models in the configured namespace
     *
     * @return array|null
     */
    public function listModels(): ?array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Hugging Face API key not configured');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->get($this->apiUrl . '/models', [
                'author' => $this->namespace,
                'limit' => 100
            ]);

            if (!$response->successful()) {
                Log::error('Failed to list Hugging Face models', [
                    'namespace' => $this->namespace,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return null;
            }

            $responseData = $response->json();
            
            Log::info('Hugging Face models listed successfully', [
                'namespace' => $this->namespace,
                'count' => count($responseData)
            ]);

            return $responseData;

        } catch (\Exception $e) {
            Log::error('Hugging Face service error during model listing', [
                'namespace' => $this->namespace,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Upload files to a Hugging Face model repository
     *
     * @param string $modelName
     * @param array $files Array of file paths or URLs to upload
     * @return bool
     */
    public function uploadFiles(string $modelName, array $files): bool
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Hugging Face API key not configured');
        }

        $modelId = $this->namespace . '/' . $modelName;
        
        Log::info('Uploading files to Hugging Face model', [
            'model_id' => $modelId,
            'file_count' => count($files)
        ]);

        $successCount = 0;
        $failCount = 0;
        $failedFiles = [];

        foreach ($files as $file) {
            $success = $this->uploadSingleFile($modelId, $file);
            
            if ($success) {
                $successCount++;
            } else {
                $failCount++;
                $failedFiles[] = $file;
            }
        }

        if ($failCount > 0) {
            Log::error('Some files failed to upload to Hugging Face', [
                'model_id' => $modelId,
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'failed_files' => $failedFiles
            ]);
            return false;
        }

        Log::info('All files uploaded successfully', [
            'model_id' => $modelId,
            'file_count' => count($files),
            'success_count' => $successCount
        ]);

        return true;
    }

    /**
     * Upload files to a Hugging Face model repository with specific filenames
     *
     * @param string $modelName
     * @param array $files Array of [desired_filename => local_path] pairs
     * @return bool
     */
    public function uploadFilesWithNames(string $modelName, array $files): bool
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Hugging Face API key not configured');
        }

        $modelId = $this->namespace . '/' . $modelName;
        
        Log::info('Uploading files with specific names to Hugging Face model', [
            'model_id' => $modelId,
            'file_count' => count($files),
            'filenames' => array_keys($files)
        ]);

        $successCount = 0;
        $failCount = 0;
        $failedFiles = [];

        foreach ($files as $targetFilename => $localPath) {
            $success = $this->uploadFileWithName($modelId, $localPath, $targetFilename);
            
            if ($success) {
                $successCount++;
            } else {
                $failCount++;
                $failedFiles[] = $targetFilename;
            }
        }

        if ($failCount > 0) {
            Log::error('Some files failed to upload to Hugging Face', [
                'model_id' => $modelId,
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'failed_files' => $failedFiles
            ]);
            return false;
        }

        Log::info('All files uploaded successfully with specific names', [
            'model_id' => $modelId,
            'file_count' => count($files),
            'success_count' => $successCount
        ]);

        return true;
    }

    /**
     * Upload a file to HuggingFace with a specific target filename
     *
     * @param string $modelId
     * @param string $localPath
     * @param string $targetFilename
     * @return bool
     */
    private function uploadFileWithName(string $modelId, string $localPath, string $targetFilename): bool
    {
        try {
            // Check file exists and is not empty
            if (!file_exists($localPath)) {
                throw new \Exception("File does not exist: {$localPath}");
            }
            
            $fileSize = filesize($localPath);
            if ($fileSize === 0) {
                throw new \Exception("File is empty: {$localPath}");
            }

            Log::info('Uploading file with specific name to Hugging Face', [
                'model_id' => $modelId,
                'local_path' => $localPath,
                'target_filename' => $targetFilename,
                'file_size' => $fileSize,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);

            // For large files (> 10MB), use LFS upload
            if ($fileSize > 10 * 1024 * 1024) {
                return $this->uploadWithLfs($modelId, $localPath, $targetFilename);
            } else {
                // For smaller files, use the commit API with base64 content
                return $this->uploadWithCommitApi($modelId, $localPath, $targetFilename);
            }

        } catch (\Exception $e) {
            Log::error('Exception during file upload with name', [
                'model_id' => $modelId,
                'local_path' => $localPath,
                'target_filename' => $targetFilename,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Upload a single file to a Hugging Face model repository
     *
     * @param string $modelId
     * @param string $filePathOrUrl
     * @return bool
     */
    private function uploadSingleFile(string $modelId, string $filePathOrUrl): bool
    {
        $tempFileToCleanup = null;
        
        try {
            $filePath = $filePathOrUrl;
            $filename = null;
            
            // Determine if it's a URL or local file path
            if (filter_var($filePathOrUrl, FILTER_VALIDATE_URL)) {
                // Download file from URL first
                $downloadResult = $this->downloadFileFromUrl($filePathOrUrl);
                if (!$downloadResult) {
                    throw new \Exception("Failed to download file from URL: {$filePathOrUrl}");
                }
                $filePath = $downloadResult['path'];
                $filename = $downloadResult['original_filename'];
                $tempFileToCleanup = $filePath; // Mark for cleanup
            } else {
                $filename = basename($filePath);
            }
            
            // Check file size
            $fileSize = filesize($filePath);
            if ($fileSize === false || $fileSize === 0) {
                throw new \Exception("File is empty or failed to get file size: {$filePath}");
            }

            Log::info('Uploading file to Hugging Face', [
                'model_id' => $modelId,
                'filename' => $filename,
                'file_size' => $fileSize,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);

            // For large files (> 10MB), use LFS upload
            if ($fileSize > 10 * 1024 * 1024) {
                $result = $this->uploadWithLfs($modelId, $filePath, $filename);
            } else {
                // For smaller files, use the commit API with base64 content
                $result = $this->uploadWithCommitApi($modelId, $filePath, $filename);
            }
            
            // Cleanup temp file if we downloaded it
            if ($tempFileToCleanup && file_exists($tempFileToCleanup)) {
                unlink($tempFileToCleanup);
            }
            
            return $result;

        } catch (\Exception $e) {
            Log::error('Exception during file upload', [
                'model_id' => $modelId,
                'file_path' => $filePathOrUrl,
                'error' => $e->getMessage()
            ]);
            
            // Cleanup temp file on error
            if ($tempFileToCleanup && file_exists($tempFileToCleanup)) {
                unlink($tempFileToCleanup);
            }
            
            return false;
        }
    }
    
    /**
     * Upload file using the Hugging Face commit API (for small files < 10MB)
     *
     * @param string $modelId
     * @param string $filePath
     * @param string $filename
     * @return bool
     */
    private function uploadWithCommitApi(string $modelId, string $filePath, string $filename): bool
    {
        try {
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                throw new \Exception("Failed to read file: {$filePath}");
            }
            
            $base64Content = base64_encode($fileContent);
            
            // Use the commit API endpoint
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("https://huggingface.co/api/models/{$modelId}/commit/main", [
                'summary' => "Upload {$filename}",
                'files' => [
                    [
                        'path' => $filename,
                        'content' => $base64Content,
                        'encoding' => 'base64'
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Failed to upload file via commit API', [
                    'model_id' => $modelId,
                    'filename' => $filename,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return false;
            }

            Log::info('File uploaded successfully via commit API', [
                'model_id' => $modelId,
                'filename' => $filename
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Exception during commit API upload', [
                'model_id' => $modelId,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Upload large file using Hugging Face LFS protocol
     *
     * @param string $modelId
     * @param string $filePath
     * @param string $filename
     * @return bool
     */
    private function uploadWithLfs(string $modelId, string $filePath, string $filename): bool
    {
        try {
            $fileSize = filesize($filePath);
            
            // Calculate SHA256 hash of the file
            $sha256 = hash_file('sha256', $filePath);
            
            Log::info('Starting LFS upload', [
                'model_id' => $modelId,
                'filename' => $filename,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'sha256' => $sha256
            ]);
            
            // Step 1: Request LFS upload URL from Hugging Face
            $lfsResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/vnd.git-lfs+json',
            ])->timeout(60)->post("https://huggingface.co/{$modelId}.git/info/lfs/objects/batch", [
                'operation' => 'upload',
                'transfers' => ['basic'],
                'objects' => [
                    [
                        'oid' => $sha256,
                        'size' => $fileSize
                    ]
                ],
                'hash_algo' => 'sha256'
            ]);
            
            if (!$lfsResponse->successful()) {
                Log::error('Failed to get LFS upload URL', [
                    'model_id' => $modelId,
                    'filename' => $filename,
                    'status_code' => $lfsResponse->status(),
                    'response_body' => $lfsResponse->body()
                ]);
                return false;
            }
            
            $lfsData = $lfsResponse->json();
            $objects = $lfsData['objects'] ?? [];
            
            if (empty($objects)) {
                Log::error('No LFS objects returned', [
                    'model_id' => $modelId,
                    'lfs_response' => $lfsData
                ]);
                return false;
            }
            
            $object = $objects[0];
            
            // Check if file already exists (no upload needed)
            if (isset($object['actions']) === false) {
                Log::info('File already exists in LFS storage', [
                    'model_id' => $modelId,
                    'filename' => $filename,
                    'sha256' => $sha256
                ]);
            } else {
                // Step 2: Upload file to the LFS storage URL
                $uploadAction = $object['actions']['upload'] ?? null;
                
                if (!$uploadAction) {
                    Log::error('No upload action in LFS response', [
                        'model_id' => $modelId,
                        'object' => $object
                    ]);
                    return false;
                }
                
                $uploadUrl = $uploadAction['href'];
                $uploadHeaders = $uploadAction['header'] ?? [];
                
                Log::info('Uploading file to LFS storage', [
                    'model_id' => $modelId,
                    'filename' => $filename,
                    'upload_url' => substr($uploadUrl, 0, 100) . '...'
                ]);
                
                // Use cURL for large file upload
                $ch = curl_init($uploadUrl);
                $fileHandle = fopen($filePath, 'rb');
                
                $headers = [];
                foreach ($uploadHeaders as $key => $value) {
                    $headers[] = "{$key}: {$value}";
                }
                $headers[] = 'Content-Type: application/octet-stream';
                
                curl_setopt_array($ch, [
                    CURLOPT_PUT => true,
                    CURLOPT_INFILE => $fileHandle,
                    CURLOPT_INFILESIZE => $fileSize,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 600, // 10 minutes
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($filename, $fileSize) {
                        if ($fileSize > 0 && $uploaded % (10 * 1024 * 1024) < 65536) { // Log every ~10MB
                            $percent = round(($uploaded / $fileSize) * 100, 1);
                            Log::debug('LFS upload progress', [
                                'filename' => $filename,
                                'uploaded_mb' => round($uploaded / 1024 / 1024, 2),
                                'total_mb' => round($fileSize / 1024 / 1024, 2),
                                'percent' => $percent
                            ]);
                        }
                        return 0;
                    },
                ]);
                
                $uploadResult = curl_exec($ch);
                $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $uploadError = curl_error($ch);
                
                fclose($fileHandle);
                curl_close($ch);
                
                if ($uploadHttpCode < 200 || $uploadHttpCode >= 300) {
                    Log::error('Failed to upload file to LFS storage', [
                        'model_id' => $modelId,
                        'filename' => $filename,
                        'http_code' => $uploadHttpCode,
                        'error' => $uploadError,
                        'response' => $uploadResult
                    ]);
                    return false;
                }
                
                // Step 3: Verify upload if needed
                $verifyAction = $object['actions']['verify'] ?? null;
                if ($verifyAction) {
                    $verifyResponse = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/vnd.git-lfs+json',
                    ])->timeout(30)->post($verifyAction['href'], [
                        'oid' => $sha256,
                        'size' => $fileSize
                    ]);
                    
                    if (!$verifyResponse->successful()) {
                        Log::warning('LFS verify failed but upload may still be ok', [
                            'model_id' => $modelId,
                            'filename' => $filename,
                            'status_code' => $verifyResponse->status()
                        ]);
                    }
                }
            }
            
            // Step 4: Create a commit to add the LFS pointer file
            $lfsPointer = "version https://git-lfs.github.com/spec/v1\n" .
                         "oid sha256:{$sha256}\n" .
                         "size {$fileSize}\n";
            
            $commitResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("https://huggingface.co/api/models/{$modelId}/commit/main", [
                'summary' => "Upload {$filename} via LFS",
                'files' => [
                    [
                        'path' => $filename,
                        'content' => base64_encode($lfsPointer),
                        'encoding' => 'base64',
                        'lfs' => [
                            'oid' => $sha256,
                            'size' => $fileSize
                        ]
                    ]
                ]
            ]);
            
            if (!$commitResponse->successful()) {
                Log::error('Failed to create LFS commit', [
                    'model_id' => $modelId,
                    'filename' => $filename,
                    'status_code' => $commitResponse->status(),
                    'response_body' => $commitResponse->body()
                ]);
                return false;
            }
            
            Log::info('File uploaded successfully via LFS', [
                'model_id' => $modelId,
                'filename' => $filename,
                'sha256' => $sha256,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);
            
            return true;

        } catch (\Exception $e) {
            Log::error('Exception during LFS upload', [
                'model_id' => $modelId,
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Download file from URL to temporary location
     *
     * @param string $url
     * @return array|null Returns array with 'path' and 'original_filename' or null on failure
     */
    private function downloadFileFromUrl(string $url): ?array
    {
        try {
            Log::info('Downloading file from URL', [
                'url' => $url
            ]);

            // Extract original filename from URL
            $parsedUrl = parse_url($url);
            $originalFilename = basename($parsedUrl['path'] ?? 'model_file');
            if (empty($originalFilename) || $originalFilename === '/') {
                $originalFilename = 'model_file';
            }

            // Create temporary file with a meaningful extension
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $tempFile = tempnam(sys_get_temp_dir(), 'hf_upload_');
            if ($extension) {
                $newTempFile = $tempFile . '.' . $extension;
                rename($tempFile, $newTempFile);
                $tempFile = $newTempFile;
            }

            Log::info('Starting file download with cURL', [
                'url' => $url,
                'original_filename' => $originalFilename,
                'temp_file' => $tempFile
            ]);

            // Use cURL for reliable large file downloads with progress
            $ch = curl_init($url);
            $fileHandle = fopen($tempFile, 'wb');
            
            if (!$fileHandle) {
                throw new \Exception("Failed to create temporary file: {$tempFile}");
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fileHandle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 600, // 10 minutes for large files
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_FAILONERROR => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) use ($originalFilename) {
                    if ($downloadSize > 0) {
                        $percent = round(($downloaded / $downloadSize) * 100, 1);
                        if ($downloaded % (5 * 1024 * 1024) < 8192) { // Log every ~5MB
                            Log::debug('Download progress', [
                                'filename' => $originalFilename,
                                'downloaded_mb' => round($downloaded / 1024 / 1024, 2),
                                'total_mb' => round($downloadSize / 1024 / 1024, 2),
                                'percent' => $percent
                            ]);
                        }
                    }
                    return 0; // Return 0 to continue, non-zero to abort
                },
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            
            fclose($fileHandle);
            curl_close($ch);

            if (!$success || $httpCode >= 400) {
                Log::error('Failed to download file from URL', [
                    'url' => $url,
                    'http_code' => $httpCode,
                    'curl_error' => $error,
                    'curl_errno' => $errno
                ]);
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                return null;
            }

            $fileSize = filesize($tempFile);
            
            if ($fileSize === 0) {
                Log::error('Downloaded file is empty', [
                    'url' => $url,
                    'temp_file' => $tempFile
                ]);
                unlink($tempFile);
                return null;
            }

            Log::info('File downloaded successfully', [
                'url' => $url,
                'original_filename' => $originalFilename,
                'temp_file' => $tempFile,
                'file_size' => $fileSize,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);

            return [
                'path' => $tempFile,
                'original_filename' => $originalFilename
            ];

        } catch (\Exception $e) {
            Log::error('Exception during file download', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
