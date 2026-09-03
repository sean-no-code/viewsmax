<?php

namespace ViewsMax\SeoEngine\Models;

use Illuminate\Database\Eloquent\Model;

class SeoBacklinkProspect extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_WON = 'won';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    protected $casts = ['dofollow' => 'boolean'];

    public function profile()
    {
        return $this->belongsTo(SeoProfile::class, 'seo_profile_id');
    }
}
