<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingClick extends Model
{
    public $timestamps = false; 

    protected $fillable = [
        'tracking_visitor_id',
        'tracking_link_id',
        'referrer',
        'landing_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'inferred_platform',
        'created_at',
    ];

    public function visitor()
    {
        return $this->belongsTo(TrackingVisitor::class, 'tracking_visitor_id');
    }

    public function link()
    {
        return $this->belongsTo(TrackingLink::class, 'tracking_link_id');
    }
}
