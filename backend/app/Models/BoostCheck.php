<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled "has it reached the like threshold yet?" check series for a
 * published target + feature. Postiz cadence: up to 3 runs, 6h apart, stop on
 * success. UNIQUE(post_target_id, feature) doubles as the dedupe ledger.
 */
class BoostCheck extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_FAILED = 'failed';

    public const MAX_RUNS = 3;

    public const RUN_INTERVAL_HOURS = 6;

    protected $fillable = [
        'post_target_id',
        'boost_setting_id',
        'feature',
        'runs_completed',
        'next_run_at',
        'status',
        'result_remote_id',
        'error',
    ];

    protected $casts = [
        'runs_completed' => 'integer',
        'next_run_at' => 'datetime',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(BoostSetting::class, 'boost_setting_id');
    }
}
