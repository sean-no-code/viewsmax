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
        'channel_id',
        'youtube_video_id',
        'title',
        'description',
        'thumbnail_url',
        'thumbnail_medium_url',
        'views',
        'outlier_score',
        'duration',
        'published_at'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'views' => 'integer',
        'outlier_score' => 'float',
    ];

    protected $appends = [
        'duration_in_seconds',
        'formatted_duration',
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

    public function channel()
    {
        return $this->belongsTo(OutlierChannel::class, 'channel_id');
    }
}
