<?php

namespace App\Services;

use App\Mail\VerifyEmailMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Creates a customer account the same way everywhere a signup can happen: the
 * SPA (POST /api/register) and the API host's /register used mid OAuth flow by
 * AI agents. Validation and the response belong to the caller; this owns the
 * side effects: free credits (no time limit), customer role, the
 * Registered event, the Kit newsletter opt-in and the verification email.
 */
class Registration
{
    public function __construct(
        protected CreditService $credits,
        protected KitService $kit,
        protected GeoLocationService $geo,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, marketing_consent?: bool}  $data  plain password
     */
    public function register(array $data, string $source, ?string $client = null): User
    {
        // Unverified and not onboarded. No card and no time limit: the free
        // credits granted below last until they are used up.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'onboarding_completed_at' => null,
            'free_credits_at' => now(),
            'marketing_consented_at' => ! empty($data['marketing_consent']) ? now() : null,
            'signup_source' => $source,
            'signup_client' => $client,
        ]);

        $this->recordSignupCountry($user);

        if ($customerRole = Role::where('name', 'customer')->first()) {
            $user->roles()->attach($customerRole->id);
        }

        $this->credits->addCredits($user, config('credits.registration_bonus'), 'Registration Bonus');

        event(new Registered($user));

        // A Kit outage must never block registration — the account already exists.
        if ($user->marketing_consented_at) {
            try {
                $this->kit->subscribe($user->email, $user->name, $source === User::SIGNUP_SOURCE_AGENT
                    ? [config('services.kit.agent_tag')]
                    : []);
            } catch (\Throwable $e) {
                Log::warning('Kit signup subscribe failed for '.$user->email.': '.$e->getMessage());
            }
        }

        $this->sendVerificationEmail($user);

        return $user;
    }

    /**
     * Stamp the signup country into the last-login columns so a user who has
     * never signed in to the SPA (e.g. an agent signup) still shows a country in
     * admin. Best-effort: an unresolved lookup leaves the columns null.
     */
    private function recordSignupCountry(User $user): void
    {
        $request = request();
        $cdnCountry = $request->header('CloudFront-Viewer-Country') ?? $request->header('CF-IPCountry');
        [$country, $code] = $this->geo->lookup($request->ip(), $cdnCountry);

        if ($code) {
            $user->forceFill([
                'last_login_country' => $country,
                'last_login_country_code' => $code,
            ])->save();
        }
    }

    /**
     * Create a verification token and email the magic link, logging each step so
     * delivery failures (SMTP auth/connection, misconfigured mailer) are
     * traceable in production. Never throws — returns false on failure.
     */
    public function sendVerificationEmail(User $user): bool
    {
        try {
            Log::channel('mail')->info('Sending verification email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mailer' => config('mail.default'),
            ]);

            $token = $user->createEmailVerificationToken();
            Mail::to($user->email)->send(new VerifyEmailMail($token, $user->email, $this->verifyUrlFor($user, $token)));

            Log::channel('mail')->info('Verification email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::channel('mail')->error('Verification email failed to send', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mailer' => config('mail.default'),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Agent signups verify on the API host so the link drops them back into
     * the signup → connect → consent flow; app signups verify in the SPA.
     */
    public function verifyUrlFor(User $user, string $token): ?string
    {
        return $user->signup_source === User::SIGNUP_SOURCE_AGENT
            ? route('verify-email.web', ['token' => $token])
            : null;
    }

    /**
     * Redeem a magic-link token: marks the email verified on first use and
     * returns the user, or null when the token is unknown or expired. A token
     * already consumed but still within validity returns the user again
     * (idempotent, so a double-clicked link still works).
     */
    public function verifyToken(string $token): ?User
    {
        $record = DB::table('email_verification_tokens')
            ->where('token', hash('sha256', $token))
            ->first();

        if (! $record || now()->greaterThan($record->expires_at)) {
            return null;
        }

        $user = User::find($record->user_id);
        if (! $user) {
            return null;
        }

        if (is_null($record->consumed_at)) {
            $user->forceFill(['email_verified_at' => now()])->save();
            DB::table('email_verification_tokens')
                ->where('id', $record->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }
}
