<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GeneratedImage extends Model
{
    use HasFactory;

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Method constants
    public const METHOD_GENERATE = 'generate';
    public const METHOD_HEAD_SWAP = 'head_swap';

    // Quality constants
    public const QUALITY_FAST = 'fast';
    public const QUALITY_NORMAL = 'normal';
    public const QUALITY_HIGH = 'high';
    public const QUALITY_VERY_HIGH = 'very_high';

    protected $fillable = [
        'user_id',
        'method',
        'quality',
        'status',
        'prompt',
        'comfy_prompt_id',
        'base_image_path',
        'base_image_url',
        'reference_image_path',
        'additional_images',
        'result_image_path',
        'result_image_paths',
        'processing_log',
        'error_message',
        'megapixel',
        'steps',
        'refine_enabled',
        'inpainting_enabled',
        'number_of_images',
        'webhook_received_at',
    ];

    protected $casts = [
        'additional_images' => 'array',
        'processing_log' => 'array',
        'result_image_paths' => 'array',
        'refine_enabled' => 'boolean',
        'inpainting_enabled' => 'boolean',
        'megapixel' => 'float',
        'webhook_received_at' => 'datetime',
    ];

    /**
     * Get the user that owns this generated image
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add a log entry to the processing log
     */
    public function addLog(string $message, array $context = []): void
    {
        $logs = $this->processing_log ?? [];
        $logs[] = [
            'timestamp' => now()->toIso8601String(),
            'message' => $message,
            'context' => $context,
        ];
        $this->processing_log = $logs;
        $this->save();
    }

    /**
     * Mark as processing with the ComfyUI prompt ID
     */
    public function markAsProcessing(string $promptId): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->comfy_prompt_id = $promptId;
        $this->addLog('Started processing', ['prompt_id' => $promptId]);
    }

    /**
     * Mark as completed with the result image path
     */
    public function markAsCompleted(string $resultPath): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->result_image_path = $resultPath;
        $this->addLog('Completed successfully', ['result_path' => $resultPath]);
    }

    /**
     * Mark as failed with error message
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->addLog('Failed', ['error' => $errorMessage]);
    }

    /**
     * Check if the generation is complete (either success or failure)
     */
    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED]);
    }

    /**
     * Check if the generation was successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Get the base image URL
     */
    public function getBaseImageUrlAttribute(): ?string
    {
        if (!$this->base_image_path) {
            return null;
        }
        return Storage::disk('public')->url($this->base_image_path);
    }

    /**
     * Get the reference image URL
     */
    public function getReferenceImageUrlAttribute(): ?string
    {
        if (!$this->reference_image_path) {
            return null;
        }
        return Storage::disk('public')->url($this->reference_image_path);
    }

    /**
     * Get the result image URL (single, backward compatibility - returns first image)
     */
    public function getResultImageUrlAttribute(): ?string
    {
        // Always use result_image_paths array
        if ($this->result_image_paths && !empty($this->result_image_paths)) {
            return Storage::disk('public')->url($this->result_image_paths[0]);
        }

        // Fallback to old result_image_path for backward compatibility
        if ($this->result_image_path) {
            return Storage::disk('public')->url($this->result_image_path);
        }

        return null;
    }

    /**
     * Get all result image URLs (multiple) - always returns array
     */
    public function getResultImageUrlsAttribute(): array
    {
        $urls = [];

        // Always use result_image_paths array
        if ($this->result_image_paths && !empty($this->result_image_paths)) {
            foreach ($this->result_image_paths as $path) {
                $urls[] = Storage::disk('public')->url($path);
            }
            return $urls;
        }

        // Fallback to old result_image_path for backward compatibility
        if ($this->result_image_path) {
            $urls[] = Storage::disk('public')->url($this->result_image_path);
        }

        return $urls;
    }

    /**
     * Get the number of generated result images
     */
    public function getResultImageCountAttribute(): int
    {
        // Always use result_image_paths array
        if ($this->result_image_paths && !empty($this->result_image_paths)) {
            return count($this->result_image_paths);
        }

        // Fallback to old result_image_path for backward compatibility
        if ($this->result_image_path) {
            return 1;
        }

        return 0;
    }

    /**
     * Get available quality options
     */
    public static function getQualityOptions(): array
    {
        return [
            self::QUALITY_FAST => 'Fast (0.3 MP)',
            self::QUALITY_NORMAL => 'Normal (0.5 MP)',
            self::QUALITY_HIGH => 'High (1.0 MP)',
            self::QUALITY_VERY_HIGH => 'Very High (2.0 MP)',
        ];
    }

    /**
     * Get available method options
     */
    public static function getMethodOptions(): array
    {
        return [
            self::METHOD_GENERATE => 'Generate',
            self::METHOD_HEAD_SWAP => 'Head Swap',
        ];
    }

    /**
     * Scope to get only pending images
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get only processing images
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope to get only completed images
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get only failed images
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
