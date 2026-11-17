<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your free trial ends tomorrow": the upgrade nudge sent by
 * SendFreeTrialReminders to card-free signups a day before promo_expires_at
 * (day 6 of User::CARD_FREE_DAYS). Says what stops when the window closes,
 * that nothing is charged, and links to the Billing page to pick a plan.
 * Replies go to ADMIN_EMAIL (mail.admin_address) so a person can answer
 * questions; without it the footer says not to reply. Public properties feed
 * the view directly (see VerifyEmailMail for why nothing here is nullable).
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
        $replyTo = config('mail.admin_address');

        return new Envelope(
            subject: 'Your '.config('app.name').' free trial ends tomorrow',
            replyTo: $replyTo ? [new Address($replyTo, config('app.name'))] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.free-trial-ending',
            with: ['canReply' => (bool) config('mail.admin_address')],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
