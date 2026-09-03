<?php

namespace ViewsMax\SeoEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoArticle extends Model
{
    /** The content archetypes every article falls into. */
    public const CATEGORIES = [
        'Guide: Explainer',
        'Guide: How-to',
        'List: Round-up',
        'List: Resources',
        'List: Examples',
    ];

    public const STATUS_REVIEW = 'review';   // drafted, awaiting manual approval

    public const STATUS_QUEUED = 'queued';   // approved (or auto), awaiting publish

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime'];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKeyword::class, 'seo_keyword_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SeoProfile::class, 'seo_profile_id');
    }
}
