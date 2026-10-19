<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>How's it going so far?</title>
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
        .questions {
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
        <h1>How's it going so far?</h1>
    </div>

    <div class="content">
        <p>Hi {{ $name }},</p>

        <p>You've been trying {{ config('app.name') }} for a little while now, and your free trial ends tomorrow,
        so it feels like a good moment to check in. How's it going so far?</p>

        <p>We'd genuinely love your feedback, good or bad. It shapes what we build next. A few things we're curious about:</p>

        <ul class="questions">
            <li>What were you hoping {{ config('app.name') }} would help you with?</li>
            <li>What's been most useful so far?</li>
            <li>Was anything confusing, or did anything get in your way?</li>
            <li>What's the one thing you wish it did?</li>
        </ul>

        @if ($canReply)
            <p>Just hit reply. Every answer is read by a real person on our team, and a sentence or two is plenty.</p>
        @else
            <p><a href="{{ $feedbackUrl }}">Tell us here</a>. Every answer is read by a real person on our team, and a sentence or two is plenty.</p>
        @endif

        <div class="note">
            <p><strong>About your trial:</strong> it ends on <strong>{{ $endsAt }}</strong>. After that your account is
            paused and you won't be charged anything. Outlier research, publishing and the AI connector stop working
            until you choose a plan.</p>

            <div style="text-align: center;">
                <a href="{{ $billingUrl }}" class="button">Choose a plan</a>
            </div>

            <p style="font-size: 13px; color: #6c757d;">Button not working? Copy this link into your browser:<br>
            <span style="word-break: break-all;">{{ $billingUrl }}</span></p>
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
