<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tracked /l/{slug} redirect minted from a URL in a post caption.
 */
class ShortLink extends Model
{
    protected $fillable = [
        'user_id',
        'post_id',
        'slug',
        'destination_url',
        'tracking_link_id',
        'clicks_count',
        'last_clicked_at',
    ];

    protected $casts = [
        'clicks_count' => 'integer',
        'last_clicked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(ShortLinkClick::class);
    }

    /** The auto-minted offer tracking link, when the destination is an offer. */
    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class);
    }

    public function shortUrl(): string
    {
        return rtrim(config('services.shortlinks.base_url') ?: config('app.url'), '/').'/l/'.$this->slug;
    }
}
