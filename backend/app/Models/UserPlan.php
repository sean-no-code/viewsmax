<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPlan extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'status',
        'starts_at',
        'expires_at',
        'cancelled_at',
        'trial_reminder_sent_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'trial_reminder_sent_at' => 'datetime',
    ];

    /**
     * Get the user that owns the user plan.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the plan that owns the user plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Scope a query to live subscriptions (active or trialing) — the same
     * definition as User::ACTIVE_SUBSCRIPTION_STATUSES.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES);
    }
}
