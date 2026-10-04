<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\Social\Contracts\SupportsFollowing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Follow us" on the connect page: when the user leaves the box ticked, a
 * newly connected account follows the ViewsMax account for that platform
 * (config social.follow_us). Best effort — a follow that fails is logged to
 * the follow_us channel and never fails the connection.
 */
class FollowUs
{
    public function __construct(protected SocialProviderManager $manager) {}

    /**
     * Our handle on $platform, or null when there is nothing to follow (not
     * configured, or the platform's API can't follow). Accepts a handle, an
     * @handle or a profile link in config.
     */
    public function handle(string $platform): ?string
    {
        $value = trim((string) config("social.follow_us.{$platform}"));
        $handle = ltrim((string) last(explode('/', rtrim($value, '/'))), '@');

        if ($handle === '' || ! $this->manager->for($platform) instanceof SupportsFollowing) {
            return null;
        }

        return $handle;
    }

    /**
     * Follow our account from each account connected for the first time. A
     * reconnect is skipped: the user may have unfollowed since, and Bluesky
     * would store a duplicate follow.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     */
    public function followFrom(Collection $accounts): void
    {
        foreach ($accounts->filter->wasRecentlyCreated as $account) {
            $handle = $this->handle($account->platform);
            if ($handle === null) {
                continue;
            }

            $context = [
                'platform' => $account->platform,
                'social_account_id' => $account->id,
                'user_id' => $account->user_id,
                'handle' => $handle,
            ];

            try {
                $this->manager->for($account->platform)->follow($account, $handle);
                Log::channel('follow_us')->info('Followed', $context);
            } catch (Throwable $e) {
                Log::channel('follow_us')->warning('Follow failed', $context + ['error' => $e->getMessage()]);
            }
        }
    }
}
