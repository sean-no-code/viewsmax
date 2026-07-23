<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Title extends Model
{
    protected $fillable = [
        'title',
        'virality_score',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'virality_score' => 'decimal:2',
    ];

    /**
     * The videos that belong to the title.
     */
    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'video_title')
                    ->withTimestamps();
    }
}
