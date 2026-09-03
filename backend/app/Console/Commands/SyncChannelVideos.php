<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\YouTubeChannelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-imports each connected YouTube channel's latest uploads so the "add a
 * video" pickers (offers, tracking links, monetization) surface videos
 * published AFTER the account was connected. Without this the `videos` table is
 * only filled at connect-time or via the Review page's manual refresh, so new
 * uploads never appear in those pickers.
 *
 * Runs daily: getAllChannelVideos uses search.list (100 YouTube quota units per
 * call), so a tighter cadence would exhaust the shared daily quota. Users who
 * need a just-published video immediately use the picker's "Refresh from
 * YouTube" button (POST /channels/{id}/fetch-videos).
 */
class SyncChannelVideos extends Command
{
    protected $signature = 'channels:sync-videos {--max=50 : Most-recent videos to import per channel}';

    protected $description = 'Import each connected channel\'s latest YouTube uploads so pickers show recent videos.';

    public function handle(YouTubeChannelService $youtube): int
    {
        $channels = Channel::whereNotNull('youtube_channel_id')
            ->where(function ($q) {
                $q->whereNotNull('youtube_access_token')
                    ->orWhereNotNull('youtube_refresh_token');
            })
            ->get();

        $maxTotal = (int) $this->option('max');
        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($channels as $channel) {
            try {
                $accessToken = $this->accessTokenFor($channel, $youtube);

                if ($accessToken === null) {
                    $skipped++;

                    continue;
                }

                $youtube->fetchAndImportChannelVideos($channel, $accessToken, $maxTotal);
                $synced++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Channel video sync failed', [
                    'channel_id' => $channel->id,
                    'youtube_channel_id' => $channel->youtube_channel_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Channels: {$channels->count()}, synced {$synced}, skipped {$skipped}, failed {$failed}.");

        // Loudly diagnosable: only a total wipeout is a failure. Partial
        // failures are logged per-channel and don't fail the scheduled run.
        return ($failed > 0 && $synced === 0 && $skipped === 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A usable access token for the channel, refreshing an expired one when a
     * refresh token exists. Returns null when the channel can't be refreshed
     * (expired with no refresh token) so the caller skips it.
     */
    private function accessTokenFor(Channel $channel, YouTubeChannelService $youtube): ?string
    {
        $expired = $channel->youtube_token_expires_at && $channel->youtube_token_expires_at->isPast();

        if (! $expired) {
            return $channel->youtube_access_token ?: null;
        }

        if (! $channel->youtube_refresh_token) {
            Log::warning('Skipping channel video sync: token expired, no refresh token', [
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
