<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Thumbnail extends Model
{
    /**
     * Type constants
     */
    public const TYPE_THUMBNAIL = 'thumbnail';

    public const TYPE_COPY_THUMBNAIL = 'copy_thumbnail';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'description',
        'prompt',
        'user_id',
        'file_location',
        'status',
        'error_message',
        'processed_at',
        'negative_prompt_id',
        'style_prompt_id',
        'general_prompt_id',
        'ai_model_id',
        'parent_id',
        'comfy_prompt_id',
        // Copy-thumbnail-specific fields
        'source_image_path',
        'source_image_url',
        'reference_image_path',
        'result_image_path',
        'processing_log',
        'extracted_expression',
        'transformed_prompt',
        'resolution_width',
        'resolution_height',
        'dw_pose_enabled',
        'generated_image_id',
    ];

    protected $casts = [
        'processing_log' => 'array',
        'dw_pose_enabled' => 'boolean',
    ];

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * Scope to only regular generated thumbnails.
     */
    public function scopeGenerated($query)
    {
        return $query->where('type', self::TYPE_THUMBNAIL);
    }

    /**
     * Scope to only copy thumbnails.
     */
    public function scopeCopied($query)
    {
        return $query->where('type', self::TYPE_COPY_THUMBNAIL);
    }

    // ─── Relationships ─────────────────────────────────────────────

    /**
     * Get the user that owns the thumbnail.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the negative prompt for this thumbnail.
     */
    public function negativePrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'negative_prompt_id');
    }

    /**
     * Get the style prompt for this thumbnail.
     */
    public function stylePrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'style_prompt_id');
    }

    /**
     * Get the general prompt for this thumbnail.
     */
    public function generalPrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'general_prompt_id');
    }

    /**
     * Get the AI model associated with this thumbnail.
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /**
     * Get the parent thumbnail (if this is a child thumbnail).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Thumbnail::class, 'parent_id');
    }

    /**
     * Get the child thumbnails (if this is a parent thumbnail).
     */
    public function children()
    {
        return $this->hasMany(Thumbnail::class, 'parent_id');
    }

    /**
     * Get the linked Flux2 GeneratedImage record (copy thumbnails only).
     */
    public function generatedImage(): BelongsTo
    {
        return $this->belongsTo(GeneratedImage::class);
    }

    // ─── Type Helpers ──────────────────────────────────────────────

    /**
     * Check if this is a copy thumbnail.
     */
    public function isCopyThumbnail(): bool
    {
        return $this->type === self::TYPE_COPY_THUMBNAIL;
    }

    /**
     * Check if this is a regular generated thumbnail.
     */
    public function isGenerated(): bool
    {
        return $this->type === self::TYPE_THUMBNAIL;
    }

    // ─── Status Helpers (shared by both types) ─────────────────────

    /**
     * Add a log entry to the processing log.
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
     * Mark as processing with ComfyUI prompt ID.
     */
    public function markAsProcessing(string $promptId): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->comfy_prompt_id = $promptId;
        $this->addLog('Started processing', ['prompt_id' => $promptId]);
    }

    /**
     * Mark as completed with result image path (copy thumbnails).
     */
    public function markAsCompleted(string $resultImagePath): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->result_image_path = $resultImagePath;
        $this->addLog('Completed successfully', ['result_path' => $resultImagePath]);
    }

    /**
     * Mark as failed with error message.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->addLog('Failed', ['error' => $errorMessage]);
    }

    /**
     * Check if processing is complete.
     */
    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED]);
    }

    /**
     * Check if processing was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    // ─── URL Accessors ─────────────────────────────────────────────

    /**
     * Get the full URL for the source image (copy thumbnails).
     */
    public function getSourceImageFullUrlAttribute(): ?string
    {
        if (! $this->source_image_path) {
            return null;
        }

        if (str_starts_with($this->source_image_path, 'http')) {
            return $this->source_image_path;
        }

        return Storage::url($this->source_image_path);
    }

    /**
     * Get the full URL for the result image (copy thumbnails).
     */
    public function getResultImageUrlAttribute(): ?string
    {
        if (! $this->result_image_path) {
            return null;
        }

        if (str_starts_with($this->result_image_path, 'http')) {
            return $this->result_image_path;
        }

        return Storage::url($this->result_image_path);
    }

    /**
     * Get the full URL for the file location (generated thumbnails).
     * This accessor transforms the stored file path into a complete URL.
     */
    public function getFileLocationAttribute($value)
    {
        if (! $value) {
            return null;
        }

        // If the value is already a full URL, return it as is
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        // Parse the file path to extract user ID, thumbnail ID, and filename
        // Expected format: thumbnails/{userId}/{thumbnailId}/{filename}
        $pathParts = explode('/', $value);

        if (count($pathParts) >= 4 && $pathParts[0] === 'thumbnails') {
            $userId = $pathParts[1];
            $thumbnailId = $pathParts[2];
            $filename = $pathParts[3];

            // Generate URL using the custom thumbnail route
            return url("/thumbnails/{$userId}/{$thumbnailId}/{$filename}");
        }

        // Fallback to Storage URL if path doesn't match expected format
        return Storage::url($value);
    }

    // ─── Copy Thumbnail Prompt Helpers ─────────────────────────────

    /**
     * Get combined prompt text including style, general, and negative prompts
     * This is appended after the ExpressionTransformerService output
     */
    public function getCombinedPromptText(): ?string
    {
        if ($this->isCopyThumbnail()) {
            $combinedText = '';

            // Add style prompt (main visual style instructions)
            if ($this->stylePrompt) {
                $combinedText .= $this->stylePrompt->text."\n\n";
            }

            // Add general prompt
            if ($this->generalPrompt) {
                $combinedText .= $this->generalPrompt->text."\n\n";
            }

            return trim($combinedText);
        }

        // Generated thumbnail prompt logic
        Log::info('getCombinedPromptText: ');
        $prompts = $this->getPromptsUsed();

        if (empty($prompts)) {
            return null;
        }

        $combinedText = '';

        // Use the prompt field (which includes trigger word for AI models)
        // Fall back to description if prompt is not set
        $mainPrompt = $this->prompt ?: $this->description;

        if ($mainPrompt) {
            $combinedText .= $mainPrompt.".\n\n";
        }

        // Add style prompt (main visual style instructions)
        if (isset($prompts['style'])) {
            $combinedText .= $prompts['style']['text']."\n\n";
        }

        // Add general prompt
        if (isset($prompts['general'])) {
            $combinedText .= $prompts['general']['text']."\n\n";
        }

        // Add negative prompt
        if (isset($prompts['negative'])) {
            $combinedText .= $prompts['negative']['text']."\n\n";
        }

        return trim($combinedText);
    }

    /**
     * Get the negative prompt text (for separate handling)
     */
    public function getNegativePromptText(): ?string
    {
        return $this->negativePrompt?->text;
    }

    // ─── Generated Thumbnail Prompt Helpers ────────────────────────

    /**
     * Get all prompts used to create this thumbnail.
     * Returns an array with negative, style, and general prompts.
     */
    public function getPromptsUsed(): array
    {
        $prompts = [];

        // Get negative prompt with fallback to default
        if ($this->negativePrompt) {
            $prompts['negative'] = [
                'id' => $this->negativePrompt->id,
                'type' => 'negative',
                'version' => $this->negativePrompt->version,
                'text' => $this->negativePrompt->text,
                'is_active' => $this->negativePrompt->is_active,
                'created_at' => $this->negativePrompt->created_at,
                'updated_at' => $this->negativePrompt->updated_at,
                'source' => 'assigned',
            ];
        } else {
            $defaultNegativePrompt = Prompt::whereHas('promptType', function ($query) {
                $query->where('name', Prompt::DEFAULT_NEGATIVE_PROMPT_TYPE);
            })->where('is_active', true)->latest('version')->first();

            if ($defaultNegativePrompt) {
                $prompts['negative'] = [
                    'id' => $defaultNegativePrompt->id,
                    'type' => 'negative',
                    'version' => $defaultNegativePrompt->version,
                    'text' => $defaultNegativePrompt->text,
                    'is_active' => $defaultNegativePrompt->is_active,
                    'created_at' => $defaultNegativePrompt->created_at,
                    'updated_at' => $defaultNegativePrompt->updated_at,
                    'source' => 'default',
                ];
            }
        }

        // Get style prompt with fallback to default
        if ($this->stylePrompt) {
            $prompts['style'] = [
                'id' => $this->stylePrompt->id,
                'type' => 'style',
                'version' => $this->stylePrompt->version,
                'text' => $this->stylePrompt->text,
                'is_active' => $this->stylePrompt->is_active,
                'created_at' => $this->stylePrompt->created_at,
                'updated_at' => $this->stylePrompt->updated_at,
                'source' => 'assigned',
            ];
        } else {
            $defaultStylePrompt = Prompt::whereHas('promptType', function ($query) {
                $query->where('name', Prompt::DEFAULT_STYLE_PROMPT_TYPE);
            })->where('is_active', true)->first();

            if (! $defaultStylePrompt) {
                $defaultStylePrompt = Prompt::whereHas('promptType', function ($query) {
                    $query->where('name', 'style');
                })->where('is_active', true)->latest('version')->first();
            }

            if ($defaultStylePrompt) {
                $prompts['style'] = [
                    'id' => $defaultStylePrompt->id,
                    'type' => 'style',
                    'version' => $defaultStylePrompt->version,
                    'text' => $defaultStylePrompt->text,
                    'is_active' => $defaultStylePrompt->is_active,
                    'created_at' => $defaultStylePrompt->created_at,
                    'updated_at' => $defaultStylePrompt->updated_at,
                    'source' => 'default',
                ];
            }
        }

        // Get general prompt with fallback to default
        if ($this->generalPrompt) {
            $prompts['general'] = [
                'id' => $this->generalPrompt->id,
                'type' => 'general',
                'version' => $this->generalPrompt->version,
                'text' => $this->generalPrompt->text,
                'is_active' => $this->generalPrompt->is_active,
                'created_at' => $this->generalPrompt->created_at,
                'updated_at' => $this->generalPrompt->updated_at,
                'source' => 'assigned',
            ];
        } else {
            $defaultGeneralPrompt = Prompt::whereHas('promptType', function ($query) {
                $query->where('name', 'general');
            })->where('is_active', true)->latest('version')->first();

            if ($defaultGeneralPrompt) {
                $prompts['general'] = [
                    'id' => $defaultGeneralPrompt->id,
                    'type' => 'general',
                    'version' => $defaultGeneralPrompt->version,
                    'text' => $defaultGeneralPrompt->text,
                    'is_active' => $defaultGeneralPrompt->is_active,
                    'created_at' => $defaultGeneralPrompt->created_at,
                    'updated_at' => $defaultGeneralPrompt->updated_at,
                    'source' => 'default',
                ];
            }
        }

        return $prompts;
    }

    /**
     * Get prompt summary for this thumbnail.
     */
    public function getPromptSummary(): array
    {
        $prompts = $this->getPromptsUsed();

        return [
            'thumbnail_id' => $this->id,
            'description' => $this->description,
            'prompt' => $this->prompt,
            'prompts_count' => count($prompts),
            'prompts' => array_map(function ($prompt) {
                return [
                    'type' => $prompt['type'],
                    'version' => $prompt['version'],
                    'text_preview' => substr($prompt['text'], 0, 100).'...',
                    'is_active' => $prompt['is_active'],
                ];
            }, $prompts),
        ];
    }
}
