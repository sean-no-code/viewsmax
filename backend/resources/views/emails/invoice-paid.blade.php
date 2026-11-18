<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your invoice</title>
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
        .summary {
            width: 100%;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin: 20px 0;
            padding: 10px 15px;
            border-collapse: separate;
        }
        .summary td {
            padding: 6px 0;
            vertical-align: top;
        }
        .summary td.label {
            color: #6c757d;
            width: 40%;
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
        <h1>Thanks for your payment</h1>
    </div>

    <div class="content">
        <p>Hi {{ $name }},</p>

        <p>We received your payment for {{ config('app.name') }}. Here are the details for your records.</p>

        <table class="summary" role="presentation">
            <tr><td class="label">Plan</td><td>{{ $planName }}</td></tr>
            <tr><td class="label">Amount paid</td><td><strong>{{ $amount }}</strong></td></tr>
            @if ($periodStart !== '' && $periodEnd !== '')
                <tr><td class="label">Billing period</td><td>{{ $periodStart }} to {{ $periodEnd }}</td></tr>
            @endif
            <tr><td class="label">Paid on</td><td>{{ $paidAt }}</td></tr>
            @if ($invoiceNumber !== '')
                <tr><td class="label">Invoice number</td><td>{{ $invoiceNumber }}</td></tr>
            @endif
        </table>

        @if ($invoiceUrl !== '')
            <div style="text-align: center;">
                <a href="{{ $invoiceUrl }}" class="button">View invoice</a>
            </div>
        @endif

        @if ($pdfUrl !== '')
            <p style="text-align: center;"><a href="{{ $pdfUrl }}">Download the invoice as a PDF</a></p>
        @endif

        <div class="note">
            <p>Past invoices, your payment method and your plan are on your
            <a href="{{ $billingUrl }}">Billing page</a>.</p>

            @if ($canReply)
                <p>Questions about this charge? Just hit reply and a real person on our team will answer.</p>
            @endif
        </div>

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
