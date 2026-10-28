<?php

namespace App\Models;

use App\Support\Quota\Keys;
use Illuminate\Database\Eloquent\Model;

/**
 * One YouTube API call: the quota ledger behind the admin dashboard. user_id
 * is set only when a signed-in request made the call; queued work has no one
 * to attribute to.
 */
class YouTubeQuotaSpend extends Model
{
    protected $table = 'youtube_quota_spends';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'spent_at' => 'datetime',
        'units' => 'integer',
        'status' => 'integer',
        'user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // The Pacific day the spend counts towards, fixed at write time so the
        // dashboard groups days the way Google's quota does.
        static::creating(function (self $spend) {
            $spend->spent_at ??= now();
            $spend->pt_date ??= Keys::date($spend->spent_at);
        });
    }
}
