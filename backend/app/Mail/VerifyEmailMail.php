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

    public function __construct(string $token, string $email)
    {
        $this->token = $token;
        $this->email = $email;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email',
        );
    }

    public function content(): Content
    {
        $verifyUrl = rtrim(config('app.frontend_url'), '/') . '/verify-email?token=' . $this->token;

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
