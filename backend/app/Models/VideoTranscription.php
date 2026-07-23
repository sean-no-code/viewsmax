<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoTranscription extends Model
{
    protected $fillable = [
        'video_id',
        'file_location',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the video that owns the transcription.
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
