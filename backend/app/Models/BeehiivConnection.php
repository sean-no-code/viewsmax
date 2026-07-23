<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeehiivConnection extends Model
{
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'api_key',
        'publication_id',
        'publication_name',
        'status',
        'last_error',
        'last_validated_at',
    ];

    protected $casts = [
        // Encrypt the API key at rest — Laravel decrypts transparently on read.
        'api_key' => 'encrypted',
        'last_validated_at' => 'datetime',
    ];

    // Never expose the raw key in serialized output.
    protected $hidden = [
        'api_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A non-sensitive hint (last 4 chars) so the user recognises which key is stored. */
    public function keyHint(): ?string
    {
        $key = $this->api_key;

        return $key ? '••••'.substr($key, -4) : null;
    }
}
