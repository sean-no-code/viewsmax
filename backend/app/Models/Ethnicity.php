<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ethnicity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * Get the AI models for this ethnicity.
     */
    public function aiModels(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }
}
