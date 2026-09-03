<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A channel the user tracks as a competitor, picked from the outlier DB. */
class OutlierCompetitorChannel extends Model
{
    protected $connection = 'outlier_db';

    protected $fillable = ['user_id', 'channel_id'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(OutlierChannel::class, 'channel_id');
    }
}
