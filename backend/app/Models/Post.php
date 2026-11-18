<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Post extends Model
{
    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_POSTED = 'posted';

    protected $fillable = [
        'user_id',
        'brand_id',
        'caption',
        'media',
        'status',
        'scheduled_at',
        'shorten_links',
        'failure_notified_at',
    ];

    protected $casts = [
        'media' => 'array',
        'scheduled_at' => 'datetime',
        'shorten_links' => 'boolean',
        'failure_notified_at' => 'datetime',
    ];

    /**
     * Count every post that goes out on its owner (users.posts_published_count),
     * whichever path published it — "post now" or posts:publish-due. The count
     * outlives the post, which is what App\Support\PostAllowance needs.
     */
    protected static function booted(): void
    {
        static::created(function (Post $post) {
            if ($post->status === self::STATUS_POSTED) {
                $post->countAsPublished();
            }
        });

        static::updated(function (Post $post) {
            if ($post->status === self::STATUS_POSTED && $post->wasChanged('status')) {
                $post->countAsPublished();
            }
        });
    }

    private function countAsPublished(): void
    {
        User::whereKey($this->user_id)->increment('posts_published_count');
    }

    /**
     * Atomically move a due scheduled post to posted, for posts:publish-due.
     * One conditional UPDATE: it changes the row only if the post is still
     * scheduled and still due, so a post the user published with "Post now",
     * rescheduled or moved to drafts after the command loaded it is left
     * alone (returns false) instead of being overwritten and dispatched.
     * On success the model is refreshed from the row.
     */
    public function claimForPublishing(): bool
    {
        $claimed = static::whereKey($this->getKey())
            ->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->update(['status' => self::STATUS_POSTED]);

        if ($claimed === 0) {
            return false;
        }

        // A query-builder update fires no model events, so count it here.
        $this->countAsPublished();
        $this->refresh();

        return true;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The brand selected in the composer — informational, never read at publish time. */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /** Composed comments, posted to each supporting target after it publishes. */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class)->orderBy('position');
    }

    /** Every failure email sent or skipped for this post, newest first. */
    public function failureNotifications(): HasMany
    {
        return $this->hasMany(PostFailureNotification::class)->latest('id');
    }

    /**
     * Take a post off the schedule because its owner can't use ViewsMax right
     * now (UserAccess::denial). It isn't a failure — nothing was attempted —
     * so no error, no failure email: the post waits in drafts, unscheduled,
     * and the user re-schedules it once their access is back.
     */
    public function revertToDraft(string $reason): void
    {
        $this->update(['status' => self::STATUS_DRAFT, 'scheduled_at' => null]);
        $this->targets()
            ->whereIn('status', [PostTarget::STATUS_PENDING, PostTarget::STATUS_PUBLISHING])
            ->update(['status' => PostTarget::STATUS_PENDING, 'error' => null]);

        Log::info('Post returned to draft: owner has no access', [
            'post_id' => $this->id,
            'user_id' => $this->user_id,
            'reason' => $reason,
        ]);
    }
}
