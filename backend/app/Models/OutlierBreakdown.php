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
}
