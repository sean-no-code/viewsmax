<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your free trial ends tomorrow — choose a plan to keep access", sent by
 * SendFreeTrialReminders to card-free signups a day before promo_expires_at.
 * Public properties feed the view directly (see VerifyEmailMail for why
 * nothing here is nullable).
 */
class FreeTrialEndingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $endsAt,     // preformatted, e.g. "October 11, 2026 at 3:00pm UTC"
        public string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your free trial ends tomorrow',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.free-trial-ending');
    }

    public function attachments(): array
    {
        return [];
    }
}
