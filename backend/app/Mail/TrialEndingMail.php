<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your free trial is ending — your card is about to be charged" reminder,
 * sent by SendTrialEndingReminders ~48h before a trial's expires_at.
 */
class TrialEndingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $chargeDate,   // preformatted, e.g. "August 14, 2026"
        public ?string $amount,      // preformatted, e.g. "$29.00", or null if unknown
        public string $manageUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your free trial is ending — your card will be charged soon',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trial-ending',
            with: [
                'name' => $this->name,
                'chargeDate' => $this->chargeDate,
                'amount' => $this->amount,
                'manageUrl' => $this->manageUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
