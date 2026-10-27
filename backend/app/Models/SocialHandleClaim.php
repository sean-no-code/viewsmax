<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which ViewsMax user owns a social account (platform + platform account id).
 * Survives disconnects and "Delete my data" — see HandleClaims.
 */
class SocialHandleClaim extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'platform_account_id',
        'handle',
        'first_connected_at',
        'last_connected_at',
    ];

    protected $casts = [
        'first_connected_at' => 'datetime',
        'last_connected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
