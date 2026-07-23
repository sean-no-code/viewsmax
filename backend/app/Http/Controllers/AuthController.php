<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetMail;
use App\Mail\VerifyEmailMail;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * @group Auth
 *
 * Session authentication. POST /api/login returns a bearer token with full
 * account access. AI agents should prefer a vmx_ API key (Settings → AI
 * Assistant Access) or the MCP OAuth flow instead of storing passwords.
 */
class AuthController extends Controller
{
    /**
     * Register a new user
     *
     * @unauthenticated
     */
    public function register(Request $request, CreditService $creditService)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'marketing_consent' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Create the user UNVERIFIED and NOT onboarded. Registration does not
            // log the user in — they must verify their email via the magic link first.
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verified_at' => null,
                'onboarding_completed_at' => null,
                'marketing_consented_at' => $request->boolean('marketing_consent') ? now() : null,
            ]);

            // Assign customer role by default
            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole) {
                $user->roles()->attach($customerRole->id);
            }

            // Add registration bonus credits
            $registrationBonus = config('credits.registration_bonus');
            $creditService->addCredits($user, $registrationBonus, 'Registration Bonus');

            event(new Registered($user));

            // Send the magic-link verification email. A delivery failure is logged
            // (see sendVerificationEmail) but must NOT fail registration — the
            // account already exists and the user can trigger a resend.
            $this->sendVerificationEmail($user);

            return response()->json([
                'success' => true,
                'message' => 'verification_sent',
                'email' => $user->email,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Login user
     *
     * @unauthenticated
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Find user by email
            $user = User::where('email', $request->email)->first();

            // Check if user exists and password is correct
            if (! $user || ! Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            // Block unverified users so the SPA can surface a "resend" affordance.
            // The message MUST contain "not_verified" — the frontend matches on it.
            if (is_null($user->email_verified_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'email_not_verified',
                    'email' => $user->email,
                ], 403);
            }

            return response()->json($this->loginPayload($user));

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build the standard login payload (issues a fresh API token).
     * Reused by login and email verification.
     */
    private function loginPayload(User $user): array
    {
        $this->recordLogin($user);

        $user->load(['roles', 'plans']);
        $token = $user->createToken('mobile-app')->plainTextToken;

        return [
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user->apiPayload(),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'user_id' => $user->id,
            'active_plan' => $user->activePlan(),
            'user_credits' => $user->balanceInt,
        ];
    }

    /**
     * Record when and from where the user logged in. Country is resolved
     * best-effort from the IP; a slow/failed lookup must never break login, and
     * a known country is preserved when the lookup can't resolve a new one.
     */
    private function recordLogin(User $user): void
    {
        $ip = request()->ip();
        $data = ['last_login_at' => now(), 'last_login_ip' => $ip];

        [$country, $code] = $this->lookupCountry($ip);
        if ($country) {
            $data['last_login_country'] = $country;
            $data['last_login_country_code'] = $code;
        }

        $user->forceFill($data)->save();
    }

    /**
     * Resolve [country name, ISO code] for an IP via the free ip-api.com service.
     * Returns [null, null] on any failure — wrapped so it can never throw.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function lookupCountry(?string $ip): array
    {
        // Only public IPs are worth resolving — skip localhost / LAN / reserved
        // ranges (this also keeps tests from hitting the network on 127.0.0.1).
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [null, null];
        }

        try {
            $res = Http::timeout(2)->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,country,countryCode']);
            if ($res->ok() && $res->json('status') === 'success') {
                return [$res->json('country'), $res->json('countryCode')];
            }
        } catch (\Throwable $e) {
            // Best-effort only — swallow network/parse errors so login is unaffected.
        }

        return [null, null];
    }

    /**
     * Verify a user's email via the magic-link token. Logs the user in on success.
     * Idempotent under React StrictMode double-calls.
     */
    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'This verification link is invalid or has expired.',
            ], 422);
        }

        $record = DB::table('email_verification_tokens')
            ->where('token', hash('sha256', $request->token))
            ->first();

        if (! $record || now()->greaterThan($record->expires_at)) {
            return response()->json([
                'message' => 'This verification link is invalid or has expired.',
            ], 422);
        }

        $user = User::find($record->user_id);
        if (! $user) {
            return response()->json([
                'message' => 'This verification link is invalid or has expired.',
            ], 422);
        }

        // First consumption: mark verified + consume the token. If already consumed
        // but still within validity and the user is verified, fall through and
        // re-issue the login payload (idempotent).
        if (is_null($record->consumed_at)) {
            $user->forceFill(['email_verified_at' => now()])->save();
            DB::table('email_verification_tokens')
                ->where('id', $record->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
        }

        return response()->json($this->loginPayload($user));
    }

    /**
     * Resend a verification magic link. Always returns 200 (no account enumeration).
     */
    public function resendVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => true]);
        }

        $user = User::where('email', $request->email)->first();

        if ($user && is_null($user->email_verified_at)) {
            $this->sendVerificationEmail($user);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Create a verification token and email the magic link, logging each step so
     * delivery failures (SMTP auth/connection, misconfigured mailer) are
     * traceable in production. Never throws — returns false on failure.
     */
    private function sendVerificationEmail(User $user): bool
    {
        try {
            Log::channel('mail')->info('Sending verification email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mailer' => config('mail.default'),
            ]);

            $token = $user->createEmailVerificationToken();
            Mail::to($user->email)->send(new VerifyEmailMail($token, $user->email));

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
     * Logout user (revoke token)
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get authenticated user profile
     */
    public function profile(Request $request)
    {
        try {
            $user = $request->user();
            $user->load(['roles', 'plans']);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user->apiPayload(),
                ],
                'user_credits' => $user->balanceInt,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh token (create new token and revoke old one)
     */
    public function refresh(Request $request)
    {
        try {
            $user = $request->user();

            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            // Create new token
            $token = $user->createToken('mobile-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send password reset email
     */
    public function forgotPassword(Request $request)
    {
        Log::channel('mail')->info('Password reset request initiated', ['email' => $request->email, 'ip' => $request->ip()]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
        ]);

        if ($validator->fails()) {
            Log::warning('Password reset validation failed', [
                'email' => $request->email,
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (! $user) {
                Log::channel('mail')->info('Password reset requested for non-existent email', ['email' => $request->email]);

                // For security, return success even if user doesn't exist
                return response()->json([
                    'success' => true,
                    'message' => 'If an account with that email exists, we have sent a password reset link.',
                ]);
            }

            Log::info('User found for password reset', ['user_id' => $user->id, 'email' => $user->email]);

            // Create password reset token
            $token = $user->createPasswordResetToken();
            Log::info('Password reset token created', ['user_id' => $user->id, 'token_length' => strlen($token)]);

            // Log mail configuration before sending
            Log::channel('mail')->info('Mail configuration check', [
                'mail_driver' => config('mail.default'),
                'mail_host' => config('mail.mailers.smtp.host'),
                'mail_port' => config('mail.mailers.smtp.port'),
                'mail_username' => config('mail.mailers.smtp.username'),
                'mail_encryption' => config('mail.mailers.smtp.encryption'),
                'mail_from_address' => config('mail.from.address'),
                'mail_from_name' => config('mail.from.name'),
            ]);

            // Send password reset email
            Log::channel('mail')->info('Attempting to send password reset email', [
                'to' => $user->email,
                'user_id' => $user->id,
            ]);

            Mail::to($user->email)->send(new PasswordResetMail($token, $user->email));

            Log::channel('mail')->info('Password reset email sent successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'If an account with that email exists, we have sent a password reset link.',
            ]);

        } catch (\Exception $e) {
            Log::channel('mail')->error('Password reset email failed', [
                'email' => $request->email,
                'mailer' => config('mail.default'),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send password reset email',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset password using token
     */
    public function resetPassword(Request $request)
    {
        Log::info('Password reset attempt initiated', [
            'email' => $request->email,
            'ip' => $request->ip(),
            'token_length' => strlen($request->token ?? ''),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            Log::warning('Password reset validation failed', [
                'email' => $request->email,
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (! $user) {
                Log::warning('Password reset attempted with invalid email', ['email' => $request->email]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email address',
                ], 400);
            }

            Log::info('User found for password reset', ['user_id' => $user->id, 'email' => $user->email]);

            // Verify the reset token
            if (! $user->verifyPasswordResetToken($request->token)) {
                Log::warning('Invalid or expired reset token', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'token_length' => strlen($request->token),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired reset token',
                ], 400);
            }

            Log::info('Reset token verified successfully', ['user_id' => $user->id]);

            // Reset the password
            $user->resetPassword($request->password);

            Log::info('Password reset completed successfully', ['user_id' => $user->id, 'email' => $user->email]);

            return response()->json([
                'success' => true,
                'message' => 'Password has been reset successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Password reset failed', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
