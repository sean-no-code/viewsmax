<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TermsDataFetch extends Model
{
    use HasFactory;
    
    protected $connection = 'outlier_db';

    protected $table = 'terms_data_fetch';

    protected $fillable = ['term_id', 'fetched_at', 'status'];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    public $timestamps = false;

    public function term()
    {
        return $this->belongsTo(SearchTerm::class, 'term_id');
    }
}
