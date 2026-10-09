<?php

namespace App\Services;

use App\Console\Commands\PublishDuePosts;
use App\Mail\QueueHeartbeatAlertMail;
use App\Models\QueueHeartbeat;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Reads the queue heartbeat (config/monitoring.php) into one status and sends
 * the stuck / recovered emails. Used by queue:heartbeat and /api/queue-monitor.
 *
 *   ok      heartbeats are being processed
 *   stuck   heartbeats are dispatched but not processed → workers dead or the
 *           queue wedged
 *   down    nothing has been dispatched lately → the scheduler itself is dead
 *           (only observable from the monitor URL; the command can't run then)
 *   unknown no heartbeat has ever been recorded
 */
class QueueHeartbeatMonitor
{
    public const STATUS_OK = 'ok';
    public const STATUS_STUCK = 'stuck';
    public const STATUS_DOWN = 'down';
    public const STATUS_UNKNOWN = 'unknown';

    public const EVENT_STUCK = 'stuck';
    public const EVENT_RECOVERED = 'recovered';

    public function status(): array
    {
        $cfg = config('monitoring.queue_heartbeat');
        $stuckAfter = (int) $cfg['stuck_after_minutes'];
        $interval = (int) $cfg['interval_minutes'];
        $now = now();

        $latest = QueueHeartbeat::query()->latest('dispatched_at')->first();
        $lastProcessed = QueueHeartbeat::query()->whereNotNull('processed_at')->latest('processed_at')->first();
        $overdue = QueueHeartbeat::query()
            ->whereNull('processed_at')
            ->where('dispatched_at', '<', $now->copy()->subMinutes($stuckAfter))
            ->count();

        $processedRecently = $lastProcessed !== null
            && $lastProcessed->processed_at->gt($now->copy()->subMinutes($stuckAfter));

        $status = match (true) {
            $latest === null => self::STATUS_UNKNOWN,
            // Dispatched but never processed is the more specific signal, so
            // it wins over "down" even when the last tick is old.
            $overdue > 0 && ! $processedRecently => self::STATUS_STUCK,
            // Two missed ticks with nothing overdue: the scheduler stopped dispatching.
            $latest->dispatched_at->lt($now->copy()->subMinutes($interval * 2 + 1)) => self::STATUS_DOWN,
            default => self::STATUS_OK,
        };

        return [
            'status' => $status,
            'healthy' => $status === self::STATUS_OK,
            'checked_at' => $now->toIso8601String(),
            'heartbeat' => [
                'enabled' => (bool) $cfg['enabled'],
                'interval_minutes' => $interval,
                'stuck_after_minutes' => $stuckAfter,
                'last_dispatched_at' => $latest?->dispatched_at?->toIso8601String(),
                'last_processed_at' => $lastProcessed?->processed_at?->toIso8601String(),
                'lag_seconds' => $lastProcessed
                    ? $lastProcessed->dispatched_at->diffInSeconds($lastProcessed->processed_at)
                    : null,
                'overdue' => $overdue,
            ],
            'queue' => $this->queueStats(),
            'scheduler' => $this->schedulerStats(),
        ];
    }

    /**
     * Email the alert address when the queue is stuck (once per cooldown) and
     * again once it recovers. Returns the event sent, or null.
     */
    public function notify(array $status): ?string
    {
        $cfg = config('monitoring.queue_heartbeat');
        $email = (string) $cfg['alert_email'];

        if ($status['status'] === self::STATUS_STUCK) {
            $cooldownStart = now()->subMinutes((int) $cfg['alert_cooldown_minutes']);
            if (QueueHeartbeat::query()->where('alerted_at', '>', $cooldownStart)->exists()) {
                return null;
            }

            $trigger = QueueHeartbeat::query()->whereNull('processed_at')->latest('dispatched_at')->first();
            if ($this->send($email, self::EVENT_STUCK, $status)) {
                $trigger?->update(['alerted_at' => now()]);
            }

            return self::EVENT_STUCK;
        }

        if ($status['status'] === self::STATUS_OK) {
            $open = QueueHeartbeat::query()->whereNotNull('alerted_at')->whereNull('recovered_at');
            if (! $open->exists()) {
                return null;
            }

            if ($this->send($email, self::EVENT_RECOVERED, $status)) {
                $open->update(['recovered_at' => now()]);
            }

            return self::EVENT_RECOVERED;
        }

        return null;
    }

    private function send(string $email, string $event, array $status): bool
    {
        if ($email === '') {
            Log::channel('queue_heartbeat')->warning("queue {$event} but no alert email is configured (ADMIN_EMAIL / QUEUE_HEARTBEAT_ALERT_EMAIL)");

            return false;
        }

        try {
            // Sent synchronously on purpose: a queued mail would sit behind the
            // very outage it is reporting.
            Mail::to($email)->send(new QueueHeartbeatAlertMail($event, $status));
            Log::channel('queue_heartbeat')->info("queue {$event} email sent", ['to' => $email]);

            return true;
        } catch (Throwable $e) {
            Log::channel('queue_heartbeat')->error("queue {$event} email failed", ['to' => $email, 'error' => $e->getMessage()]);
            Log::error("Queue heartbeat: {$event} email failed", ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** Pending / failed job counts from the database queue, null when unavailable. */
    private function queueStats(): array
    {
        $connection = (string) config('queue.default');
        $stats = ['connection' => $connection, 'pending_jobs' => null, 'oldest_pending_seconds' => null, 'failed_last_24h' => null];

        try {
            $table = (string) config("queue.connections.{$connection}.table", 'jobs');
            if ($connection === 'database') {
                $stats['pending_jobs'] = (int) DB::table($table)->count();
                $oldest = DB::table($table)->min('available_at');
                $stats['oldest_pending_seconds'] = $oldest ? max(0, now()->timestamp - (int) $oldest) : 0;
            }
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');
            $stats['failed_last_24h'] = (int) DB::table($failedTable)->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable $e) {
            // Non-database queue or missing tables: leave the nulls.
        }

        return $stats;
    }

    /** The publish-due scheduler heartbeat, as /api/health reports it. */
    private function schedulerStats(): array
    {
        try {
            $lastRun = Cache::get(PublishDuePosts::HEARTBEAT_KEY);
            $staleAfter = now()->subMinutes(PublishDuePosts::HEARTBEAT_STALE_MINUTES);

            return [
                'last_run_at' => $lastRun,
                'running' => $lastRun !== null && Carbon::parse($lastRun)->gt($staleAfter),
            ];
        } catch (Throwable $e) {
            return ['last_run_at' => null, 'running' => null];
        }
    }
}
