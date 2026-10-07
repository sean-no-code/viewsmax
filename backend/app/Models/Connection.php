<?php

namespace App\Models;

use App\Models\Concerns\SoftDeletesConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Connection extends Model
{
    use HasFactory, SoftDeletesConnection;

    /** Credentials wiped on disconnect (access_token is NOT NULL, so it becomes empty). */
    protected array $clearedOnDisconnect = ['access_token' => '', 'refresh_token' => null];

    protected static function accountIdentity(array $row): array
    {
        return [$row['user_id'] ?? null, $row['provider'], $row['account_id'] ?? null];
    }

    protected static function booted(): void
    {
        // A hard delete removed the connection from its brands via the
        // brand_accounts FK cascade; a soft delete doesn't, so do it here.
        static::softDeleted(function (Connection $connection) {
            DB::table('brand_accounts')->where('connection_id', $connection->id)->delete();
        });
    }

    protected $fillable = [
        'user_id',
        'provider',
        'account_name',
        'account_id',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the stored access token is missing or about to expire. A 60s skew
     * avoids racing an expiry between this check and the outbound API call.
     */
    public function tokenIsExpired(): bool
    {
        if (! $this->access_token) {
            return true;
        }

        return $this->token_expires_at !== null
            && $this->token_expires_at->subSeconds(60)->isPast();
    }

    /**
     * The shape the SPA expects for a connection.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'account_name' => $this->account_name,
            'account_id' => $this->account_id,
            'avatar_url' => $this->avatar_url,
            'connected_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
