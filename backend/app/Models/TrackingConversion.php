<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingConversion extends Model
{
    protected $fillable = [
        'tracking_visitor_id',
        'tracking_event_id',
        'tracking_click_id',
        'tracking_link_id',
        'first_touch_click_id',
        'event_type',
        'value',
    ];

    public function visitor()
    {
        return $this->belongsTo(TrackingVisitor::class, 'tracking_visitor_id');
    }

    public function event()
    {
        return $this->belongsTo(Offer::class, 'tracking_event_id');
    }

    /** The last-touch click credited with this conversion. */
    public function click()
    {
        return $this->belongsTo(TrackingClick::class, 'tracking_click_id');
    }

    /** The last-touch link (traffic source) credited with this conversion. */
    public function link()
    {
        return $this->belongsTo(TrackingLink::class, 'tracking_link_id');
    }
}
