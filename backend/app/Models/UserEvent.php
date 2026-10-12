<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A product event that no other table records, timestamped per user.
 * Append-only: rows are never updated.
 *
 * Only what a signed-in person does in the web app is recorded. The model
 * enforces it on create, so every event type gets the rule without its
 * caller checking: the current request must carry a web app login token
 * (Sanctum, without the mcp abilities API keys have) belonging to the
 * event's user. MCP tool calls, API keys, queue jobs and console commands
 * record nothing, even when they run the same controller code.
 */
class UserEvent extends Model
{
    public const UPDATED_AT = null;

    public const OUTLIER_BREAKDOWN_VIEWED = 'outlier_breakdown.viewed';

    protected $fillable = ['user_id', 'event_name', 'metadata', 'created_at'];

    protected static function booted(): void
    {
        // Returning false cancels the insert.
        static::creating(fn (self $event) => self::isWebAppRequestBy($event->user_id));
    }

    private static function isWebAppRequestBy(mixed $userId): bool
    {
        $user = request()->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken
            && ! array_intersect(['mcp', 'mcp:read', 'mcp:write'], $token->abilities ?? [])
            && (string) $user->getKey() === (string) $userId;
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** The stored event, or null when this isn't a web app request by $user. */
    public static function record(User $user, string $eventName, array $metadata = []): ?self
    {
        $event = static::create([
            'user_id' => $user->id,
            'event_name' => $eventName,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);

        return $event->exists ? $event : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
