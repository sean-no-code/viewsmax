<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutlierChannel extends Model
{
    use HasFactory;

    protected $connection = 'outlier_db';
    protected $table = 'channels';
    
    protected $fillable = [
        'platform',
        'youtube_channel_id',
        'handle',
        'channel_name',
        'profile_image_url',
        'subscriber_count',
        'video_count',
        'country',
        'average_views',
        'average_calculated_at',
        'average_video_ids',
        'last_ingested_at',
    ];

    protected $casts = [
        'average_calculated_at' => 'datetime',
        'average_video_ids' => 'array',
        'last_ingested_at' => 'datetime',
    ];

    /** Lowercase handle without "@" — the shape stored in `handle` and used for lookups. */
    public static function normalizeHandle(?string $handle): ?string
    {
        $handle = strtolower(trim(ltrim((string) $handle, '@')));

        return $handle === '' ? null : $handle;
    }

    public function videos()
    {
        return $this->hasMany(OutlierVideo::class);
    }
}
