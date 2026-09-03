<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a user first starts a subscription (card added). Drives the Kit
 * "converted" tag and removal of the abandoned-cart tag (see SyncKitOnSubscription).
 */
class SubscriptionStarted
{
    use Dispatchable;

    public function __construct(public User $user)
    {
    }
}
