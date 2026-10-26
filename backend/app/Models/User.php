<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements Wallet
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasWallet, Notifiable, SoftDeletes;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->public_id = (string) Str::uuid();
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'onboarding_completed_at',
        'last_login_at',
        'last_login_ip',
        'last_login_country',
        'last_login_country_code',
        'youtube_access_token',
        'youtube_refresh_token',
        'youtube_token_expires_at',
        'privacy_consent_at',
        'privacy_consent_version',
        'privacy_consent_ip',
        'privacy_consent_user_agent',
        'marketing_consented_at',
        'public_id',
        'default_reference_image_path',
        'stripe_customer_id',
        'notify_post_failures',
        'locale',
        'promo_expires_at',
        'signup_source',
        'signup_client',
    ];

    /** Where the account was created; see the signup_source migration. */
    public const SIGNUP_SOURCE_APP = 'app';

    public const SIGNUP_SOURCE_AGENT = 'agent';

    public const SIGNUP_SOURCE_ADMIN = 'admin';

    public const SIGNUP_SOURCES = [self::SIGNUP_SOURCE_APP, self::SIGNUP_SOURCE_AGENT, self::SIGNUP_SOURCE_ADMIN];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'youtube_access_token',
        'youtube_refresh_token',
    ];

    /**
     * The one spelling an address is stored and looked up under. Postgres
     * compares the users.email unique index case-sensitively, so without this
     * "Jane@Example.com" and "jane@example.com" were two accounts and a login
     * typed in the other case failed.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        return $email === null ? null : mb_strtolower(trim($email));
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normalizeEmail($value));
    }

    /** Find by address as typed: User::byEmail($request->email)->first(). */
    public function scopeByEmail(Builder $query, ?string $email): Builder
    {
        return $query->where('email', static::normalizeEmail($email));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'card_added_at' => 'datetime',
            'promo_expires_at' => 'datetime',
            'free_trial_reminder_sent_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'youtube_token_expires_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
            'marketing_consented_at' => 'datetime',
            'notify_post_failures' => 'boolean',
        ];
    }

    /**
     * The roles that belong to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /**
     * The plans that belong to the user.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'user_plans')
            ->withPivot(['stripe_subscription_id', 'stripe_price_id', 'status', 'starts_at', 'expires_at', 'cancelled_at'])
            ->withTimestamps();
    }

    /**
     * The thumbnails that belong to the user.
     */
    public function thumbnails(): HasMany
    {
        return $this->hasMany(Thumbnail::class);
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    /**
     * Check if the user has any of the given roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->exists();
    }

    /**
     * The channels that belong to the user.
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * The videos that belong to the user (through channels).
     */
    public function videos(): HasManyThrough
    {
        return $this->hasManyThrough(Video::class, Channel::class);
    }

    /**
     * Statuses that count as a live, paid-or-trialing subscription.
     */
    public const ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];

    /**
     * Get the user's active plan (active or trialing).
     * The connected social media accounts that belong to the user.
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * The social posts authored by the user.
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * Get the user's active plan.
     */
    public function activePlan()
    {
        return $this->plans()->wherePivotIn('status', self::ACTIVE_SUBSCRIPTION_STATUSES)->first();
    }

    /**
     * Check if the user has an active plan (active or trialing).
     */
    public function hasActivePlan(): bool
    {
        return $this->plans()->wherePivotIn('status', self::ACTIVE_SUBSCRIPTION_STATUSES)->exists();
    }

    /**
     * Whether the user has a subscription that is active or trialing
     * (PayPal or Stripe) OR an open free window. Drives the onboarding
     * gate (card step) and the `has_active_subscription` payload flag.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->hasActivePlan() || $this->hasActivePromo();
    }

    public const PROMO_ROLE = 'promotional_customer';

    public const ADMIN_ROLE = 'admin';

    public function isPromotional(): bool
    {
        return $this->hasRole(self::PROMO_ROLE);
    }

    /**
     * Admins and promotional customers are never gated: no plan, card or
     * window is required of them, anywhere (UserAccess, the SPA's billing
     * pin, and every scheduled command / job).
     */
    public function isExemptFromAccessRules(): bool
    {
        return $this->hasAnyRole([self::ADMIN_ROLE, self::PROMO_ROLE]);
    }

    /**
     * Onboarding's paywall: a card-backed plan (active or trialing) is needed
     * to leave the wizard. The free window alone isn't enough for a self
     * signup; it is the trial that subscription starts with (see
     * subscriptionTrialTerms). Only an admin-created promotional customer
     * skips the card step.
     */
    public function canCompleteOnboarding(): bool
    {
        return $this->hasActivePlan() || $this->isPromotional();
    }

    /**
     * Days a new signup can use the product before a card is required. Set as
     * `promo_expires_at` at registration — the same free window an admin
     * grants a promotional customer, just without the role.
     */
    public const CARD_FREE_DAYS = 7;

    /** The card-free window set at signup (promo_expires_at) hasn't closed yet. Null = no window. */
    public function freeWindowOpen(): bool
    {
        return ! is_null($this->promo_expires_at) && $this->promo_expires_at->isFuture();
    }

    /**
     * Free access without a card: the signup window is still open, or the
     * user is a promotional customer (whose window never closes).
     */
    public function hasActivePromo(): bool
    {
        return $this->isPromotional() || $this->freeWindowOpen();
    }

    /**
     * No active/trialing plan and no free access — the user must subscribe.
     * EnsureAccessActive locks these users to the billing endpoints, the SPA
     * pins them to the Billing page, and background work skips them (see
     * scopeWithAccess). A user with no window at all (pre-paywall signups,
     * admin-created accounts) is expired unless they hold a plan or an
     * exempt role; only admins and promotional customers are never expired.
     */
    public function accessExpired(): bool
    {
        return ! $this->isExemptFromAccessRules()
            && ! $this->hasActivePlan()
            && ! $this->freeWindowOpen();
    }

    /**
     * Users UserAccess::denial() would allow, as a query — for commands and
     * jobs that pick records in bulk (publish-due, syncs, metrics) so lapsed
     * users cost nothing. Mirrors denial(): verified, and either an exempt
     * role, or (a live plan or an open window) plus finished onboarding (or
     * a live plan). Soft-deleted users are excluded by the model's scope.
     */
    public function scopeWithAccess(Builder $query): Builder
    {
        $livePlan = fn (Builder $q) => $q->whereIn('user_plans.status', self::ACTIVE_SUBSCRIPTION_STATUSES);

        return $query->whereNotNull('email_verified_at')->where(function (Builder $q) use ($livePlan) {
            $q->whereHas('roles', fn (Builder $r) => $r->whereIn('name', [self::ADMIN_ROLE, self::PROMO_ROLE]))
                ->orWhere(function (Builder $q) use ($livePlan) {
                    $q->where(fn (Builder $q) => $q->whereHas('plans', $livePlan)->orWhere('promo_expires_at', '>', now()))
                        ->where(fn (Builder $q) => $q->whereNotNull('onboarding_completed_at')->orWhereHas('plans', $livePlan));
                });
        });
    }

    /**
     * Stripe trial terms for a new subscription. The free window is the only
     * trial: a card added while it is open isn't charged until it closes, and
     * one added at any other time (window closed, or no window at all) is
     * charged straight away. There is no separate Stripe trial period.
     *
     * @return array{trial_end?: int}
     */
    public function subscriptionTrialTerms(): array
    {
        return $this->freeWindowOpen()
            ? ['trial_end' => $this->promo_expires_at->getTimestamp()]
            : [];
    }

    /**
     * The connections (youtube/tiktok/instagram) that belong to the user.
     */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /**
     * The user's posts (multi-platform composer).
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * The user's brands (named groups of connected accounts).
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * The feature request votes cast by the user.
     */
    public function featureRequestVotes(): HasMany
    {
        return $this->hasMany(FeatureRequestVote::class);
    }

    /**
     * Canonical user object returned by every endpoint that emits a `user`.
     * Keeps the new SPA-required fields alongside the legacy extras the app
     * already returns, so existing consumers keep working.
     */
    public function apiPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'onboarding_completed_at' => optional($this->onboarding_completed_at)->toIso8601String(),
            'connections_count' => $this->connections()->count(),
            'has_active_subscription' => $this->hasActiveSubscription(),
            // Promotional access: a card-backed plan vs a free window, and
            // whether that window has closed without a plan replacing it.
            'has_active_plan' => $this->hasActivePlan(),
            'promo_expires_at' => optional($this->promo_expires_at)->toIso8601String(),
            'access_expired' => $this->accessExpired(),
            'is_admin' => $this->isAdmin(),
            // Promotional customer: with admins, the limited-release audience
            // (SocialProviderManager::inLimitedReleaseAudience).
            'is_promotional' => $this->isPromotional(),
            // Legacy extras retained for backward compatibility.
            'roles' => $this->roles,
            'active_plan' => $this->activePlan(),
            'created_at' => $this->created_at,
            'public_id' => $this->public_id,
        ];
    }

    /**
     * Create a single-use, 24h email verification token. Stores the sha256
     * hash so the raw token is never persisted; returns the raw token.
     */
    public function createEmailVerificationToken(): string
    {
        $raw = Str::random(64);

        DB::table('email_verification_tokens')->insert([
            'user_id' => $this->id,
            'token' => hash('sha256', $raw),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $raw;
    }

    /**
     * Check if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(self::ADMIN_ROLE);
    }

    /**
     * Check if the user is a customer.
     */
    public function isCustomer(): bool
    {
        return $this->hasRole('customer');
    }

    /**
     * Create a password reset token for the user.
     */
    public function createPasswordResetToken(): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $this->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        return $token;
    }

    /**
     * Verify a password reset token.
     */
    public function verifyPasswordResetToken(string $token): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $this->email)
            ->first();

        if (! $record) {
            return false;
        }

        // Check if token is expired (60 minutes)
        if (\Illuminate\Support\Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            $this->deletePasswordResetToken();

            return false;
        }

        return Hash::check($token, $record->token);
    }

    /**
     * Delete the password reset token.
     */
    public function deletePasswordResetToken(): void
    {
        DB::table('password_reset_tokens')
            ->where('email', $this->email)
            ->delete();
    }

    /**
     * Reset the user's password.
     */
    public function resetPassword(string $newPassword): bool
    {
        $this->password = Hash::make($newPassword);
        $this->save();

        $this->deletePasswordResetToken();

        return true;
    }

    /**
     * Get the offers (tracked landing pages) for the user.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function beehiivConnection(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BeehiivConnection::class);
    }

    /**
     * Get the content posts for the user.
     */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /**
     * Get the generated images for the user.
     */
    public function generatedImages(): HasMany
    {
        return $this->hasMany(GeneratedImage::class);
    }

    /**
     * Check if the user has a default reference image set.
     */
    public function hasDefaultReferenceImage(): bool
    {
        return ! empty($this->default_reference_image_path);
    }

    /**
     * Get the default reference image URL.
     */
    public function getDefaultReferenceImageUrlAttribute(): ?string
    {
        if (! $this->default_reference_image_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->default_reference_image_path);
    }
}
