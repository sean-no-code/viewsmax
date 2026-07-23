<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class PostTarget extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    /**
     * Guarantee that every publish failure is surfaced to the error log — no
     * matter which platform job or code path set it — so an admin is always
     * made aware a post failed. This is the single choke point every target
     * passes through on its way to FAILED, which closes the gaps where a job
     * marked a target failed without logging (e.g. the X result-failure path).
     */
    protected static function booted(): void
    {
        static::updated(function (PostTarget $target) {
            if ($target->status === self::STATUS_FAILED && $target->wasChanged('status')) {
                Log::error('Post publish failed', [
                    'post_target_id' => $target->id,
                    'post_id' => $target->post_id,
                    'platform' => $target->platform,
                    'error' => $target->error,
                ]);

                \App\Services\Social\PostFailureNotifier::onTargetFailed($target);
            }

            // The published transition is the trigger for everything that
            // follows a live post: composed comments and Boosts checks.
            if ($target->status === self::STATUS_PUBLISHED && $target->wasChanged('status')) {
                \App\Services\Social\CommentDispatcher::onTargetPublished($target);
                \App\Services\Social\BoostScheduler::onTargetPublished($target);
            }
        });
    }

    protected $fillable = [
        'post_id',
        'platform',
        'social_account_id',
        'caption_override',
        'status',
        'platform_post_id',
        'error',
        'published_at',
        'options',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'options' => 'array',
            'meta' => 'array',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * The exact account this target publishes through. Null on legacy targets
     * (which fall back to the user's newest connected account of the platform).
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Targets orphaned by a worker outage: still pending/publishing on a POSTED
     * post and untouched for at least $minutes (so genuinely in-flight jobs are
     * left alone). Shared by the reconcile command and the admin monitor.
     */
    public function scopeStuck($query, int $minutes = 15)
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PUBLISHING])
            ->where('updated_at', '<=', now()->subMinutes(max(0, $minutes)))
            ->whereHas('post', fn ($q) => $q->where('status', Post::STATUS_POSTED));
    }
}
