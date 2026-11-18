<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your subscription is cancelled</title>
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
        <h1>Your subscription is cancelled</h1>
    </div>

    <div class="content">
        <p>Hi {{ $name }},</p>

        @if ($endsNow)
            <p>Your {{ config('app.name') }} {{ $planName }} subscription has been cancelled and ended on
            <strong>{{ $accessUntil }}</strong>. You won't be charged for it again.</p>
        @else
            <p>Your {{ config('app.name') }} {{ $planName }} subscription has been cancelled. You keep full access
            until <strong>{{ $accessUntil }}</strong>, and you won't be charged again.</p>
        @endif

        <p>Your accounts, posts and saved outliers stay in place. If you change your mind, you can pick a plan
        again at any time.</p>

        <div style="text-align: center;">
            <a href="{{ $billingUrl }}" class="button">Go to Billing</a>
        </div>

        @if ($canReply)
            <div class="note">
                <p>Didn't cancel, or have a question? Just hit reply and a real person on our team will answer.</p>
            </div>
        @endif

        <p>Thanks for using {{ config('app.name') }},<br>
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
