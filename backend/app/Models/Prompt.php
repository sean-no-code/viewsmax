<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    /**
     * Default prompt type names
     */
    public const DEFAULT_NEGATIVE_PROMPT_TYPE = 'negative';
    public const DEFAULT_STYLE_PROMPT_TYPE = 'default_style';

    protected $fillable = [
        'prompt_type_id',
        'title',
        'text',
        'version',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'version' => 'integer'
    ];

    /**
     * Get the prompt type that owns the prompt.
     */
    public function promptType(): BelongsTo
    {
        return $this->belongsTo(PromptType::class);
    }

    /**
     * Get thumbnails that use this prompt as negative prompt.
     */
    public function thumbnailsAsNegative(): HasMany
    {
        return $this->hasMany(Thumbnail::class, 'negative_prompt_id');
    }

    /**
     * Get thumbnails that use this prompt as style prompt.
     */
    public function thumbnailsAsStyle(): HasMany
    {
        return $this->hasMany(Thumbnail::class, 'style_prompt_id');
    }

    /**
     * Get thumbnails that use this prompt as general prompt.
     */
    public function thumbnailsAsGeneral(): HasMany
    {
        return $this->hasMany(Thumbnail::class, 'general_prompt_id');
    }

    /**
     * Scope to get active prompts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get latest version of each prompt type.
     */
    public function scopeLatestVersion($query)
    {
        return $query->whereRaw('version = (SELECT MAX(version) FROM prompts p2 WHERE p2.prompt_type_id = prompts.prompt_type_id)');
    }
}
