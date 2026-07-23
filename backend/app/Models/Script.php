<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Script extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'prompt',
        'text',
        'length',
        'word_count',
        'status',
        'error_message'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['stage'];

    /**
     * Get the user that owns the script.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the current stage of the script (1-4).
     */
    public function getStageAttribute()
    {
        // Stage 4: Script Generated
        if (!empty($this->text)) {
            return 4;
        }
        
        // Stage 3: Components Selected
        if ($this->components()->exists()) {
            return 3;
        }

        // Stage 2: Research Generated
        if ($this->research()->exists()) {
            return 2;
        }

        // Stage 1: Topic/Draft
        return 1;
    }

    public function research()
    {
        return $this->hasOne(ScriptResearch::class);
    }

    public function components()
    {
        return $this->belongsToMany(LibraryComponent::class, 'script_library_component');
    }

    /**
     * The videos that belong to the script.
     */
    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'scripts_videos')
                    ->withTimestamps()
                    ->orderBy('videos.created_at', 'desc');
    }

    /**
     * Get the versions for the script.
     */
    public function versions()
    {
        return $this->hasMany(ScriptVersion::class)->orderBy('created_at', 'desc');
    }

    /**
     * Scope to get scripts for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}