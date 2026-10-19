<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutlierBreakdown extends Model
{
    protected $connection = 'outlier_db';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Minutes after which a pending/processing row is assumed crashed (worker killed, queue cleared). */
    public const STALE_MINUTES = 10;

    protected $fillable = [
        'platform',
        'video_id',
        'status',
        'payload',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    /** Failed, or stuck in pending/processing with no job left to finish it. */
    public function needsRequeue(): bool
    {
        if ($this->status === self::STATUS_FAILED) {
            return true;
        }

        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)
            && $this->updated_at?->lt(now()->subMinutes(self::STALE_MINUTES));
    }
}
