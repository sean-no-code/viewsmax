<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SavedOutlier extends Model
{
    protected $connection = 'outlier_db';

    protected $fillable = [
        'user_id',
        'platform',
        'video_id',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(OutlierTag::class, 'saved_outlier_tag', 'saved_outlier_id', 'outlier_tag_id');
    }
}
