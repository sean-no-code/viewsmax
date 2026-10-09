<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeatJob;
use App\Models\QueueHeartbeat as Heartbeat;
use App\Services\QueueHeartbeatMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One tick of the queue heartbeat (every five minutes from the scheduler):
 * record a heartbeat row, dispatch QueueHeartbeatJob for it, prune old rows,
 * then look at how earlier heartbeats fared and email the alert address if
 * they were never processed (or when the queue is back). See
 * config/monitoring.php.
 *
 *   queue:heartbeat                 # normal tick
 *   queue:heartbeat --no-dispatch   # only evaluate + alert (ops / tests)
 */
class QueueHeartbeat extends Command
{
    protected $signature = 'queue:heartbeat {--no-dispatch : Evaluate and alert without dispatching a new heartbeat}';

    protected $description = 'Dispatch the queue heartbeat job and alert when heartbeats stop being processed.';

    public function handle(QueueHeartbeatMonitor $monitor): int
    {
        $cfg = config('monitoring.queue_heartbeat');

        if (! $cfg['enabled']) {
            $this->info('Queue heartbeat is disabled (QUEUE_HEARTBEAT_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $this->option('no-dispatch')) {
            $heartbeat = Heartbeat::create(['dispatched_at' => now()]);
            QueueHeartbeatJob::dispatch($heartbeat->id);

            Heartbeat::query()->where('dispatched_at', '<', now()->subDays((int) $cfg['retain_days']))->delete();

            Log::channel('queue_heartbeat')->info('heartbeat dispatched', ['heartbeat_id' => $heartbeat->id]);
        }

        $status = $monitor->status();
        $event = $monitor->notify($status);

        Log::channel('queue_heartbeat')->info('heartbeat status', [
            'status' => $status['status'],
            'overdue' => $status['heartbeat']['overdue'],
            'lag_seconds' => $status['heartbeat']['lag_seconds'],
            'pending_jobs' => $status['queue']['pending_jobs'],
            'event' => $event,
        ]);

        $lag = $status['heartbeat']['lag_seconds'];
        $line = "Queue heartbeat: {$status['status']} (overdue {$status['heartbeat']['overdue']}, lag ".($lag === null ? 'n/a' : "{$lag}s").')';
        $status['healthy'] ? $this->info($line) : $this->warn($line);
        if ($event) {
            $this->line("Sent '{$event}' email.");
        }

        return self::SUCCESS;
    }
}
