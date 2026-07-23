<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SearchTerm extends Model
{
    use HasFactory;

    protected $connection = 'outlier_db';
    protected $fillable = ['term'];

    public function requests()
    {
        return $this->hasMany(SearchTermsRequest::class, 'term_id');
    }

    public function termsDataFetch()
    {
        return $this->hasOne(TermsDataFetch::class, 'term_id');
    }
}
