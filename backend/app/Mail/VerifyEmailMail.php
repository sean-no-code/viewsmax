<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $token;
    public string $email;
    /** Where the link lands; defaults to the SPA's verify page. */
    public ?string $verifyUrl;

    public function __construct(string $token, string $email, ?string $verifyUrl = null)
    {
        $this->token = $token;
        $this->email = $email;
        $this->verifyUrl = $verifyUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email',
        );
    }

    public function content(): Content
    {
        $verifyUrl = $this->verifyUrl ?? rtrim(config('app.frontend_url'), '/') . '/verify-email?token=' . $this->token;

        return new Content(
            view: 'emails.verify-email',
            with: [
                'verifyUrl' => $verifyUrl,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
