<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your payment didn't go through</title>
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
        <h1>Your payment didn't go through</h1>
    </div>

    <div class="content">
        <p>Hi {{ $name }},</p>

        <p>We tried to charge <strong>{{ $amount }}</strong> for your {{ config('app.name') }} {{ $planName }}
        subscription and your bank declined the payment.</p>

        <p>Your access is paused until the payment goes through. Your accounts, posts and saved outliers stay in
        place, but scheduled posts won't publish while the account is paused.</p>

        <p>To get going again, update your card in Billing:</p>

        <div style="text-align: center;">
            <a href="{{ $billingUrl }}" class="button">Update payment method</a>
        </div>

        @if ($invoiceUrl)
            <p>Or <a href="{{ $invoiceUrl }}">pay the invoice directly</a>.</p>
        @endif

        @if ($nextAttempt)
            <p>If you do nothing, we'll try the card again on <strong>{{ $nextAttempt }}</strong>.</p>
        @endif

        @if ($canReply)
            <div class="note">
                <p>Think this is a mistake, or have a question? Just hit reply and a real person on our team will
                answer.</p>
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
