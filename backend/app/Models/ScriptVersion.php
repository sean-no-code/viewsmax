<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScriptVersion extends Model
{
    protected $fillable = [
        'script_id',
        'user_id',
        'content',
        'content_hash',
    ];

    /**
     * Get the content attribute.
     * Returns clean string.
     * Decodes Base64 first, then Uncompresses.
     * 
     * @param string $value
     * @return string
     */
    public function getContentAttribute($value)
    {
        if (empty($value)) return '';

        // If it's valid UTF-8 and looks like plain text, return it
        // This handles if plain text were stored
        if (mb_check_encoding($value, 'UTF-8') && strpos($value, 'x') !== 0 && !preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $value)) {
             return $value;
        }

        try {
            $decoded = base64_decode($value, true);
            if ($decoded === false) return $value; // Failed base64 decode, assume plain text
            
            $uncompressed = @gzuncompress($decoded);
            return $uncompressed !== false ? $uncompressed : $value;
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Set the content attribute.
     * Compresses then Base64 encodes to store safely in TEXT column.
     *
     * @param string $value
     * @return void
     */
    public function setContentAttribute($value)
    {
        // Gzip level 9 for max compression
        // Base64 encode to ensure it's safe for Postgres TEXT column
        $compressed = base64_encode(gzcompress($value, 9));
        
        $this->attributes['content'] = $compressed;
        $this->attributes['content_hash'] = hash('sha256', $value);
    }

    public function script()
    {
        return $this->belongsTo(Script::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
