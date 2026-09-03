<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transcript extends Model
{
    protected $connection = 'outlier_db';

    protected $fillable = [
        'platform',
        'source_url',
        'url_hash',
        'text',
        'segments',
        'language',
        'provider_fetched_at',
        'request_id',
        'credits_used',
    ];

    protected $casts = [
        'segments' => 'array',
        'provider_fetched_at' => 'datetime',
        'credits_used' => 'integer',
    ];
}
