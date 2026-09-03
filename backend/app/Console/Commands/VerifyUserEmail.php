<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * DEV convenience: mark a user's email verified without the magic-link email.
 * Dev mail goes to a Mailtrap sandbox (never reaches a real inbox), so this
 * unblocks local/staging testing. Refuses to run in production.
 */
class VerifyUserEmail extends Command
{
    protected $signature = 'user:verify {email : Email of the user to mark verified}';

    protected $description = 'DEV ONLY: mark a user\'s email as verified (bypasses the verification email).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        if ($user->email_verified_at) {
            $this->info("{$email} is already verified.");

            return self::SUCCESS;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Verified {$email} (user {$user->id}). You can now log in.");

        return self::SUCCESS;
    }
}
