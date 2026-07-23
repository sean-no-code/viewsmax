<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchTermsRequest extends Model
{
    use HasFactory;

    protected $connection = 'outlier_db';
    protected $fillable = ['user_id', 'term_id'];

    const UPDATED_AT = null;

    public function term()
    {
        return $this->belongsTo(SearchTerm::class, 'term_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
