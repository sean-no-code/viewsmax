<?php

namespace ViewsMax\SeoEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoKeyword extends Model
{
    public const STATUS_DISCOVERED = 'discovered';

    public const STATUS_DRAFTED = 'drafted';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = [];

    public function articles(): HasMany
    {
        return $this->hasMany(SeoArticle::class);
    }

    public function profile()
    {
        return $this->belongsTo(SeoProfile::class, 'seo_profile_id');
    }
}
