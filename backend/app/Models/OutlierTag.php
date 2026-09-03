<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OutlierTag extends Model
{
    protected $connection = 'outlier_db';

    protected $fillable = [
        'user_id',
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedOutliers(): BelongsToMany
    {
        return $this->belongsToMany(SavedOutlier::class, 'saved_outlier_tag', 'outlier_tag_id', 'saved_outlier_id');
    }
}
