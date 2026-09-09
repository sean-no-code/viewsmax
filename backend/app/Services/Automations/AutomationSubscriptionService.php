<?php

namespace App\Services\Automations;

use App\Models\SocialAccount;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\Social\SocialProviderManager;
use RuntimeException;

/**
 * Keeps an Instagram account subscribed to the comments + messages webhook
 * fields. Meta keeps subscriptions until the token is revoked, but a
 * reconnect (new token) or a long gap can drop them, so we re-subscribe on
 * start, after reconnect and from a daily command when the record is stale.
 */
class AutomationSubscriptionService
{
    public const FIELDS = ['comments', 'messages'];

    /** Re-subscribe when the last confirmation is older than this. */
    public const STALE_DAYS = 30;

    public function __construct(protected SocialProviderManager $manager) {}

    public function isFresh(SocialAccount $account): bool
    {
        return $account->webhook_subscribed_at !== null
            && $account->webhook_subscribed_at->gt(now()->subDays(self::STALE_DAYS));
    }

    /**
     * @throws RuntimeException with a user-facing message when Meta refuses.
     */
    public function ensureSubscribed(SocialAccount $account, bool $force = false): void
    {
        if (! $force && $this->isFresh($account)) {
            return;
        }

        /** @var InstagramProvider $provider */
        $provider = $this->manager->for($account->platform);

        if (! $provider->subscribeWebhooks($account, self::FIELDS)) {
            throw new RuntimeException(
                'Instagram refused the webhook subscription for this account. '
                .($account->fresh()?->status === SocialAccount::STATUS_NEEDS_REAUTH
                    ? 'Reconnect the account and try again.'
                    : 'Check the app has the comments + messages permissions, then try again.')
            );
        }
    }
}
