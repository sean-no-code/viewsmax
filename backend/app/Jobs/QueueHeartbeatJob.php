<?php

namespace App\Jobs;

use App\Models\QueueHeartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The queue heartbeat. Dispatched every five minutes by queue:heartbeat; when a
 * worker runs it, the heartbeat row is stamped as processed. That stamp is the
 * whole point: its absence is how QueueHeartbeatMonitor knows the queue is stuck.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $heartbeatId) {}

    public function handle(): void
    {
        $heartbeat = QueueHeartbeat::find($this->heartbeatId);
        if (! $heartbeat) {
            return; // pruned before the worker got to it
        }

        $heartbeat->processed_at = now();
        $heartbeat->save();

        Log::channel('queue_heartbeat')->info('heartbeat processed', [
            'heartbeat_id' => $heartbeat->id,
            'lag_seconds' => $heartbeat->dispatched_at->diffInSeconds($heartbeat->processed_at),
        ]);
    }
}
