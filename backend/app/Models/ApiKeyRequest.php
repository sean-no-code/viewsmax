<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of REST calls made with a vmx_ API key: who called which
 * endpoint with what input, the HTTP status, and the response body.
 * Append-only — rows are never updated. Kept 30 days (MassPrunable, pruned
 * daily by the scheduler's model:prune).
 */
class ApiKeyRequest extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'method',
        'path',
        'status',
        'request',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'status' => 'integer',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
