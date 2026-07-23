<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Content extends Model
{
    use HasFactory;

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'user_id',
        'offer_id',
        'title',
        'body',
        'media_path',
        'media_filename',
        'media_mime',
        'media_size',
        'status',
    ];

    protected $casts = [
        'media_size' => 'integer',
    ];

    protected $hidden = [
        'media_path',
    ];

    /**
     * Whether this content has a stored media file.
     */
    public function hasMedia(): bool
    {
        return ! empty($this->media_path);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'offer_id');
    }
}
