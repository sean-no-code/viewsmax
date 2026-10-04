<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialAccount;

/**
 * Providers whose API lets a connected account follow another account. Only
 * used for the "Follow us" box on the connect page (see FollowUs).
 */
interface SupportsFollowing
{
    /**
     * Make $account follow $handle. Throws when the platform refuses.
     */
    public function follow(SocialAccount $account, string $handle): void;
}
