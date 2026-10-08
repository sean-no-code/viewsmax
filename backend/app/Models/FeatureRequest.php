<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureRequest extends Model
{
    use HasFactory;

    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IMPLEMENTED = 'implemented';
    public const STATUSES = [self::STATUS_IN_REVIEW, self::STATUS_APPROVED, self::STATUS_IMPLEMENTED];

    /** Statuses everyone can see; in_review requests are only visible to their author and admins. */
    public const PUBLIC_STATUSES = [self::STATUS_APPROVED, self::STATUS_IMPLEMENTED];

    protected $attributes = [
        'status' => self::STATUS_IN_REVIEW,
    ];

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

    /** Requests the user may see: public ones plus their own (admins see all). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->orWhere('user_id', $user->id));
    }

    /**
     * The shape the SPA expects. Pass the authenticated user id to compute has_upvoted
     * and is_mine; `requested_by` is only included for admins (pass $forAdmin).
     * Relies on the `upvotes_count` aggregate being loaded via withCount.
     */
    public function toApiArray(?int $userId = null, bool $forAdmin = false): array
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
            'is_mine' => $userId !== null && (int) $this->user_id === $userId,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ] + ($forAdmin ? ['requested_by' => $this->user?->name] : []);
    }
}
