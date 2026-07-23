<?php

namespace App\Jobs\Concerns;

use App\Models\PostTarget;
use App\Models\SocialAccount;

/**
 * Shared account resolution for the per-platform publish jobs.
 *
 * A target pinned to an account (social_account_id) always publishes through
 * that exact account — if it's gone or moved, the target fails loudly instead
 * of silently posting through a sibling account. Legacy targets (no pin) keep
 * the historical behavior: the user's newest connected account.
 */
trait ResolvesTargetAccount
{
    private const PLATFORM_LABELS = [
        'x' => 'X',
        'linkedin' => 'LinkedIn',
        'threads' => 'Threads',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'bluesky' => 'Bluesky',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
    ];

    /**
     * @return array{0: SocialAccount|null, 1: string|null} [account, error]
     */
    protected function resolveTargetAccount(PostTarget $target, string $platform): array
    {
        $label = self::PLATFORM_LABELS[$platform] ?? ucfirst($platform);

        if ($target->social_account_id) {
            $account = SocialAccount::find($target->social_account_id);

            if (! $account
                || $account->user_id !== $target->post?->user_id
                || $account->platform !== $platform) {
                return [null, "{$label} account disconnected — reconnect it and retry."];
            }

            return [$account, null];
        }

        $account = $target->post?->user?->socialAccounts()
            ->where('platform', $platform)
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->latest()
            ->first();

        return $account
            ? [$account, null]
            : [null, "No {$label} account connected."];
    }
}
