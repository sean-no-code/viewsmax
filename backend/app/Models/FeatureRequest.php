<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'category',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(FeatureRequestVote::class);
    }

    /**
     * The shape the SPA expects. Pass the authenticated user id to compute has_upvoted.
     * Relies on the `upvotes_count` aggregate being loaded via withCount.
     */
    public function toApiArray(?int $userId = null): array
    {
        $upvotes = $this->upvotes_count ?? $this->votes()->count();
        $hasUpvoted = $userId
            ? ($this->relationLoaded('votes')
                ? $this->votes->contains('user_id', $userId)
                : $this->votes()->where('user_id', $userId)->exists())
            : false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'upvotes_count' => (int) $upvotes,
            'has_upvoted' => $hasUpvoted,
            'status' => $this->status,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
