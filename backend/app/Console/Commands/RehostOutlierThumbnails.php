<?php

namespace App\Console\Commands;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Services\CaptApiOutlierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill: copy TikTok/Instagram video thumbnails and channel avatars that
 * still point at the provider CDN onto our media disk (new ingests do this
 * inline). Rows whose CDN URL has already expired are left as they are — a
 * later refresh-media / re-ingest re-fetches them.
 */
class RehostOutlierThumbnails extends Command
{
    protected $signature = 'outliers:rehost-thumbnails
        {--platform= : tiktok or instagram (default both)}
        {--limit=500 : Max rows to process this run}';

    protected $description = 'Copy TikTok/Instagram outlier thumbnails and channel avatars from the provider CDN onto the media disk';

    public function handle(CaptApiOutlierService $service): int
    {
        if (! config('services.outliers.rehost_thumbnails')) {
            $this->warn('OUTLIER_REHOST_THUMBNAILS is off — nothing to do.');

            return self::SUCCESS;
        }

        $platforms = $this->option('platform') ? [strtolower((string) $this->option('platform'))] : ['tiktok', 'instagram'];

        $rows = OutlierVideo::whereIn('platform', $platforms)
            ->whereNotNull('thumbnail_url')
            ->where('thumbnail_url', 'not like', '%/outliers/thumbs/%')
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $done = 0;
        $skipped = 0;
        foreach ($rows as $video) {
            if ($service->isHostedUrl($video->thumbnail_url)) {
                continue;
            }
            $hosted = $service->rehostThumbnail($video->platform, $video->youtube_video_id, $video->thumbnail_url);
            if ($hosted === null) {
                $skipped++;
                continue;
            }
            $video->forceFill(['thumbnail_url' => $hosted, 'thumbnail_medium_url' => $hosted])->save();
            $done++;
        }

        $channels = OutlierChannel::whereIn('platform', $platforms)
            ->whereNotNull('profile_image_url')
            ->where('profile_image_url', 'not like', '%/outliers/avatars/%')
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $avatarsDone = 0;
        $avatarsSkipped = 0;
        foreach ($channels as $channel) {
            if ($service->isHostedUrl($channel->profile_image_url)) {
                continue;
            }
            $hosted = $service->rehostAvatar($channel->platform, $channel->youtube_channel_id, $channel->profile_image_url);
            if ($hosted === null) {
                $avatarsSkipped++;
                continue;
            }
            $channel->forceFill(['profile_image_url' => $hosted])->save();
            $avatarsDone++;
        }

        Log::info('outliers:rehost-thumbnails ran', [
            'rehosted' => $done, 'skipped' => $skipped, 'scanned' => $rows->count(),
            'avatars_rehosted' => $avatarsDone, 'avatars_skipped' => $avatarsSkipped, 'channels_scanned' => $channels->count(),
        ]);
        $this->info("Re-hosted {$done} thumbnail(s), skipped {$skipped} (expired or not an image), scanned {$rows->count()}.");
        $this->info("Re-hosted {$avatarsDone} avatar(s), skipped {$avatarsSkipped}, scanned {$channels->count()} channel(s).");

        return self::SUCCESS;
    }
}
