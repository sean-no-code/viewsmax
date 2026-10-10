<?php

namespace App\Console\Commands;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\Registration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send a user's verification email to the admin instead of the user, so an
 * operator can see exactly what that user would receive (link included)
 * without touching their inbox. Builds the mail through the same code path as
 * registration and resend, so the token is real and the link works.
 */
class VerifyEmailDryRun extends Command
{
    protected $signature = 'user:verify-email-dry-run
        {email : Email of the user whose verification email to render}
        {--to= : Recipient; defaults to ADMIN_EMAIL}';

    protected $description = 'Send a user\'s verification email to ADMIN_EMAIL (or --to) instead of the user.';

    public function handle(Registration $registration): int
    {
        $email = $this->argument('email');
        $to = $this->option('to') ?: config('mail.admin_address');

        if (! $to) {
            $this->error('No recipient: set ADMIN_EMAIL or pass --to=.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $token = $user->createEmailVerificationToken();
        $mail = new VerifyEmailMail($token, $user->email, $registration->verifyUrlFor($user, $token));

        $this->table(['Field', 'Value'], [
            ['User', "{$user->id} ({$user->email})"],
            ['Signup source', $user->signup_source ?? '-'],
            ['Verified at', $user->email_verified_at?->toDateTimeString() ?? 'not verified'],
            ['Mailer', config('mail.default')],
            ['Sent to', $to],
            ['Verify URL', $mail->verifyUrl],
        ]);

        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            Log::channel('mail')->error('Verification dry run failed to send', [
                'user_id' => $user->id, 'to' => $to, 'exception' => get_class($e), 'error' => $e->getMessage(),
            ]);
            $this->error('Send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::channel('mail')->info('Verification dry run sent', [
            'user_id' => $user->id, 'to' => $to, 'verify_url' => $mail->verifyUrl,
        ]);

        $this->info("Sent to {$to}.");
        $this->warn('The link is live: it verifies and signs in this user for 24 hours.');

        return self::SUCCESS;
    }
}
