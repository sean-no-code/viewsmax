<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingVisitor extends Model
{
    protected $fillable = [
        'visitor_id',
        'ip_address',
        'user_agent',
        'first_touch_click_id',
    ];

    public function clicks()
    {
        return $this->hasMany(TrackingClick::class);
    }

    public function conversions()
    {
        return $this->hasMany(TrackingConversion::class);
    }
}
