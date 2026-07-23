<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ai_models';

    protected $fillable = [
        'name',
        'user_id',
        'status',
        'error_message',
        'replicates_prediction_id',
        'huggingface_model_id',
        'huggingface_model_url',
        'replicate_model_name',
        'replicate_model_url',
        'bald',
        'age',
        'ai_model_type_id',
        'ethnicity_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'bald' => 'boolean',
    ];

    /**
     * Get the user that owns the AI model.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the AI model type.
     */
    public function aiModelType(): BelongsTo
    {
        return $this->belongsTo(AiModelType::class);
    }

    /**
     * Get the ethnicity.
     */
    public function ethnicity(): BelongsTo
    {
        return $this->belongsTo(Ethnicity::class);
    }

    /**
     * Get the file uploads for this AI model.
     */
    public function fileUploads(): BelongsToMany
    {
        return $this->belongsToMany(FileUpload::class, 'ai_model_files', 'ai_model_id', 'file_upload_id');
    }

    /**
     * Get the thumbnail file for this AI model.
     */
    public function thumbnail(): HasOne
    {
        return $this->hasOne(FileUpload::class, 'ai_model_id')
            ->whereHas('fileCategory', function ($query) {
                $query->where('name', 'AI Model Thumbnail');
            });
    }

    /**
     * Check if the model is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the model is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if the model is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the model has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Scope to get models by user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get models by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get the trigger word for this AI model.
     *
     * @return string
     */
    public function triggerWord(): string
    {
        return $this->user_id . '-' . $this->id;
    }

    /**
     * Get the model name for this AI model.
     * Uses the created date as timestamp for consistency between Hugging Face and Replicate.
     *
     * @return string
     */
    public function modelName(): string
    {
        $timestamp = $this->created_at ? $this->created_at->timestamp : time();
        return "ai-model-{$this->user_id}-{$this->id}-{$timestamp}";
    }
}
