<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One scheduler tick of the queue heartbeat (see config/monitoring.php).
 * dispatched_at is set by queue:heartbeat, processed_at by QueueHeartbeatJob
 * when a worker runs it; the gap between the two is the queue's lag.
 */
class QueueHeartbeat extends Model
{
    protected $fillable = [
        'dispatched_at',
        'processed_at',
        'alerted_at',
        'recovered_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'processed_at' => 'datetime',
        'alerted_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];
}
