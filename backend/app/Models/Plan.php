<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'price',
        'currency',
        'billing_cycle',
        'features',
        'is_active',
        'max_channels',
        'max_offers',
        'max_posts_per_month',
        'max_automations',
        'stripe_price_id',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'max_channels' => 'integer',
        'max_offers' => 'integer',
        'max_posts_per_month' => 'integer',
        'max_automations' => 'integer',
    ];

    /**
     * Whether this plan permits unlimited offers (null limit = unlimited).
     */
    public function hasUnlimitedOffers(): bool
    {
        return $this->max_offers === null;
    }

    /**
     * The users that belong to the plan.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_plans')
                    ->withPivot(['stripe_subscription_id', 'stripe_price_id', 'status', 'starts_at', 'expires_at', 'cancelled_at'])
                    ->withTimestamps();
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public const FREE_PLAN = 'free';
    public static function getDefaultPlan()
    {
        return config('services.stripe.default_plan', 'starter');
    }
}
