<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = [
        'user_id',
        'youtube_channel_id',
        'channel_name',
        'channel_description',
        'subscriber_count',
        'video_count',
        'view_count',
        'custom_url',
        'country',
        'published_at',
        'profile_image_url',
        'youtube_access_token',
        'youtube_refresh_token',
        'youtube_token_expires_at',
        'oauth_scopes',
        'playlists',
        'views_over_time',
        'audience_demographics',
        'watch_time_analytics',
        'analytics_eligible',
        'analytics_reason',
        'analytics_reason',
        'analytics_last_updated',
        'average_views',
        'average_video_ids',
        'average_calculated_at'
    ];

    protected $casts = [
        'subscriber_count' => 'integer',
        'video_count' => 'integer',
        'view_count' => 'integer',
        'published_at' => 'datetime',
        'youtube_token_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'playlists' => 'array',
        'views_over_time' => 'array',
        'audience_demographics' => 'array',
        'watch_time_analytics' => 'array',
        'analytics_eligible' => 'boolean',
        'analytics_last_updated' => 'datetime',
        'average_views' => 'integer',
        'average_video_ids' => 'array',
        'average_calculated_at' => 'datetime'
    ];

    /**
     * Get the user that owns the channel.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the videos for the channel.
     */
    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}

