<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reconnect your {{ $platformLabel }} account</title>
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
        .failure {
            background-color: #fff5f5;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 12px 15px;
            margin: 10px 0;
        }
        .failure .platform {
            font-weight: bold;
        }
        .failure .error {
            color: #721c24;
            font-size: 14px;
            margin-top: 4px;
        }
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
        <h1>Reconnect your {{ $platformLabel }} account</h1>
    </div>
    <div class="content">
        <p>Hi,</p>
        <p>ViewsMax can no longer post to your {{ $platformLabel }} account <strong>{{ $accountName }}</strong>. Until you reconnect it, every scheduled post to this account will fail.</p>

        <div class="failure">
            <div class="platform">{{ $platformLabel }} &mdash; {{ $accountName }}</div>
            <div class="error">{{ $cause ?: 'The platform stopped accepting our access token.' }}</div>
        </div>

        <p>Reconnecting takes a few seconds from the Connections page:</p>
        <p style="text-align: center;">
            <a href="{{ $connectionsUrl }}" class="button">Reconnect {{ $platformLabel }}</a>
        </p>
        <p>This usually happens when the platform expires or revokes the login, or when you change your password there.</p>
    </div>
    <div class="footer">
        You're receiving this because a connected account on ViewsMax needs your attention.
    </div>
</body>
</html>
