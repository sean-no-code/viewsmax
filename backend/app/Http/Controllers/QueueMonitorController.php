<?php

namespace App\Http\Controllers;

use App\Services\QueueHeartbeatMonitor;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/queue-monitor — the queue heartbeat status for the server itself
 * (restricted to QUEUE_MONITOR_ALLOWED_IPS). 200 while heartbeats are being
 * processed, 503 when the queue is stuck or the scheduler is down, so a local
 * curl or uptime probe can key off the status code alone.
 */
class QueueMonitorController extends Controller
{
    public function __invoke(QueueHeartbeatMonitor $monitor): JsonResponse
    {
        $status = $monitor->status();

        return response()
            ->json($status, $status['healthy'] ? 200 : 503)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }
}
