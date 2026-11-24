<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We couldn't take your payment", sent by BillingEmails once per Stripe
 * invoice when invoice.payment_failed arrives (StripeWebhookController). The
 * account is past_due from that moment, so the email says access is paused
 * and links to Billing (update the card) and to Stripe's hosted invoice (pay
 * now). $nextAttempt is Stripe's next automatic retry, '' when there is none.
 * Replies go to ADMIN_EMAIL (mail.admin_address) when it is set.
 */
class PaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $planName,
        public string $amount,      // e.g. "$59.00 USD"
        public string $nextAttempt, // e.g. "December 17, 2026 at 3:00pm UTC", or ''
        public string $billingUrl,
        public string $invoiceUrl,  // Stripe's hosted invoice page, or ''
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('mail.admin_address');

        return new Envelope(
            subject: 'Your '.config('app.name').' payment didn\'t go through',
            replyTo: $replyTo ? [new Address($replyTo, config('app.name'))] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-failed',
            with: ['canReply' => (bool) config('mail.admin_address')],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
