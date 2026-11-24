<?php

/*
|--------------------------------------------------------------------------
| Billing emails
|--------------------------------------------------------------------------
|
| Emails ViewsMax sends about a customer's Stripe subscription, through
| App\Services\Billing\BillingEmails. Each send (or failure) is one line in
| storage/logs/billing-YYYY-MM-DD.log (the `billing` log channel,
| BILLING_LOG_ENABLED=false silences it). A failed send is reported and never
| fails the webhook or request that triggered it.
|
| If Stripe's own receipt emails are also on (Dashboard -> Settings ->
| Emails -> "Successful payments"), customers get two receipts per payment:
| turn one of them off.
|
*/

return [

    'emails' => [
        // One email per paid invoice above $0 (StripeWebhookController,
        // invoice.paid / invoice.payment_succeeded / invoice_payment.paid),
        // with the amount, period and links to Stripe's hosted invoice + PDF.
        // BILLING_INVOICE_EMAIL_ENABLED=false stops it without a deploy.
        'invoice' => (bool) env('BILLING_INVOICE_EMAIL_ENABLED', true),

        // Confirmation when a customer cancels in the app
        // (UserPlanController::cancelSubscriptionViaStripe), with the date
        // access ends. BILLING_CANCELLATION_EMAIL_ENABLED=false stops it.
        'cancellation' => (bool) env('BILLING_CANCELLATION_EMAIL_ENABLED', true),
    ],

];
