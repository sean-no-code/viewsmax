<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A predefined conversion goal/event type offered in the Analytics
 * "Conversion events" UI. `value` is the event_type stored on tracking_goals;
 * `label` is its human display name. The list is a seeded reference table —
 * anything outside it is still allowed as a free-text "custom" event.
 */
class GoalType extends Model
{
    protected $fillable = [
        'value',
        'label',
        'sort_order',
    ];
}
