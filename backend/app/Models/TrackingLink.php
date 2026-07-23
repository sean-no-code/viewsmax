<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingLink extends Model
{
    const PLACEMENT_VIDEO = 'video';
    const PLACEMENT_EMAIL = 'email';
    const PLACEMENT_X = 'x';
    const PLACEMENT_LINKEDIN = 'linkedin';
    const PLACEMENT_PODCAST = 'podcast';
    const PLACEMENT_BLOG = 'blog';
    const PLACEMENT_WEBSITE = 'website';
    const PLACEMENT_TIKTOK = 'tiktok';
    const PLACEMENT_AD = 'ad';
    const PLACEMENT_INSTAGRAM = 'instagram';
    const PLACEMENT_BEEHIIV = 'beehiiv';
    const PLACEMENT_OTHER = 'other';

    /** Every valid placement key — the multi-select's allow-list. */
    const PLACEMENTS = [
        self::PLACEMENT_VIDEO,
        self::PLACEMENT_EMAIL,
        self::PLACEMENT_X,
        self::PLACEMENT_LINKEDIN,
        self::PLACEMENT_PODCAST,
        self::PLACEMENT_BLOG,
        self::PLACEMENT_WEBSITE,
        self::PLACEMENT_TIKTOK,
        self::PLACEMENT_AD,
        self::PLACEMENT_INSTAGRAM,
        self::PLACEMENT_BEEHIIV,
        self::PLACEMENT_OTHER,
        // Newer social platforms the post composer supports.
        'facebook',
        'threads',
        'bluesky',
    ];

    protected $fillable = [
        'tracking_event_id',
        'video_id',
        'youtube_video_id',
        'beehiiv_post_id',
        'x_post_id',
        'instagram_media_id',
        'placement',
        'placements',
        'name',
        'content_title',
        'parameter_id',
        'initial_view_count',
        'current_view_count',
        'reach_synced_at',
        'description',
    ];

    protected $casts = [
        // Declared platforms for a multi-platform link; `placement` mirrors
        // the first entry for back-compat.
        'placements' => 'array',
    ];

    /**
     * Stable content-source key for reach de-duplication: multiple links to the
     * SAME piece of content (YouTube video or Beehiiv post) share one key so the
     * content's views are counted once. Null when the link has no reach source.
     */
    public function reachKey(): ?string
    {
        if (! empty($this->youtube_video_id)) {
            return 'yt:'.$this->youtube_video_id;
        }
        if (! empty($this->beehiiv_post_id)) {
            return 'bh:'.$this->beehiiv_post_id;
        }
        if (! empty($this->instagram_media_id)) {
            return 'ig:'.$this->instagram_media_id;
        }

        return null;
    }

    public function event()
    {
        return $this->belongsTo(Offer::class, 'tracking_event_id');
    }

    public function video()
    {
        return $this->belongsTo(Video::class, 'youtube_video_id', 'youtube_video_id');
    }
}
