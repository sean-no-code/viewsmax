<?php

namespace App\Services\Social;

use App\Models\SocialHandleClaim;
use App\Models\User;

/**
 * A social account belongs to the ViewsMax user who connected it first.
 *
 * Every connect path records (platform, platform account id, handle, user)
 * here and the row is never deleted — not on disconnect, not by "Delete my
 * data" — so the handle is remembered after the tokens and channel rows are
 * gone. A different ViewsMax user connecting the same account is refused and
 * told to sign in with the original account: a second signup can't re-use a
 * channel that already has an account here. Matching is on the platform's own
 * account id (stable when the handle is renamed); the handle is kept for the
 * message and the records.
 */
final class HandleClaims
{
    /**
     * @throws HandleClaimedException when another user owns the account
     */
    public static function claim(User $user, string $platform, ?string $platformAccountId, ?string $handle = null): void
    {
        $platformAccountId = trim((string) $platformAccountId);
        if ($platformAccountId === '') {
            return;
        }

        $existing = SocialHandleClaim::where('platform', $platform)
            ->where('platform_account_id', $platformAccountId)
            ->first();

        if ($existing && $existing->user_id !== $user->id) {
            throw new HandleClaimedException(self::message($platform, $handle ?: $existing->handle, $existing->user));
        }

        SocialHandleClaim::updateOrCreate(
            ['platform' => $platform, 'platform_account_id' => $platformAccountId],
            [
                'user_id' => $user->id,
                'handle' => $handle ?: $existing?->handle,
                'first_connected_at' => $existing?->first_connected_at ?? now(),
                'last_connected_at' => now(),
            ],
        );
    }

    public static function message(string $platform, ?string $handle, ?User $owner): string
    {
        $label = CaptionRules::PLATFORM_LABELS[$platform] ?? ucfirst($platform);
        $account = $handle ? " ({$handle})" : '';
        $signIn = $owner?->email ? ' ('.self::maskEmail($owner->email).')' : '';

        return "This {$label} account{$account} is already connected to another ViewsMax account. "
            ."Sign in with that account{$signIn} to use it.";
    }

    /** s***@example.com — enough to recognise the account, not enough to learn it. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***'.($domain !== '' ? '@'.$domain : '');
    }
}
