<?php

namespace App\Jobs;

use App\Mail\AccountNeedsReconnectMail;
use App\Models\SocialAccount;
use App\Support\UserAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the owner of a social account the moment it flips to needs_reauth
 * (see SocialAccount::markNeedsReauth), naming the platform, the account and
 * the cause, with a link to the Connections page. Queued so the provider
 * code path that detected the dead token is never slowed by SMTP.
 */
class SendReconnectEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $socialAccountId)
    {
    }

    public function handle(): void
    {
        $account = SocialAccount::with('user')->find($this->socialAccountId);

        // Deleted, already reconnected before the queue got here, or an owner who
        // can't use ViewsMax right now (no reconnect nags to lapsed users): nothing to say.
        if (! $account || ! $account->user || $account->status !== SocialAccount::STATUS_NEEDS_REAUTH
            || ! UserAccess::allowsBackgroundWork($account->user)) {
            return;
        }

        Mail::to($account->user->email)->send(new AccountNeedsReconnectMail($account));
    }
}
