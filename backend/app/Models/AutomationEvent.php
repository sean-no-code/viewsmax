<?php

namespace App\Models;

use App\Services\Automations\Data\InboundEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inbound webhook ledger. UNIQUE(platform, event_id) makes Meta redeliveries
 * a no-op before they reach the queue, and `ignored` + ignore_reason is the
 * support trail for "why didn't my automation fire?".
 */
class AutomationEvent extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'platform',
        'account_platform_id',
        'event_type',
        'event_id',
        'sender_id',
        'payload',
        'status',
        'ignore_reason',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function toInboundEvent(): InboundEvent
    {
        return InboundEvent::fromArray($this->payload ?? []);
    }

    public function markIgnored(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_IGNORED,
            'ignore_reason' => $reason,
            'processed_at' => now(),
        ])->save();
    }

    public function markMatched(): void
    {
        $this->forceFill([
            'status' => self::STATUS_MATCHED,
            'ignore_reason' => null,
            'processed_at' => now(),
        ])->save();
    }
}
