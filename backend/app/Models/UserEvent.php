<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product event that no other table records, timestamped per user.
 * Append-only: rows are never updated.
 */
class UserEvent extends Model
{
    public const UPDATED_AT = null;

    public const OUTLIER_BREAKDOWN_VIEWED = 'outlier_breakdown.viewed';

    protected $fillable = ['user_id', 'event_name', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(User $user, string $eventName, array $metadata = []): self
    {
        return static::create([
            'user_id' => $user->id,
            'event_name' => $eventName,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
