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
        'youtube_channel_id',
        'channel_name',
        'profile_image_url',
        'subscriber_count',
        'video_count',
        'average_views',
        'average_calculated_at',
        'average_video_ids'
    ];

    protected $casts = [
        'average_calculated_at' => 'datetime',
        'average_video_ids' => 'array',
    ];

    public function videos()
    {
        return $this->hasMany(OutlierVideo::class);
    }
}
