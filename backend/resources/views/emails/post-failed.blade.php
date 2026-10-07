<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your post failed to publish</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background-color: #ffffff;
            padding: 30px;
            border: 1px solid #e9ecef;
            border-top: none;
        }
        .caption {
            background-color: #f8f9fa;
            border-left: 4px solid #adb5bd;
            padding: 10px 15px;
            margin: 15px 0;
            font-style: italic;
        }
        .failure {
            background-color: #fff5f5;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 12px 15px;
            margin: 10px 0;
        }
        .failure .platform {
            font-weight: bold;
            text-transform: capitalize;
        }
        .failure .error {
            color: #721c24;
            font-size: 14px;
            margin-top: 4px;
        }
        .details {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 10px 15px;
            margin: 15px 0;
            font-size: 14px;
        }
        .details div { margin: 2px 0; }
        .button {
            display: inline-block;
            background-color: #007bff;
            color: #ffffff;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-radius: 0 0 8px 8px;
            font-size: 13px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Post failed to publish</h1>
    </div>
    <div class="content">
        @if ($forAdmin)
            <p>A customer's post failed to publish to {{ $failedTargets->count() === 1 ? 'a platform' : 'some platforms' }}.</p>
            <div class="details">
                <div><strong>Customer:</strong> {{ $ownerName ?: '(no name)' }} &lt;{{ $ownerEmail }}&gt; (user #{{ $ownerId }})</div>
                <div><strong>Post:</strong> #{{ $postId }}@if ($scheduledAt), scheduled for {{ $scheduledAt }}@elseif ($createdAt), created {{ $createdAt }}@endif</div>
            </div>
        @else
            <p>Hi,</p>
            <p>We couldn't publish your post to {{ $failedTargets->count() === 1 ? 'a platform' : 'some platforms' }}:</p>
        @endif

        <div class="caption">{{ $captionExcerpt }}</div>

        @foreach ($failedTargets as $target)
            <div class="failure">
                <div class="platform">{{ $target->platform }}</div>
                <div class="error">{{ $target->error ?: 'Unknown error' }}</div>
            </div>
        @endforeach

        @if ($forAdmin)
            <p>{{ $customerNotified === false ? 'The customer was not emailed (their failure alerts are turned off).' : 'The customer has been sent the matching failure email.' }} They can retry the failed platforms from their post history.</p>
        @else
            <p>You can retry the failed platforms from your post history:</p>
            <p style="text-align: center;">
                <a href="{{ $historyUrl }}" class="button">Open post history</a>
            </p>
            <p>If a platform keeps failing, its account connection may have expired — reconnecting it from the Connections page usually fixes this.</p>
        @endif
    </div>
    <div class="footer">
        @if ($forAdmin)
            You're receiving this because POST_FAILURE_ADMIN_EMAIL is set to this address.
        @else
            You're receiving this because publish-failure alerts are turned on.
            You can turn them off under Settings &rarr; Notifications.
        @endif
    </div>
</body>
</html>
