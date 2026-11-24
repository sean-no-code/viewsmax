<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation of a cancel the customer made in the app
 * (UserPlanController::cancelSubscriptionViaStripe), sent by BillingEmails.
 * Says when access ends, as Stripe reports it, with the timezone in the
 * date. $endsNow = the subscription ended immediately (a past-due cancel),
 * rather than at the end of the paid period. Replies go to ADMIN_EMAIL
 * (mail.admin_address) when it is set.
 */
class SubscriptionCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $planName,
        public string $accessUntil, // e.g. "December 17, 2026 at 3:00pm UTC"
        public bool $endsNow,
        public string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('mail.admin_address');

        return new Envelope(
            subject: 'Your '.config('app.name').' subscription is cancelled',
            replyTo: $replyTo ? [new Address($replyTo, config('app.name'))] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-cancelled',
            with: ['canReply' => (bool) config('mail.admin_address')],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
