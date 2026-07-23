<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A promotion/campaign being tracked: it owns tracking links, clicks and
 * conversions, and carries `offer_url`, `conversion_url` and `conversion_value`.
 *
 * Naming note — "Offer" is the model concept only. It is backed by the
 * `tracking_events` table (see $table below), and foreign keys on the related
 * tables are still named `tracking_event_id`. Expect that mismatch when writing
 * queries or migrations against anything that references an Offer.
 */
/**
 * A promotion/campaign being tracked: it owns tracking links, clicks and
 * conversions, and carries `offer_url`, `conversion_url` and `conversion_value`.
 *
 * Naming note — "Offer" is the model concept only. It is backed by the
 * `tracking_events` table (see $table below), and foreign keys on the related
 * tables are still named `tracking_event_id`. Expect that mismatch when writing
 * queries or migrations against anything that references an Offer.
 */
class Offer extends Model
{
    use SoftDeletes;

    const TYPE_CONVERSION = 'conversion';
    const TYPE_CALL_BOOKED = 'call booked';
    const TYPE_VIEW = 'view';
    const TYPE_CLICK = 'click';
    const TYPE_SALE = 'sale';
    const TYPE_EMAIL_SIGNUP = 'email-signup';
    const TYPE_NEWSLETTER = 'newsletter';
    const TYPE_TRIAL = 'trial';
    // Built-in goal types. event_type is a free-text column: anything outside this
    // list is treated as a user-defined "custom" event and stored verbatim.
    const BUILTIN_GOAL_TYPES = [
        self::TYPE_CONVERSION,
        self::TYPE_CALL_BOOKED,
        self::TYPE_EMAIL_SIGNUP,
        self::TYPE_NEWSLETTER,
        self::TYPE_TRIAL,
    ];

    // The underlying table keeps its original name; only the model concept is "Offer".
    protected $table = 'tracking_events';

    protected $fillable = [
        'user_id',
        'name',
        'offer_url',
        'conversion_value',
    ];

    // Backward-compat: the column was renamed landing_page_url -> offer_url, but
    // the SPA still reads/writes `landing_page_url`. Expose it as a mirror so the
    // old name keeps working on both input (fillable) and output (appended JSON).
    protected $appends = ['landing_page_url'];

    public function getLandingPageUrlAttribute(): ?string
    {
        return $this->offer_url;
    }

    public function setLandingPageUrlAttribute($value): void
    {
        $this->attributes['offer_url'] = $value;
    }

    // FK stays tracking_event_id (the table/column names are unchanged), so the
    // hasMany relations must name it explicitly — Laravel would otherwise infer
    // offer_id from the renamed model.
    public function links()
    {
        return $this->hasMany(TrackingLink::class, 'tracking_event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function goals()
    {
        return $this->hasMany(TrackingGoal::class, 'tracking_event_id');
    }

    public function conversions()
    {
        return $this->hasMany(TrackingConversion::class, 'tracking_event_id');
    }

    public function contents()
    {
        return $this->hasMany(Content::class, 'offer_id');
    }
}
