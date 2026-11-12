<?php

namespace App\Services;

use App\Models\User;
use App\Services\Social\YouTubeDisconnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * GDPR erasure: revoke every grant, delete everything the user owns, and leave
 * a tombstone row that can't be logged into or matched by email.
 *
 * Run from `user:erase {email}`. plan() says what erase() would do, from the
 * same lookups, so the dry run can't drift from the real one.
 *
 * Order: remote revokes first (they need the tokens), Stripe before the
 * customer id is scrubbed, local rows, then the user row last. Remote steps
 * are best effort — a provider outage is reported in the result and logged to
 * storage/logs/user-erasure.log, never a reason to keep the data.
 *
 * What survives, on purpose:
 *  - social_handle_claims: still point at the tombstone, so the same channel
 *    can't be used to sign up again (HandleClaims).
 *  - youtube_quota_spends: the quota ledger keeps its rows with user_id nulled.
 *  - The user row itself, soft-deleted, with every personal column scrubbed.
 */
final class UserErasure
{
    public const ERASED_EMAIL_DOMAIN = 'erased.invalid';

    /** user_id tables that are not deleted (see the class docblock). */
    private const KEEP_TABLES = ['social_handle_claims', 'youtube_quota_spends'];

    /** Per-user prefixes on the public disk. The media disk holds posts/{id}. */
    private const PUBLIC_PREFIXES = ['thumbnails', 'copy-thumbnails', 'generated-images', 'ai-models', 'user-defaults'];

    /** outlier_db has no FKs to users; these carry a plain user_id. */
    private const OUTLIER_TABLES = [
        'search_terms_requests', 'outlier_saved_filters', 'saved_outliers',
        'outlier_tags', 'outlier_competitor_channels', 'outlier_channel_ingests',
    ];

    public function __construct(
        private readonly StripeService $stripe,
        private readonly KlaviyoService $klaviyo,
        private readonly KitService $kit,
    ) {}

    public static function erasedEmail(int $userId): string
    {
        return 'erased+'.$userId.'@'.self::ERASED_EMAIL_DOMAIN;
    }

    public static function isErased(User $user): bool
    {
        return $user->trashed() && $user->email === self::erasedEmail($user->id);
    }

    /**
     * What erase() would do. Pure read.
     *
     * @return array{
     *   tables: array<string, int>, outlier_tables: array<string, int>,
     *   platforms: list<string>, local_only_platforms: list<string>,
     *   stripe_subscriptions: list<string>, stripe_customer_id: ?string,
     *   tokens: array{sanctum: int, passport: int},
     *   files: list<string>, remote: array<string, string>
     * }
     */
    public function plan(User $user): array
    {
        $tables = [];
        foreach ($this->userTables() as $table) {
            $tables[$table] = DB::table($table)->where('user_id', $user->id)->count();
        }

        $outlier = [];
        foreach (self::OUTLIER_TABLES as $table) {
            if (Schema::connection('outlier_db')->hasTable($table)) {
                $outlier[$table] = DB::connection('outlier_db')->table($table)->where('user_id', $user->id)->count();
            }
        }

        $platforms = $user->socialAccounts()->distinct()->pluck('platform')->sort()->values()->all();

        return [
            'tables' => $tables,
            'outlier_tables' => $outlier,
            'platforms' => $platforms,
            'local_only_platforms' => array_values(array_diff($platforms, ['youtube'])),
            'stripe_subscriptions' => $this->stripeSubscriptionIds($user),
            'stripe_customer_id' => $user->stripe_customer_id,
            'tokens' => [
                'sanctum' => $user->tokens()->count(),
                'passport' => DB::table('oauth_access_tokens')->where('user_id', $user->id)->count(),
            ],
            'files' => $this->filePrefixes($user),
            'remote' => [
                'stripe' => $this->stripeConfigured() ? 'will run' : 'skipped (no key)',
                'klaviyo' => $this->klaviyo->configured() ? 'will run' : 'skipped (no key)',
                'kit' => $this->kit->configured() ? 'will run' : 'skipped (no key)',
            ],
        ];
    }

    /**
     * @return array{
     *   google_revoked: int, local_only_platforms: list<string>,
     *   tables: array<string, int>, outlier_tables: array<string, int>,
     *   tokens: array{sanctum: int, passport: int},
     *   steps: array<string, 'ok'|'failed'|'skipped'>, failures: list<string>
     * }
     */
    public function erase(User $user): array
    {
        $plan = $this->plan($user);
        $email = $user->email;
        $steps = [];
        $failures = [];
        $fail = function (string $step, Throwable|string $why) use (&$steps, &$failures) {
            $steps[$step] = 'failed';
            $failures[] = $step.': '.($why instanceof Throwable ? $why->getMessage() : $why);
        };

        // 1. Remote grants. Google is the only provider with a revoke call;
        //    the rest only lose their rows here (reported as local_only).
        $google = ['revoked' => 0];
        try {
            $google = YouTubeDisconnector::disconnect($user);
            $steps['google'] = $google['revoked'] > 0 || $plan['tables']['social_accounts'] === 0 ? 'ok' : 'failed';
            if ($steps['google'] === 'failed' && in_array('youtube', $plan['platforms'], true)) {
                $failures[] = 'google: no grant could be revoked';
            }
        } catch (Throwable $e) {
            $fail('google', $e);
        }

        // 2. Agent access: Sanctum (login + vmx_ key) and Passport (Claude/ChatGPT).
        $user->tokens()->delete();
        $this->deletePassportGrants($user);
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // 3. Stripe: stop billing, then drop the customer (card + email).
        if (! $this->stripeConfigured()) {
            $steps['stripe'] = 'skipped';
        } else {
            try {
                foreach ($plan['stripe_subscriptions'] as $subscriptionId) {
                    $this->stripe->cancelSubscription($subscriptionId, 'GDPR erasure', true);
                }
                if ($user->stripe_customer_id) {
                    $this->stripe->deleteCustomer($user->stripe_customer_id);
                }
                $steps['stripe'] = 'ok';
            } catch (Throwable $e) {
                $fail('stripe', $e);
            }
        }

        // 4. Mailing lists.
        $steps['klaviyo'] = $this->remote('klaviyo', fn () => $this->klaviyo->configured() ? $this->klaviyo->deleteProfile($email) : null, $fail);
        $steps['kit'] = $this->remote('kit', fn () => $this->kit->configured() ? $this->kit->unsubscribe($email) : null, $fail);

        // 5. Files.
        foreach ($plan['files'] as $location) {
            [$disk, $prefix] = explode(':', $location, 2);
            try {
                Storage::disk($disk)->deleteDirectory($prefix);
            } catch (Throwable $e) {
                $fail("files {$location}", $e);
            }
        }

        // 6 + 7. Rows. outlier_db is its own connection, so its own transaction.
        DB::connection('outlier_db')->transaction(function () use ($user) {
            foreach (array_keys($this->plan($user)['outlier_tables']) as $table) {
                DB::connection('outlier_db')->table($table)->where('user_id', $user->id)->delete();
            }
        });

        DB::transaction(function () use ($user) {
            DB::table('youtube_quota_spends')->where('user_id', $user->id)->update(['user_id' => null]);
            $this->deleteWallet($user);
            foreach ($this->userTables() as $table) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
            $this->tombstone($user);
        });

        $result = [
            'google_revoked' => (int) $google['revoked'],
            'local_only_platforms' => $plan['local_only_platforms'],
            'tables' => $plan['tables'],
            'outlier_tables' => $plan['outlier_tables'],
            'tokens' => $plan['tokens'],
            'steps' => $steps,
            'failures' => $failures,
        ];

        Log::channel('user_erasure')->info('User erased', ['user_id' => $user->id] + $result);

        return $result;
    }

    /**
     * Every table on the default connection with a user_id column, minus the
     * ones kept on purpose. Derived from the schema so a new table can't be
     * missed; UserErasureTest pins the expected set.
     *
     * @return list<string>
     */
    public function userTables(): array
    {
        $tables = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::KEEP_TABLES, true) || $table === 'users') {
                continue;
            }
            if (Schema::hasColumn($table, 'user_id')) {
                $tables[] = $table;
            }
        }
        sort($tables);

        return $tables;
    }

    /** @return list<string> "disk:prefix" */
    private function filePrefixes(User $user): array
    {
        $files = [config('filesystems.media_disk').":posts/{$user->id}"];
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            $files[] = "public:{$prefix}/{$user->id}";
        }

        return $files;
    }

    /** @return list<string> */
    private function stripeSubscriptionIds(User $user): array
    {
        return $user->plans()
            ->wherePivotIn('status', ['active', 'trialing', 'past_due'])
            ->wherePivotNotNull('stripe_subscription_id')
            ->get()
            ->map(fn ($plan) => (string) $plan->pivot->stripe_subscription_id)
            ->unique()
            ->values()
            ->all();
    }

    private function stripeConfigured(): bool
    {
        return ! empty(config('services.stripe.secret'));
    }

    /** Run a best-effort remote step: null = skipped, false = failed, true = ok. */
    private function remote(string $step, callable $call, callable $fail): string
    {
        try {
            $outcome = $call();
        } catch (Throwable $e) {
            $fail($step, $e);

            return 'failed';
        }

        if ($outcome === null) {
            return 'skipped';
        }
        if ($outcome === false) {
            $fail($step, 'the provider refused the request (see the log)');

            return 'failed';
        }

        return 'ok';
    }

    /**
     * Passport keeps no user_id on refresh tokens, so they go through the
     * access tokens. Rows are deleted, not flagged revoked: the row is the data.
     */
    private function deletePassportGrants(User $user): void
    {
        $accessTokenIds = DB::table('oauth_access_tokens')->where('user_id', $user->id)->pluck('id');
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $accessTokenIds)->delete();
        DB::table('oauth_access_tokens')->where('user_id', $user->id)->delete();
        DB::table('oauth_auth_codes')->where('user_id', $user->id)->delete();
        if (Schema::hasTable('oauth_device_codes')) {
            DB::table('oauth_device_codes')->where('user_id', $user->id)->delete();
        }
    }

    /** bavix/laravel-wallet links by morph, so no cascade. */
    private function deleteWallet(User $user): void
    {
        $walletIds = DB::table('wallets')->where('holder_type', $user->getMorphClass())->where('holder_id', $user->id)->pluck('id');
        DB::table('transfers')->whereIn('from_id', $walletIds)->orWhereIn('to_id', $walletIds)->delete();
        DB::table('transactions')->where('payable_type', $user->getMorphClass())->where('payable_id', $user->id)->delete();
        DB::table('wallets')->whereIn('id', $walletIds)->delete();
    }

    /** Scrub every personal column, then soft-delete. Keeps id, public_id, created_at. */
    private function tombstone(User $user): void
    {
        $user->forceFill([
            'email' => self::erasedEmail($user->id),
            'name' => 'Erased user',
            'password' => Str::random(64),
            'email_verified_at' => null,
            'remember_token' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'last_login_country' => null,
            'last_login_country_code' => null,
            'youtube_access_token' => null,
            'youtube_refresh_token' => null,
            'youtube_token_expires_at' => null,
            'privacy_consent_at' => null,
            'privacy_consent_version' => null,
            'privacy_consent_ip' => null,
            'privacy_consent_user_agent' => null,
            'marketing_consented_at' => null,
            'stripe_customer_id' => null,
            'default_reference_image_path' => null,
            'signup_client' => null,
            'promo_expires_at' => null,
            'onboarding_completed_at' => null,
        ])->saveQuietly();

        $user->delete();
    }
}
