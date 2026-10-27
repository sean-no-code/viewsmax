<?php

namespace App\Services\Social;

use App\Models\User;

/**
 * Disconnecting YouTube means revoking the Google grant and deleting every
 * store that holds it. The legacy connect flow (YouTubeOAuthController) writes
 * one grant into four places — the user's own token columns, the channel row
 * (analytics / nightly sync, videos cascade), the legacy connection and the
 * social account that publishing reads — so removing one of them while the
 * grant lives on left the rest half-connected, and revoking the grant without
 * removing the rest left rows that could only fail. Every disconnect path
 * (Connections page, Analytics, Settings, the MCP tool, delete-my-data) comes
 * through here.
 */
final class YouTubeDisconnector
{
    /**
     * @param  string|null  $youtubeChannelId  one channel, or null for every YouTube connection the user has
     * @return array{revoked: int, social_accounts: int, channels: int, connections: int}
     */
    public static function disconnect(User $user, ?string $youtubeChannelId = null): array
    {
        $accounts = $user->socialAccounts()->where('platform', 'youtube')
            ->when($youtubeChannelId, fn ($q) => $q->where('platform_account_id', $youtubeChannelId))
            ->get();
        $channels = $user->channels()
            ->when($youtubeChannelId, fn ($q) => $q->where('youtube_channel_id', $youtubeChannelId))
            ->get();
        $connections = $user->connections()->where('provider', 'youtube')
            ->when($youtubeChannelId, fn ($q) => $q->where('account_id', $youtubeChannelId))
            ->get();

        // One grant is mirrored across the stores, so revoke each distinct token once.
        $grants = [];
        foreach ($accounts as $a) {
            $grants[] = [$a->refresh_token, $a->access_token, ['social_account_id' => $a->id]];
        }
        foreach ($channels as $c) {
            $grants[] = [$c->youtube_refresh_token, $c->youtube_access_token, ['channel_id' => $c->id]];
        }
        foreach ($connections as $c) {
            $grants[] = [$c->refresh_token, $c->access_token, ['connection_id' => $c->id]];
        }
        $clearUserTokens = $youtubeChannelId === null || $user->channels()->whereKeyNot($channels->modelKeys())->doesntExist();
        if ($clearUserTokens) {
            $grants[] = [$user->youtube_refresh_token, $user->youtube_access_token, ['user_id' => $user->id]];
        }

        $revoked = 0;
        $seen = [];
        foreach ($grants as [$refresh, $access, $context]) {
            $key = $refresh ?: $access;
            if (! $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $revoked += GoogleGrantRevoker::revoke($refresh, $access, ['user_id' => $user->id] + $context) ? 1 : 0;
        }

        $accounts->each->delete();
        $channels->each->delete();
        $connections->each->delete();
        if ($clearUserTokens) {
            $user->forceFill([
                'youtube_access_token' => null,
                'youtube_refresh_token' => null,
                'youtube_token_expires_at' => null,
            ])->save();
        }

        return [
            'revoked' => $revoked,
            'social_accounts' => $accounts->count(),
            'channels' => $channels->count(),
            'connections' => $connections->count(),
        ];
    }
}
