<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The customer's invoice for one paid billing cycle, sent by BillingEmails
 * when Stripe reports a paid invoice above $0. Amount, period and paid date
 * come preformatted; the links go to Stripe's hosted invoice page and PDF.
 * Replies go to ADMIN_EMAIL (mail.admin_address) when it is set. Public
 * properties feed the view directly (see VerifyEmailMail for why nothing
 * here is nullable: an empty string means "not available").
 */
class InvoicePaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $planName,
        public string $amount,        // e.g. "$59.00 USD"
        public string $invoiceNumber, // Stripe's invoice number, '' if none
        public string $periodStart,   // e.g. "November 17, 2026", '' if unknown
        public string $periodEnd,
        public string $paidAt,        // e.g. "November 17, 2026 at 3:00pm UTC"
        public string $invoiceUrl,    // Stripe hosted invoice page, '' if none
        public string $pdfUrl,        // Stripe invoice PDF, '' if none
        public string $billingUrl,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('mail.admin_address');

        return new Envelope(
            subject: 'Your '.config('app.name').' invoice: '.$this->amount.' paid',
            replyTo: $replyTo ? [new Address($replyTo, config('app.name'))] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-paid',
            with: ['canReply' => (bool) config('mail.admin_address')],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
