<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\YouTubeChannelService;
use App\Support\YouTubeChannelToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-imports each connected YouTube channel's latest uploads so the "add a
 * video" pickers (offers, tracking links, monetization) surface videos
 * published AFTER the account was connected. Without this the `videos` table is
 * only filled at connect-time or via the Review page's manual refresh, so new
 * uploads never appear in those pickers.
 *
 * Runs daily. getAllChannelVideos reads the channel's uploads playlist
 * (playlistItems.list, 1 quota unit a page), so the whole run costs a few
 * units per channel. It must never move to search.list: that is 100 units a
 * call and capped at 100 calls a day project-wide, the outlier search's
 * budget. Users who need a just-published video immediately use the picker's
 * "Refresh from YouTube" button (POST /channels/{id}/fetch-videos).
 */
class SyncChannelVideos extends Command
{
    protected $signature = 'channels:sync-videos {--max=50 : Most-recent videos to import per channel}';

    protected $description = 'Import each connected channel\'s latest YouTube uploads so pickers show recent videos.';

    public function handle(YouTubeChannelService $youtube): int
    {
        // Only channels of users who may use ViewsMax right now: a lapsed trial
        // or cancelled plan must not keep spending YouTube quota every day.
        $channels = Channel::whereNotNull('youtube_channel_id')
            ->where(function ($q) {
                $q->whereNotNull('youtube_access_token')
                    ->orWhereNotNull('youtube_refresh_token');
            })
            ->whereHas('user', fn ($u) => $u->withAccess())
            ->get();

        $maxTotal = (int) $this->option('max');
        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($channels as $channel) {
            try {
                $accessToken = YouTubeChannelToken::for($channel, $youtube);

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
}
