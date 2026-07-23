<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingGoal extends Model
{
    protected $fillable = [
        'tracking_event_id',
        'event_type',
        'conversion_url',
        'conversion_value',
    ];

    public function event()
    {
        return $this->belongsTo(Offer::class, 'tracking_event_id');
    }
}
