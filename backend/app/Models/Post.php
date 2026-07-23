<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_POSTED = 'posted';

    protected $fillable = [
        'user_id',
        'brand_id',
        'caption',
        'media',
        'status',
        'scheduled_at',
        'shorten_links',
        'failure_notified_at',
    ];

    protected $casts = [
        'media' => 'array',
        'scheduled_at' => 'datetime',
        'shorten_links' => 'boolean',
        'failure_notified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The brand selected in the composer — informational, never read at publish time. */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /** Composed comments, posted to each supporting target after it publishes. */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class)->orderBy('position');
    }
}
