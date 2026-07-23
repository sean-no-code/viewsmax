<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    use HasApiTokens, HasFactory, HasWallet, Notifiable;

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
    ];

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
     * (PayPal or Stripe). Drives the onboarding gate and user payload.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->hasActivePlan();
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
        if (now()->diffInMinutes($record->created_at) > 60) {
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
