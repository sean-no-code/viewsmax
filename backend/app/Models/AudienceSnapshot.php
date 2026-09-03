<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily follower/subscriber count for one connected social account. One row per
 * (social_account_id, snapshot_date); the audience:refresh command upserts it.
 */
class AudienceSnapshot extends Model
{
    protected $fillable = [
        'social_account_id',
        'snapshot_date',
        'follower_count',
    ];

    // snapshot_date stays a plain 'Y-m-d' string (not a datetime cast) so exact
    // matches (updateOrCreate) and whereBetween behave the same on SQLite/Postgres.
    protected $casts = [
        'follower_count' => 'integer',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
