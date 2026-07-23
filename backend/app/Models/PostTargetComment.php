<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery record of one composed comment on one publish target.
 */
class PostTargetComment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_POSTING = 'posting';
    public const STATUS_POSTED = 'posted';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'post_comment_id',
        'post_target_id',
        'status',
        'platform_comment_id',
        'error',
        'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'post_comment_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
