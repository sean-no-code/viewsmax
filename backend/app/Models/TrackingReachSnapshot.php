<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingReachSnapshot extends Model
{
    protected $fillable = [
        'tracking_link_id',
        'snapshot_date',
        'view_count',
    ];

    // snapshot_date is kept as a plain 'Y-m-d' string (not a datetime cast) so
    // exact matches and whereBetween on the date column behave consistently
    // across SQLite/Postgres.
    protected $casts = [
        'view_count' => 'integer',
    ];

    public function link()
    {
        return $this->belongsTo(TrackingLink::class, 'tracking_link_id');
    }
}
