<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>YouTube quota alert</title></head>
<body style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#1a1a1a;line-height:1.5;max-width:560px;margin:24px auto;padding:0 16px">
    <h2 style="margin:0 0 12px">
        @if ($event === 'refused')
            YouTube is refusing <code>{{ $resource }}</code> calls
        @elseif ($event === 'exhausted')
            YouTube <code>{{ $resource }}</code> quota is used up for today
        @else
            YouTube <code>{{ $resource }}</code> quota is at {{ $percent }}%
        @endif
    </h2>

    <p style="margin:0 0 16px">
        @if ($event === 'refused')
            Google answered <strong>quotaExceeded</strong>. Searches and uploads that need this call are now failing for users until the quota resets.
        @elseif ($event === 'exhausted')
            The day's calls have reached Google's allocation. Any further <code>{{ $resource }}</code> call will be refused until the reset.
        @else
            The day's calls are nearing Google's allocation. At 100% further calls are refused until the reset.
        @endif
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:0 0 16px">
        <tr><td style="color:#666">Calls today</td><td><strong>{{ $used }}</strong> of {{ $limit }} ({{ $percent }}%)</td></tr>
        <tr><td style="color:#666">Resets</td><td>{{ $resets_at }} (midnight Pacific)</td></tr>
    </table>

    <p style="margin:0;color:#666;font-size:13px">
        Live numbers: <code>GET /api/admin/youtube-quota/summary</code>. Tier caps (<code>YOUTUBE_QUOTA_SEARCH_FREE/PAID</code>) stop free users first; raise the daily limit env values once Google grants more.
    </p>
</body>
</html>
