<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your free trial is ending</title>
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
            font-size: 14px;
            color: #6c757d;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Your free trial is ending soon</h1>
    </div>

    <div class="content">
        <p>Hi {{ $name }},</p>

        <p>Your {{ config('app.name') }} free trial ends on <strong>{{ $chargeDate }}</strong>.</p>

        <div class="warning">
            <strong>Heads up:</strong> in about 48 hours, on {{ $chargeDate }},
            @if ($amount)
                your card will be charged <strong>{{ $amount }}</strong> and your subscription will begin.
            @else
                your card will be charged and your subscription will begin.
            @endif
        </div>

        <p>No action is needed if you'd like to continue — you'll keep full access. If you'd rather not
        be charged, you can cancel any time before then:</p>

        <div style="text-align: center;">
            <a href="{{ $manageUrl }}" class="button">Manage your subscription</a>
        </div>

        <p>Thanks for trying {{ config('app.name') }}!</p>

        <p>Best regards,<br>
        The {{ config('app.name') }} Team</p>
    </div>

    <div class="footer">
        <p>This is an automated message. Please do not reply to this email.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>
