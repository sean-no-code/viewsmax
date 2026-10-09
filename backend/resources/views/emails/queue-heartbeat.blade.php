<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event === 'recovered' ? 'Queue recovered' : 'Queue is not processing jobs' }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #ffffff; padding: 30px; border: 1px solid #e9ecef; border-top: none; }
        .banner { border-radius: 5px; padding: 12px 15px; margin: 0 0 20px; }
        .banner.stuck { background-color: #fff5f5; border: 1px solid #f5c6cb; }
        .banner.recovered { background-color: #f0fff4; border: 1px solid #c3e6cb; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0 20px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e9ecef; font-size: 14px; }
        th { width: 45%; color: #6c757d; font-weight: normal; }
        pre { background-color: #f8f9fa; padding: 10px; font-size: 12px; overflow-x: auto; }
        .footer { text-align: center; margin-top: 20px; padding: 15px; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $event === 'recovered' ? 'Queue recovered' : 'Queue is not processing jobs' }}</h1>
    </div>
    <div class="content">
        @if ($event === 'recovered')
            <div class="banner recovered">
                Heartbeat jobs are being processed again. Anything queued during the outage should now be draining.
            </div>
        @else
            <div class="banner stuck">
                Heartbeat jobs have been dispatched but none was processed in the last
                {{ $heartbeat['stuck_after_minutes'] }} minutes. Scheduled posts, uploads, outlier fetches and
                AI jobs are not running until the workers are back.
            </div>
            <p><strong>Check on the server:</strong></p>
            <pre>supervisorctl status
tail -40 /var/log/supervisor/*worker*.log
sudo -u www-data php /var/www/html/artisan queue:work database --once</pre>
        @endif

        <table>
            <tr><th>Status</th><td>{{ $status['status'] }}</td></tr>
            <tr><th>Checked at</th><td>{{ $status['checked_at'] }}</td></tr>
            <tr><th>Last heartbeat dispatched</th><td>{{ $heartbeat['last_dispatched_at'] ?? '—' }}</td></tr>
            <tr><th>Last heartbeat processed</th><td>{{ $heartbeat['last_processed_at'] ?? 'never' }}</td></tr>
            <tr><th>Heartbeats overdue</th><td>{{ $heartbeat['overdue'] }}</td></tr>
            <tr><th>Last queue lag</th><td>{{ $heartbeat['lag_seconds'] !== null ? $heartbeat['lag_seconds'].' s' : '—' }}</td></tr>
            <tr><th>Pending jobs ({{ $queue['connection'] }})</th><td>{{ $queue['pending_jobs'] ?? '—' }}</td></tr>
            <tr><th>Oldest pending job</th><td>{{ $queue['oldest_pending_seconds'] !== null ? $queue['oldest_pending_seconds'].' s' : '—' }}</td></tr>
            <tr><th>Failed jobs (24h)</th><td>{{ $queue['failed_last_24h'] ?? '—' }}</td></tr>
            <tr><th>Scheduler last run</th><td>{{ $scheduler['last_run_at'] ?? 'unknown' }}</td></tr>
        </table>
    </div>
    <div class="footer">
        Sent by the queue heartbeat (config/monitoring.php) because ADMIN_EMAIL or QUEUE_HEARTBEAT_ALERT_EMAIL is set to this address.
    </div>
</body>
</html>
