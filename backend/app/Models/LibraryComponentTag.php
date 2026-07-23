<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LibraryComponentTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function components()
    {
        return $this->belongsToMany(LibraryComponent::class, 'library_component_tag', 'library_component_tag_id', 'library_component_id');
    }
}
