<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of MCP tool calls: who ran what tool with which arguments, and
 * whether it errored. Append-only — rows are never updated.
 */
class McpToolInvocation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'tool',
        'arguments',
        'is_error',
        'auth_mode',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'is_error' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
