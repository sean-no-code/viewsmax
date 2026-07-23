<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Services\HuggingFaceService;
use App\Services\ReplicateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushReplicateModelToHuggingFace implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout
    public $tries = 3; // Retry up to 3 times

    private int $aiModelId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $aiModelId)
    {
        $this->aiModelId = $aiModelId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('PushReplicateModelToHuggingFace job started', [
                'ai_model_id' => $this->aiModelId,
                'job_class' => self::class,
                'timestamp' => now()->toISOString()
            ]);

            // Find the AI model
            $aiModel = AiModel::findOrFail($this->aiModelId);
            
            Log::info('AI Model found for file push', [
                'ai_model_id' => $aiModel->id,
                'name' => $aiModel->name,
                'status' => $aiModel->status,
                'replicates_prediction_id' => $aiModel->replicates_prediction_id,
                'huggingface_model_id' => $aiModel->huggingface_model_id
            ]);

            // Check if we have the required IDs
            if (!$aiModel->replicates_prediction_id || !$aiModel->huggingface_model_id) {
                Log::error('Missing required IDs for file push', [
                    'ai_model_id' => $aiModel->id,
                    'replicates_prediction_id' => $aiModel->replicates_prediction_id,
                    'huggingface_model_id' => $aiModel->huggingface_model_id
                ]);
                throw new \Exception('Missing Replicate prediction ID or Hugging Face model ID');
            }

            // Get model files from Replicate
            $replicateService = new ReplicateService();
            $modelFiles = $replicateService->getModelFiles($aiModel->replicates_prediction_id);

            if (!$modelFiles || empty($modelFiles)) {
                Log::error('No model files found from Replicate', [
                    'ai_model_id' => $aiModel->id,
                    'prediction_id' => $aiModel->replicates_prediction_id
                ]);
                throw new \Exception('No model files found from Replicate');
            }

            Log::info('Retrieved model files from Replicate', [
                'ai_model_id' => $aiModel->id,
                'file_count' => count($modelFiles),
                'files' => $modelFiles
            ]);

            // Extract model name from Hugging Face model ID
            $hfModelName = basename($aiModel->huggingface_model_id);
            
            // Process model files - extract lora.safetensors from tar if needed
            $filesToUpload = $this->processModelFiles($modelFiles);
            
            if (empty($filesToUpload)) {
                Log::error('No valid files to upload after processing', [
                    'ai_model_id' => $aiModel->id,
                    'original_files' => $modelFiles
                ]);
                throw new \Exception('No valid files to upload after processing');
            }

            Log::info('Files ready for upload to HuggingFace', [
                'ai_model_id' => $aiModel->id,
                'files_to_upload' => array_keys($filesToUpload)
            ]);
            
            // Upload files to Hugging Face
            $huggingFaceService = new HuggingFaceService();
            $uploadSuccess = $huggingFaceService->uploadFilesWithNames($hfModelName, $filesToUpload);

            if (!$uploadSuccess) {
                Log::error('Failed to upload files to Hugging Face', [
                    'ai_model_id' => $aiModel->id,
                    'hf_model_name' => $hfModelName,
                    'file_count' => count($modelFiles)
                ]);
                throw new \Exception('Failed to upload files to Hugging Face');
            }

            // Update status to completed only after successful push
            $aiModel->update([
                'status' => 'completed',
                'error_message' => null
            ]);

            Log::info('Successfully pushed model files to Hugging Face', [
                'ai_model_id' => $aiModel->id,
                'hf_model_name' => $hfModelName,
                'file_count' => count($modelFiles),
                'hf_model_url' => $aiModel->huggingface_model_url
            ]);

        } catch (\Exception $e) {
            Log::error('PushReplicateModelToHuggingFace job failed', [
                'ai_model_id' => $this->aiModelId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString()
            ]);

            // Re-throw the exception to mark the job as failed
            throw $e;
        } finally {
            // Clean up any temporary files
            $this->cleanupTempFiles();
        }
    }

    /**
     * Process model files - download and extract if needed
     * Returns array of filename => local_path pairs
     *
     * @param array $modelFiles Array of URLs
     * @return array Array of [filename => local_path]
     */
    private function processModelFiles(array $modelFiles): array
    {
        $filesToUpload = [];
        
        foreach ($modelFiles as $fileUrl) {
            Log::info('Processing model file', ['url' => $fileUrl]);
            
            // Download the file
            $downloadedFile = $this->downloadFile($fileUrl);
            
            if (!$downloadedFile) {
                Log::error('Failed to download file', ['url' => $fileUrl]);
                continue;
            }
            
            $filename = basename(parse_url($fileUrl, PHP_URL_PATH));
            
            // Check if it's a tar file that needs extraction
            if (str_ends_with(strtolower($filename), '.tar')) {
                Log::info('Extracting tar file', [
                    'filename' => $filename,
                    'downloaded_path' => $downloadedFile
                ]);
                
                $extractedFiles = $this->extractTarFile($downloadedFile);
                
                // Look for lora.safetensors in extracted files
                foreach ($extractedFiles as $extractedFile) {
                    $extractedFilename = basename($extractedFile);
                    
                    // Prioritize lora.safetensors file
                    if ($extractedFilename === 'lora.safetensors' || 
                        str_ends_with($extractedFilename, '.safetensors')) {
                        $filesToUpload['lora.safetensors'] = $extractedFile;
                        Log::info('Found LoRA safetensors file', [
                            'filename' => $extractedFilename,
                            'path' => $extractedFile
                        ]);
                    }
                }
                
                // Clean up the tar file
                if (file_exists($downloadedFile)) {
                    unlink($downloadedFile);
                }
            } else {
                // Non-tar file, upload as-is
                $filesToUpload[$filename] = $downloadedFile;
            }
        }
        
        return $filesToUpload;
    }

    /**
     * Download a file from URL to temporary location
     *
     * @param string $url
     * @return string|null Local file path or null on failure
     */
    private function downloadFile(string $url): ?string
    {
        try {
            Log::info('Downloading file', ['url' => $url]);
            
            // Create temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'hf_model_');
            
            // Use cURL for reliable download
            $ch = curl_init($url);
            $fileHandle = fopen($tempFile, 'wb');
            
            if (!$fileHandle) {
                throw new \Exception("Failed to create temp file");
            }
            
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fileHandle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 600, // 10 minutes
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_FAILONERROR => true,
            ]);
            
            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            fclose($fileHandle);
            curl_close($ch);
            
            if (!$success || $httpCode >= 400) {
                Log::error('Failed to download file', [
                    'url' => $url,
                    'http_code' => $httpCode,
                    'error' => $error
                ]);
                unlink($tempFile);
                return null;
            }
            
            $fileSize = filesize($tempFile);
            
            if ($fileSize === 0) {
                Log::error('Downloaded file is empty', ['url' => $url]);
                unlink($tempFile);
                return null;
            }
            
            Log::info('File downloaded successfully', [
                'url' => $url,
                'temp_file' => $tempFile,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);
            
            return $tempFile;
            
        } catch (\Exception $e) {
            Log::error('Exception downloading file', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extract a tar file and return array of extracted file paths
     *
     * @param string $tarPath
     * @return array Array of extracted file paths
     */
    private function extractTarFile(string $tarPath): array
    {
        $extractedFiles = [];
        $renamedTarPath = null;
        
        try {
            // Create extraction directory
            $extractDir = sys_get_temp_dir() . '/hf_extract_' . uniqid();
            if (!mkdir($extractDir, 0755, true)) {
                throw new \Exception("Failed to create extraction directory");
            }
            
            // PharData requires .tar extension - rename temp file if needed
            if (!str_ends_with(strtolower($tarPath), '.tar')) {
                $renamedTarPath = $tarPath . '.tar';
                if (!copy($tarPath, $renamedTarPath)) {
                    throw new \Exception("Failed to create tar file with proper extension");
                }
                $workingTarPath = $renamedTarPath;
                Log::info('Renamed temp file for PharData compatibility', [
                    'original' => $tarPath,
                    'renamed' => $renamedTarPath
                ]);
            } else {
                $workingTarPath = $tarPath;
            }
            
            Log::info('Extracting tar file', [
                'tar_path' => $workingTarPath,
                'extract_dir' => $extractDir
            ]);
            
            // Use PharData for tar extraction
            $phar = new \PharData($workingTarPath);
            $phar->extractTo($extractDir);
            
            // Clean up renamed tar file
            if ($renamedTarPath && file_exists($renamedTarPath)) {
                unlink($renamedTarPath);
            }
            
            // Find all extracted files recursively
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extractedFiles[] = $file->getPathname();
                    Log::info('Extracted file', [
                        'filename' => $file->getFilename(),
                        'path' => $file->getPathname(),
                        'size_mb' => round($file->getSize() / 1024 / 1024, 2)
                    ]);
                }
            }
            
            Log::info('Tar extraction complete', [
                'tar_path' => $tarPath,
                'extracted_count' => count($extractedFiles)
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to extract tar file with PharData, trying shell command', [
                'tar_path' => $tarPath,
                'error' => $e->getMessage()
            ]);
            
            // Clean up renamed tar file on error
            if ($renamedTarPath && file_exists($renamedTarPath)) {
                unlink($renamedTarPath);
            }
            
            // Fallback: try using shell tar command
            $extractedFiles = $this->extractTarWithShell($tarPath);
        }
        
        return $extractedFiles;
    }

    /**
     * Extract tar file using shell command as fallback
     *
     * @param string $tarPath
     * @return array Array of extracted file paths
     */
    private function extractTarWithShell(string $tarPath): array
    {
        $extractedFiles = [];
        
        try {
            // Create extraction directory
            $extractDir = sys_get_temp_dir() . '/hf_extract_' . uniqid();
            if (!mkdir($extractDir, 0755, true)) {
                throw new \Exception("Failed to create extraction directory");
            }
            
            Log::info('Extracting tar file with shell command', [
                'tar_path' => $tarPath,
                'extract_dir' => $extractDir
            ]);
            
            // Use tar command
            $command = sprintf(
                'tar -xf %s -C %s 2>&1',
                escapeshellarg($tarPath),
                escapeshellarg($extractDir)
            );
            
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                Log::error('Shell tar extraction failed', [
                    'command' => $command,
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output)
                ]);
                throw new \Exception("Shell tar extraction failed with code: {$returnCode}");
            }
            
            // Find all extracted files recursively
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extractedFiles[] = $file->getPathname();
                    Log::info('Extracted file (shell)', [
                        'filename' => $file->getFilename(),
                        'path' => $file->getPathname(),
                        'size_mb' => round($file->getSize() / 1024 / 1024, 2)
                    ]);
                }
            }
            
            Log::info('Shell tar extraction complete', [
                'tar_path' => $tarPath,
                'extracted_count' => count($extractedFiles)
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to extract tar file with shell', [
                'tar_path' => $tarPath,
                'error' => $e->getMessage()
            ]);
        }
        
        return $extractedFiles;
    }

    /**
     * Clean up temporary files created during processing
     */
    private function cleanupTempFiles(): void
    {
        try {
            $tempDir = sys_get_temp_dir();
            
            // Clean up upload temp files
            $uploadFiles = glob($tempDir . '/hf_upload_*');
            foreach ($uploadFiles as $file) {
                if (file_exists($file) && is_file($file)) {
                    unlink($file);
                    Log::info('Cleaned up temporary file', ['file' => $file]);
                }
            }
            
            // Clean up model temp files
            $modelFiles = glob($tempDir . '/hf_model_*');
            foreach ($modelFiles as $file) {
                if (file_exists($file) && is_file($file)) {
                    unlink($file);
                    Log::info('Cleaned up temporary file', ['file' => $file]);
                }
            }
            
            // Clean up extraction directories
            $extractDirs = glob($tempDir . '/hf_extract_*');
            foreach ($extractDirs as $dir) {
                if (is_dir($dir)) {
                    $this->removeDirectory($dir);
                    Log::info('Cleaned up extraction directory', ['dir' => $dir]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup temporary files', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recursively remove a directory
     *
     * @param string $dir
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PushReplicateModelToHuggingFace job permanently failed', [
            'ai_model_id' => $this->aiModelId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Optionally update the AI model status to indicate the push failed
        try {
            $aiModel = AiModel::find($this->aiModelId);
            if ($aiModel) {
                $aiModel->update([
                    'error_message' => 'Failed to push model files to Hugging Face: ' . $exception->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update AI model after job failure', [
                'ai_model_id' => $this->aiModelId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
