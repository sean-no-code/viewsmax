<?php

/*
|--------------------------------------------------------------------------
| Queue heartbeat + monitor URL
|--------------------------------------------------------------------------
|
| Every five minutes the scheduler (queue:heartbeat) records a heartbeat row
| and dispatches QueueHeartbeatJob; when a worker runs it, the row is stamped
| as processed. queue:heartbeat then emails the alert address when heartbeats
| are being dispatched but not processed (workers dead or the queue wedged),
| at most once per cooldown, and again when the queue recovers.
|
| GET /api/queue-monitor exposes the same picture to the server itself. It is
| restricted to QUEUE_MONITOR_ALLOWED_IPS (comma-separated IPs or CIDR ranges,
| anything else gets a 404), carries X-Robots-Tag: noindex and is not listed
| in any sitemap.
|
*/

return [

    'queue_heartbeat' => [
        // QUEUE_HEARTBEAT_ENABLED=false stops dispatching and alerting.
        'enabled' => (bool) env('QUEUE_HEARTBEAT_ENABLED', true),

        // How often the scheduler dispatches a heartbeat. Keep in step with
        // the schedule in bootstrap/app.php.
        'interval_minutes' => 5,

        // A heartbeat still unprocessed this long after dispatch means the
        // workers are not picking up jobs.
        'stuck_after_minutes' => (int) env('QUEUE_HEARTBEAT_STUCK_MINUTES', 10),

        // Where the stuck / recovered emails go. Defaults to ADMIN_EMAIL.
        'alert_email' => env('QUEUE_HEARTBEAT_ALERT_EMAIL', env('ADMIN_EMAIL')),

        // Minimum gap between two "stuck" emails while the outage lasts.
        'alert_cooldown_minutes' => (int) env('QUEUE_HEARTBEAT_ALERT_COOLDOWN_MINUTES', 60),

        // Heartbeat rows older than this are pruned on each tick.
        'retain_days' => 7,
    ],

    // Who may read /api/queue-monitor. IPs or CIDR ranges, comma-separated.
    'monitor_allowed_ips' => env('QUEUE_MONITOR_ALLOWED_IPS', '127.0.0.1,::1'),

];
