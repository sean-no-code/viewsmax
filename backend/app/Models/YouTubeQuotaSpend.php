<?php

namespace App\Models;

use App\Support\Quota\Keys;
use Illuminate\Database\Eloquent\Model;

/**
 * One YouTube API call: the quota ledger behind the admin dashboard. user_id
 * is whose quota the call spent (App\Support\Quota\Actor): the signed-in
 * requester, or for queued and scheduled work the owner of the channel,
 * account or post it ran for. Null only for calls nobody owns (admin
 * backfills, the public API-key fallback). source is the unit of work that
 * made the call (App\Support\Quota\Source: job:…, cmd:…, mcp:…, api:…) and
 * target what it asked for (playlist=UU…, id=… +49, q=…), so a run of rows
 * reads as one search's per-channel fan-out rather than as repeats.
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
