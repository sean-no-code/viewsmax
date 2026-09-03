<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutlierVideo extends Model
{
    use HasFactory;

    const DURATION_TYPE_LONG = 'long';
    const DURATION_TYPE_SHORTS = 'shorts';
    const SHORTS_MAX_SECONDS = 180;

    protected $connection = 'outlier_db';
    protected $table = 'videos';
    
    protected $fillable = [
        'platform',
        'channel_id',
        'youtube_video_id',
        'title',
        'description',
        'thumbnail_url',
        'thumbnail_medium_url',
        'views',
        'like_count',
        'comment_count',
        'outlier_score',
        'duration',
        'published_at',
        'manually_added',
        'featured',
        'video_url',
        'video_url_expires_at',
        'is_short',
        'format_checked_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'views' => 'integer',
        'like_count' => 'integer',
        'comment_count' => 'integer',
        'outlier_score' => 'float',
        'manually_added' => 'boolean',
        'featured' => 'boolean',
        'video_url_expires_at' => 'datetime',
        'is_short' => 'boolean',
        'format_checked_at' => 'datetime',
    ];

    /** Has the Shorts/long-form question been answered for this row? */
    public function isClassified(): bool
    {
        return $this->is_short !== null;
    }

    /** TikTok and Instagram are short-form by definition — no probe needed. */
    public static function platformIsAlwaysShort(string $platform): bool
    {
        return in_array($platform, ['tiktok', 'instagram'], true);
    }

    protected $appends = [
        'duration_in_seconds',
        'formatted_duration',
        'engagement_rate',
    ];

    public static function minScore(): float
    {
        return (float) config('services.youtube.min_outlier_score', 20);
    }

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

    public function getFormattedDurationAttribute(): ?string
    {
        if (!$this->duration) {
            return null;
        }

        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $this->duration, $matches);

        $hours = isset($matches[1]) ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * (likes + comments) / views, as a percentage. Null when views are unknown
     * (Instagram) or no interaction data was captured.
     */
    public function getEngagementRateAttribute(): ?float
    {
        if (! $this->views || $this->views <= 0) {
            return null;
        }
        if ($this->like_count === null && $this->comment_count === null) {
            return null;
        }
        $interactions = (int) ($this->like_count ?? 0) + (int) ($this->comment_count ?? 0);

        return round($interactions / $this->views * 100, 1);
    }

    public function channel()
    {
        return $this->belongsTo(OutlierChannel::class, 'channel_id');
    }
}
