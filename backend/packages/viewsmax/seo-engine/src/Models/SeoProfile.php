<?php

namespace ViewsMax\SeoEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's SEO configuration for ONE offer: which competitors to mine, where
 * their WordPress blog lives, and how aggressively to publish.
 */
class SeoProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'competitors' => 'array',
        'wp_app_password' => 'encrypted',
        'auto_publish' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    /** The offer this profile promotes (host app's Offer = tracking_events). */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(config('seo-engine.offer_model', \App\Models\Offer::class), 'tracking_event_id');
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(SeoKeyword::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(SeoArticle::class);
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(SeoBacklinkProspect::class);
    }

    /** Ready to run: switched on and has the pieces the pipeline needs. */
    public function runnable(): bool
    {
        return $this->enabled && ! empty($this->competitors);
    }
}
