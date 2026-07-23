<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScriptResearch extends Model
{
    use HasFactory;

    protected $table = 'script_researches';

    protected $fillable = [
        'script_id',
        'body',
        'references',
        'status',
    ];

    protected $casts = [
        'references' => 'array',
    ];

    public function script()
    {
        return $this->belongsTo(Script::class);
    }
}
