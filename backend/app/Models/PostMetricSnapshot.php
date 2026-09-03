<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily engagement snapshot for one published post, keyed on
 * (platform, remote_post_id) so it doesn't FK into the two rival post stores.
 * The posts:refresh-metrics command upserts it once per day.
 */
class PostMetricSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'social_account_id',
        'platform',
        'remote_post_id',
        'url',
        'caption_excerpt',
        'published_at',
        'likes',
        'comments',
        'shares',
        'views',
        'engagement_total',
        'snapshot_date',
    ];

    // snapshot_date stays a plain 'Y-m-d' string (not a datetime cast) so exact
    // matches (updateOrCreate) and whereBetween behave the same on SQLite/Postgres.
    protected $casts = [
        'published_at' => 'datetime',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'views' => 'integer',
        'engagement_total' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
