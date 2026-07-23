<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $email;

    /**
     * Create a new message instance.
     */
    public function __construct($token, $email)
    {
        $this->token = $token;
        $this->email = $email;
        
        Log::info('PasswordResetMail instance created', [
            'email' => $email,
            'token_length' => strlen($token),
            'frontend_url' => config('app.frontend_url')
        ]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Reset Request',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $this->token . '&email=' . urlencode($this->email);
        
        Log::info('PasswordResetMail content prepared', [
            'email' => $this->email,
            'reset_url' => $resetUrl,
            'view_exists' => view()->exists('emails.password-reset')
        ]);

        return new Content(
            view: 'emails.password-reset',
            with: [
                'token' => $this->token,
                'email' => $this->email,
                'resetUrl' => $resetUrl,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
