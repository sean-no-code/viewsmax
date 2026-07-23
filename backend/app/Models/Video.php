<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    protected $fillable = [
        'youtube_video_id',
        'channel_id',
        'youtube_channel_id',
        'title',
        'description',
        'thumbnail_url',
        'thumbnail_medium_url',
        'thumbnail_high_url',
        'published_at',
        'view_count',
        'like_count',
        'comment_count',
        'duration',
        'definition',
        'has_captions',
        'outlier_score',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'view_count' => 'integer',
        'like_count' => 'integer',
        'comment_count' => 'integer',
        'has_captions' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'formatted_duration',
        'duration_in_seconds',
        'formatted_published_at',
    ];

    /**
     * The titles that belong to the video.
     */
    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class, 'video_title')
            ->withTimestamps()
            ->orderBy('titles.created_at', 'desc');
    }

    /**
     * The scripts that belong to the video.
     */
    public function scripts(): BelongsToMany
    {
        return $this->belongsToMany(Script::class, 'scripts_videos')
            ->withTimestamps()
            ->orderBy('scripts.created_at', 'desc');
    }

    /**
     * The transcriptions for this video.
     */
    public function transcriptions(): HasMany
    {
        return $this->hasMany(VideoTranscription::class);
    }

    /**
     * The title score for this video.
     */
    public function titleScore(): HasOne
    {
        return $this->hasOne(TitleScore::class);
    }

    /**
     * The thumbnail score for this video.
     */
    public function thumbnailScore(): HasOne
    {
        return $this->hasOne(ThumbnailScore::class);
    }

    /**
     * Get the channel that owns the video.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Get formatted duration (e.g., "5:30" from "PT5M30S", "0:19" from "PT19S")
     * 
     * @return string|null Formatted duration or null
     */
    public function getFormattedDurationAttribute(): ?string
    {
        if (!$this->duration) {
            return null;
        }

        // Parse ISO 8601 duration (e.g., "PT5M30S" = 5 minutes 30 seconds, "PT19S" = 19 seconds)
        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $this->duration, $matches);

        $hours = isset($matches[1]) ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;

        // Format: H:MM:SS for videos with hours, M:SS for others
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        // Always show minutes, even if 0 (e.g., "0:19" for 19 seconds)
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Get duration in seconds
     * 
     * @return int|null Duration in seconds or null
     */
    public function getDurationInSecondsAttribute(): ?int
    {
        if (!$this->duration) {
            return null;
        }

        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $this->duration, $matches);

        $hours = isset($matches[1]) ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    /**
     * Get formatted published date (e.g., "Nov 12, 2025" or "2 days ago")
     * 
     * @return string|null Formatted date or null
     */
    public function getFormattedPublishedAtAttribute(): ?string
    {
        if (!$this->published_at) {
            return null;
        }

        $publishedDate = $this->published_at;
        $now = now();

        // Use absolute difference to handle timezone differences
        $diffSeconds = abs($now->diffInSeconds($publishedDate));
        $diffMinutes = floor($diffSeconds / 60);
        $diffHours = floor($diffSeconds / 3600);
        $diffDays = floor($diffSeconds / 86400);

        // Show relative time for recent dates
        if ($diffMinutes < 1) {
            return 'Just now';
        }

        if ($diffMinutes < 60) {
            return $diffMinutes === 1 ? '1 minute ago' : "{$diffMinutes} minutes ago";
        }

        if ($diffHours < 24) {
            return $diffHours === 1 ? '1 hour ago' : "{$diffHours} hours ago";
        }

        if ($diffDays === 1) {
            return '1 day ago';
        }

        if ($diffDays < 7) {
            return "{$diffDays} days ago";
        }

        if ($diffDays < 30) {
            $weeks = floor($diffDays / 7);
            return $weeks === 1 ? '1 week ago' : "{$weeks} weeks ago";
        }

        if ($diffDays < 365) {
            $months = floor($diffDays / 30);
            return $months === 1 ? '1 month ago' : "{$months} months ago";
        }

        // For older dates, show formatted date (e.g., "Nov 12, 2025")
        return $publishedDate->format('M j, Y');
    }
}
