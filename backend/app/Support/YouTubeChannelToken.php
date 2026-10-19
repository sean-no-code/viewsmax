<?php

namespace App\Support;

use App\Models\Channel;
use App\Services\YouTubeChannelService;
use Illuminate\Support\Facades\Log;

/**
 * A usable OAuth access token for a connected channel, for work that runs off
 * the request (the nightly sync, the post-connect import), where the stored
 * token may have expired since it was saved.
 */
class YouTubeChannelToken
{
    /**
     * The stored token, or a refreshed one when it has expired and a refresh
     * token exists. Null when the channel can't be refreshed (expired with no
     * refresh token), so the caller skips it.
     */
    public static function for(Channel $channel, YouTubeChannelService $youtube): ?string
    {
        $expired = $channel->youtube_token_expires_at && $channel->youtube_token_expires_at->isPast();

        if (! $expired) {
            return $channel->youtube_access_token ?: null;
        }

        if (! $channel->youtube_refresh_token) {
            Log::warning('Skipping channel video import: token expired, no refresh token', [
                'channel_id' => $channel->id,
            ]);

            return null;
        }

        $tokenData = $youtube->refreshAccessToken($channel->youtube_refresh_token);

        $channel->update([
            'youtube_access_token' => $tokenData['access_token'],
            'youtube_token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
        ]);

        return $tokenData['access_token'];
    }
}
