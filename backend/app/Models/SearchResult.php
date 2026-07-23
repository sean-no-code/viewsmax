<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchResult extends Model
{
    use HasFactory;

    protected $connection = 'outlier_db';
    
    protected $fillable = ['term_id', 'video_youtube_id'];

    public function term()
    {
        return $this->belongsTo(SearchTerm::class, 'term_id');
    }
}
