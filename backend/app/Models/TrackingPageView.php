<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page load on a tracked site (tracker.js beacon). The visitor spine is
 * shared with clicks/conversions, so acquisition and attribution join up.
 */
class TrackingPageView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'tracking_event_id',
        'tracking_visitor_id',
        'url',
        'path',
        'referrer',
        'inferred_platform',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(TrackingVisitor::class, 'tracking_visitor_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'tracking_event_id');
    }
}
