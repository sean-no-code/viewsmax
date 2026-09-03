<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'platform',
        'platform_account_id',
        'name',
        'username',
        'avatar_url',
        'profile_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'metadata',
        'status',
        'last_error',
        'last_synced_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'scopes' => 'array',
        'metadata' => 'array',
        // Encrypt OAuth secrets at rest. Laravel transparently encrypts on
        // write and decrypts on read, so providers always see plain tokens.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    /**
     * Never leak raw tokens in API responses.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function postTargets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    /**
     * Whether the stored access token is present and not past its expiry.
     * Platforms with long-lived / non-expiring tokens leave token_expires_at null.
     */
    public function hasValidToken(): bool
    {
        if (empty($this->access_token)) {
            return false;
        }

        if ($this->token_expires_at === null) {
            return true;
        }

        return $this->token_expires_at->isFuture();
    }

    /**
     * Whether this account can still publish WITHOUT user action. An expired
     * access token is normal (X's live 2 hours) — publishing refreshes it
     * silently. Only a missing refresh path or an explicit needs_reauth means
     * the user genuinely has to reconnect. This is what the UI's "Reconnect"
     * state must key off — never raw access-token expiry.
     */
    public function hasUsableCredentials(): bool
    {
        if ($this->status !== self::STATUS_CONNECTED || empty($this->access_token)) {
            return false;
        }

        return $this->hasValidToken() || ! empty($this->refresh_token);
    }

    /**
     * Read a value out of the metadata bag.
     */
    public function meta(string $key, $default = null)
    {
        return data_get($this->metadata, $key, $default);
    }

    public function markNeedsReauth(?string $error = null): void
    {
        $this->update([
            'status' => self::STATUS_NEEDS_REAUTH,
            'last_error' => $error,
        ]);
    }
}
