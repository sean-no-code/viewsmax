<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureRequestVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'feature_request_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }
}
