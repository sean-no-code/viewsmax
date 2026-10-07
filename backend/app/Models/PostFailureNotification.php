<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * The record of a publish-failure email: one row per recipient per failure
 * episode, whether it was sent, skipped (with the reason) or failed. Answers
 * "was the customer / admin told about this failure?" without searching an
 * inbox. Each row is mirrored to the `post_failures` log channel. Append-only.
 */
class PostFailureNotification extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_ADMIN = 'admin';

    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const REASON_OPTED_OUT = 'opted_out';
    public const REASON_RECOVERED = 'recovered';
    public const REASON_NO_ADMIN_ADDRESS = 'no_admin_address';

    protected $fillable = [
        'post_id', 'user_id', 'recipient_type', 'recipient_email', 'status', 'reason', 'failures', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'failures' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Write the row and the matching log line together so the two can't drift.
     *
     * @param  array<int, array{platform: string, social_account_id: int|null, error: string|null}>  $failures
     */
    public static function record(
        Post $post,
        string $recipientType,
        ?string $recipientEmail,
        string $status,
        ?string $reason = null,
        array $failures = [],
    ): self {
        $row = static::create([
            'post_id' => $post->id,
            'user_id' => $post->user_id,
            'recipient_type' => $recipientType,
            'recipient_email' => $recipientEmail,
            'status' => $status,
            'reason' => $reason,
            'failures' => $failures ?: null,
            'created_at' => now(),
        ]);

        Log::channel('post_failures')->{$status === self::STATUS_FAILED ? 'error' : 'info'}('Failure notification', [
            'post_id' => $post->id,
            'user_id' => $post->user_id,
            'recipient' => $recipientType,
            'email' => $recipientEmail,
            'status' => $status,
            'reason' => $reason,
            'platforms' => array_column($failures, 'platform'),
        ]);

        return $row;
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
