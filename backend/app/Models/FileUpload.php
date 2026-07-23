<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class FileUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'original_name',
        'location',
        'mime_type',
        'file_size',
        'file_category_id',
        'user_id',
        'ai_model_id',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * Get the file category that owns the file upload.
     */
    public function fileCategory(): BelongsTo
    {
        return $this->belongsTo(FileCategory::class);
    }

    /**
     * Get the user that owns the file upload.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the AI models that use this file upload.
     */
    public function aiModels(): BelongsToMany
    {
        return $this->belongsToMany(AiModel::class, 'ai_model_files', 'file_upload_id', 'ai_model_id');
    }

    /**
     * Get the AI model that owns this file upload (direct relationship).
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    /**
     * Get the full URL for the file.
     */
    public function getUrlAttribute(): string
    {
        // If it's an AI model thumbnail/image, use the custom route
        if (strpos($this->location, 'ai-models/') === 0) {
            // Parse path: ai-models/{userId}/{filename}
            $pathParts = explode('/', $this->location);
            if (count($pathParts) >= 3) {
                $userId = $pathParts[1];
                $filename = $pathParts[2];
                return url("/ai-models/{$userId}/{$filename}");
            }
        }
        
        // Fallback to standard asset URL
        return asset('storage/' . $this->location);
    }

    /**
     * Get the file size in human readable format.
     */
    public function getHumanFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Create a zip file from multiple file uploads.
     *
     * @param \Illuminate\Database\Eloquent\Collection $fileUploads
     * @param string $zipFileName
     * @return string|null The path to the created zip file, or null if failed
     */
    public static function createZipFromUploads($fileUploads, string $zipFileName = null): ?string
    {
        if ($fileUploads->isEmpty()) {
            return null;
        }

        // Generate zip filename if not provided
        if (!$zipFileName) {
            $zipFileName = 'ai_model_files_' . time() . '.zip';
        }

        // Create zip file path in PUBLIC storage
        $zipPath = 'temp/' . $zipFileName;
        $fullZipPath = storage_path('app/public/' . $zipPath);

        // Ensure temp directory exists in public storage
        $tempDir = storage_path('app/public/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zip = new ZipArchive();
        $zipOpened = false;
        
        try {
            if ($zip->open($fullZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                return null;
            }
            $zipOpened = true;

            // Add each file to the zip
            foreach ($fileUploads as $fileUpload) {
                $filePath = storage_path('app/public/' . $fileUpload->location);
                
                if (file_exists($filePath)) {
                    // Use original filename in zip to preserve file names
                    $zip->addFile($filePath, $fileUpload->original_name);
                }
            }

            $zip->close();
            $zipOpened = false;
            
            return $zipPath;
        } catch (\Exception $e) {
            // Only close if the zip is still open
            if ($zipOpened) {
                $zip->close();
            }
            // Clean up failed zip file
            if (file_exists($fullZipPath)) {
                unlink($fullZipPath);
            }
            return null;
        }
    }

    /**
     * Get the public URL for a zip file.
     *
     * @param string $zipPath
     * @return string
     */
    public static function getZipUrl(string $zipPath): string
    {
        return asset('storage/temp/' . basename($zipPath));
    }

    /**
     * Delete a zip file from storage.
     *
     * @param string $zipPath
     * @return bool
     */
    public static function deleteZipFile(string $zipPath): bool
    {
        $fullPath = storage_path('app/public/' . $zipPath);
        
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        
        return true; // File doesn't exist, consider it deleted
    }
}
