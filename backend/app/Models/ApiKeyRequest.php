<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of REST calls made with a vmx_ API key: who called which
 * endpoint with what input, the HTTP status, and the response body.
 * Append-only — rows are never updated.
 */
class ApiKeyRequest extends Model
{
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
