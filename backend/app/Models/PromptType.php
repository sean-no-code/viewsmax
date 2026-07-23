<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptType extends Model
{
    protected $fillable = [
        'name',
        'description'
    ];

    /**
     * Get the prompts for this prompt type.
     */
    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }

    /**
     * Get the active prompt for this type.
     */
    public function activePrompt(): HasMany
    {
        return $this->hasMany(Prompt::class)->where('is_active', true)->latest('version');
    }

    /**
     * Get the latest prompt for this type.
     */
    public function latestPrompt(): HasMany
    {
        return $this->hasMany(Prompt::class)->latest('version');
    }
}
