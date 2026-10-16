<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "How's it going so far?": a check-in asking for feedback, sent by
 * SendFreeTrialReminders to card-free signups a day before promo_expires_at.
 * It still says the trial ends tomorrow, that nothing is charged, and how to
 * pick a plan. Replies go to ADMIN_EMAIL (mail.admin_address) so a person reads
 * them; without it the email links to the feedback page instead of inviting
 * replies to the no-reply sender. Public properties feed the view directly
 * (see VerifyEmailMail for why nothing here is nullable).
 */
class FreeTrialEndingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $endsAt,     // preformatted, e.g. "October 11, 2026 at 3:00pm UTC"
        public string $billingUrl,
        public string $feedbackUrl,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('mail.admin_address');

        return new Envelope(
            subject: "How's it going so far?",
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
