<?php

namespace App\Mail;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Reconnect your X account" — sent once per needs_reauth episode by
 * SendReconnectEmailJob. Until the user reconnects, every scheduled post to
 * this account will fail, so the email leads with the Connections link.
 */
class AccountNeedsReconnectMail extends Mailable
{
    use Queueable, SerializesModels;

    public SocialAccount $account;

    public function __construct(SocialAccount $account)
    {
        $this->account = $account;
    }

    public function platformLabel(): string
    {
        return config("social.platforms.{$this->account->platform}.label", ucfirst($this->account->platform));
    }

    public function accountName(): string
    {
        return $this->account->name
            ?: ($this->account->username ? '@'.ltrim($this->account->username, '@') : $this->platformLabel().' account');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Reconnect your {$this->platformLabel()} account ({$this->accountName()})");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-reconnect',
            with: [
                'platformLabel' => $this->platformLabel(),
                'accountName' => $this->accountName(),
                'cause' => $this->account->last_error,
                'connectionsUrl' => rtrim(config('app.frontend_url'), '/').'/dashboard/connections',
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
