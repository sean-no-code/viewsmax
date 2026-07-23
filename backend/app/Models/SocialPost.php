<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'content',
        'media',
        'link',
        'status',
        'scheduled_at',
        'published_at',
    ];

    protected $casts = [
        'media' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    /**
     * Recompute the overall post status from its individual targets and persist it.
     */
    public function syncStatusFromTargets(): void
    {
        $targets = $this->targets()->get();

        if ($targets->isEmpty()) {
            return;
        }

        $published = $targets->where('status', SocialPostTarget::STATUS_PUBLISHED)->count();
        $failed = $targets->where('status', SocialPostTarget::STATUS_FAILED)->count();
        $total = $targets->count();

        if ($published === $total) {
            $status = self::STATUS_PUBLISHED;
        } elseif ($published > 0) {
            $status = self::STATUS_PARTIAL;
        } elseif ($failed === $total) {
            $status = self::STATUS_FAILED;
        } else {
            $status = self::STATUS_PUBLISHING;
        }

        $this->update([
            'status' => $status,
            'published_at' => $published > 0 ? ($this->published_at ?? now()) : $this->published_at,
        ]);
    }
}
