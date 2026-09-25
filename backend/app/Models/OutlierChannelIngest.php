<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "add this creator's channel" request: who asked, what they typed, and
 * where the background pull got to. Lives on outlier_db next to the channel
 * it resolves to; user_id is a plain column (users are on the main DB).
 */
class OutlierChannelIngest extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const DEFAULT_MAX_VIDEOS = 10;

    protected $connection = 'outlier_db';

    protected $fillable = [
        'user_id',
        'platform',
        'input',
        'handle',
        'channel_id',
        'status',
        'error',
        'videos_added',
        'max_videos',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'channel_id' => 'integer',
        'videos_added' => 'integer',
        'max_videos' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(OutlierChannel::class, 'channel_id');
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => $message,
            'finished_at' => now(),
        ])->save();
    }
}
