<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One composed comment on a post — posted to each supporting target after it
 * publishes, `delay_seconds` after the previous message in the chain.
 */
class PostComment extends Model
{
    protected $fillable = [
        'post_id',
        'position',
        'body',
        'delay_seconds',
    ];

    protected $casts = [
        'position' => 'integer',
        'delay_seconds' => 'integer',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function targetComments(): HasMany
    {
        return $this->hasMany(PostTargetComment::class);
    }
}
