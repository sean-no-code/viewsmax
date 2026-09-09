<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One fired automation: who triggered it, what we sent back, and whether the
 * tracked link was clicked. UNIQUE(automation_id, event_id) makes webhook
 * redeliveries a no-op at the job level.
 */
class AutomationRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const REPLY_SENT = 'sent';
    public const REPLY_FAILED = 'failed';
    public const REPLY_SKIPPED = 'skipped';

    public const DM_SENT = 'sent';
    public const DM_FAILED = 'failed';
    public const DM_SKIPPED = 'skipped';

    public const ERROR_NEEDS_REAUTH = 'needs_reauth';
    public const ERROR_OUTSIDE_WINDOW = 'outside_24h_window';
    public const ERROR_STOPPED = 'automation_stopped';

    protected $fillable = [
        'automation_id',
        'user_id',
        'automation_event_id',
        'trigger_type',
        'event_id',
        'sender_id',
        'sender_username',
        'media_id',
        'inbound_text',
        'matched_keyword',
        'status',
        'reply_status',
        'reply_remote_id',
        'dm_status',
        'dm_remote_id',
        'clicked_at',
        'error',
        'executed_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AutomationEvent::class, 'automation_event_id');
    }

    public function shortLinks(): HasMany
    {
        return $this->hasMany(ShortLink::class);
    }

    public function wasClicked(): bool
    {
        return $this->clicked_at !== null;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
