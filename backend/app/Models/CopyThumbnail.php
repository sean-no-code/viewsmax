<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CopyThumbnail extends Model
{
    protected $fillable = [
        'user_id',
        'source_image_path',
        'source_image_url',
        'result_image_path',
        'status',
        'comfy_prompt_id',
        'error_message',
        'processing_log',
        'extracted_expression',
        'transformed_prompt',
        'resolution_width',
        'resolution_height',
        'dw_pose_enabled',
        'ai_model_id',
        'generated_image_id',
        'negative_prompt_id',
        'style_prompt_id',
        'general_prompt_id',
    ];

    protected $casts = [
        'processing_log' => 'array',
        'dw_pose_enabled' => 'boolean',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Get the user that owns this copy thumbnail.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the AI model associated with this copy thumbnail.
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    /**
     * Get the negative prompt.
     */
    public function negativePrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'negative_prompt_id');
    }

    /**
     * Get the style prompt.
     */
    public function stylePrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'style_prompt_id');
    }

    /**
     * Get the general prompt.
     */
    public function generalPrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'general_prompt_id');
    }

    /**
     * Get the linked Flux2 GeneratedImage record.
     */
    public function generatedImage(): BelongsTo
    {
        return $this->belongsTo(GeneratedImage::class);
    }

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
     * Mark as completed with result image path.
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
     * Get the full URL for the source image.
     */
    public function getSourceImageUrlAttribute(): ?string
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
     * Get the full URL for the result image.
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

    /**
     * Get combined prompt text including style, general, and negative prompts
     * This is appended after the ExpressionTransformerService output
     */
    public function getCombinedPromptText(): ?string
    {
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

    /**
     * Get the negative prompt text (for separate handling)
     */
    public function getNegativePromptText(): ?string
    {
        return $this->negativePrompt?->text;
    }
}
