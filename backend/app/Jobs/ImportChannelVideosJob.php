<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\YouTubeChannelService;
use App\Support\AnalyticsAccess;
use App\Support\Quota\Actor;
use App\Support\YouTubeChannelToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Imports a just-connected channel's uploads so the "add a video" pickers have
 * them. Queued from the YouTube OAuth exchange, which used to wait on the
 * YouTube API (and a write per video) before responding. Carries only the
 * channel id so no OAuth token sits in the queue payload; the token is read,
 * and refreshed if needed, when the job runs.
 */
class ImportChannelVideosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    // search.list costs 100 quota units a call, so retry once, not three times.
    public $tries = 2;

    public $backoff = 60;

    public function __construct(public int $channelId) {}

    public function handle(YouTubeChannelService $youtube): void
    {
        $channel = Channel::find($this->channelId);
        if (! $channel?->youtube_channel_id) {
            return;
        }

        Actor::set($channel->user_id);

        // Analytics is the Pro tier upsell, and a queued job can outlive a
        // downgrade: re-check the owner's plan before spending a single call.
        if (! AnalyticsAccess::allows($channel->user)) {
            AnalyticsAccess::logSkip('channel video import', [
                'channel_id' => $channel->id,
                'user_id' => $channel->user_id,
            ]);

            return;
        }

        $accessToken = YouTubeChannelToken::for($channel, $youtube);
        if ($accessToken === null) {
            return;
        }

        $youtube->fetchAndImportChannelVideos($channel, $accessToken);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Failed to import channel videos after connect', [
            'channel_id' => $this->channelId,
            'error' => $e->getMessage(),
        ]);
    }
}
