<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-account Boost configuration: Auto Repost / Auto Promo with a like
 * threshold (and the promo text for Auto Promo). X-only in v1.
 */
class BoostSetting extends Model
{
    public const FEATURE_AUTO_REPOST = 'auto_repost';
    public const FEATURE_AUTO_PROMO = 'auto_promo';

    public const FEATURES = [self::FEATURE_AUTO_REPOST, self::FEATURE_AUTO_PROMO];

    protected $fillable = [
        'user_id',
        'social_account_id',
        'feature',
        'enabled',
        'likes_threshold',
        'promo_text',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'likes_threshold' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
