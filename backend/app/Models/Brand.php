<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of connected accounts. Members come from both account stores
 * (social_accounts + legacy connections) through the brand_accounts pivot;
 * each pivot row sets exactly one of the two member columns.
 */
class Brand extends Model
{
    protected $fillable = [
        'user_id',
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'brand_accounts')->withTimestamps();
    }

    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class, 'brand_accounts')->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
