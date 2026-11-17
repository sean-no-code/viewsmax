<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your free trial ends tomorrow</title>
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
        .perks {
            background-color: #f8f9fa;
            padding: 15px 15px 15px 35px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .note {
            border-top: 1px solid #e9ecef;
            margin-top: 25px;
            padding-top: 15px;
            font-size: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Your free trial ends tomorrow</h1>
    </div>

    <div class="content">
        <p>Hi {{ $name }},</p>

        <p>Your {{ config('app.name') }} free trial ends on <strong>{{ $endsAt }}</strong>. Pick a plan today and
        everything keeps running without a break.</p>

        <p>With a paid plan you keep:</p>

        <ul class="perks">
            <li>Outlier research and AI breakdowns of why videos took off</li>
            <li>Scheduled and instant publishing to your connected accounts</li>
            <li>The AI connector for Claude and other assistants</li>
            <li>Everything you've already set up: accounts, posts and saved outliers</li>
        </ul>

        <div style="text-align: center;">
            <a href="{{ $billingUrl }}" class="button">Choose a plan</a>
        </div>

        <p style="font-size: 13px; color: #6c757d;">Button not working? Copy this link into your browser:<br>
        <span style="word-break: break-all;">{{ $billingUrl }}</span></p>

        <div class="note">
            <p>If you don't choose a plan, your account is paused when the trial ends and you won't be charged
            anything. Scheduled posts won't go out until you upgrade.</p>

            @if ($canReply)
                <p>Questions about which plan fits? Just hit reply and a real person on our team will answer.</p>
            @endif
        </div>

        <p>Thanks for giving {{ config('app.name') }} a try,<br>
        The {{ config('app.name') }} Team</p>
    </div>

    <div class="footer">
        @unless ($canReply)
            <p>This is an automated message. Please do not reply to this email.</p>
        @endunless
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>
