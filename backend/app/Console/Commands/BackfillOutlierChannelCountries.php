<?php

namespace App\Console\Commands;

use App\Models\OutlierChannel;
use App\Services\YouTubeChannelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off backfill for the outliers countries filter: fetch snippet.country for
 * YouTube outlier channels that don't have one yet. Idempotent — only touches
 * NULL-country rows; channels YouTube reports no country for simply stay NULL.
 * TikTok/Instagram channels are skipped (CaptAPI exposes no region data).
 */
class BackfillOutlierChannelCountries extends Command
{
    protected $signature = 'outliers:backfill-channel-countries
        {--limit=2000 : Max channels to look up this run (batched 50 per API call)}';

    protected $description = 'Fill in country (ISO 3166-1 alpha-2) for YouTube outlier channels missing one.';

    public function handle(YouTubeChannelService $channelService): int
    {
        $limit = max(0, (int) $this->option('limit'));

        $channels = OutlierChannel::where('platform', 'youtube')
            ->whereNull('country')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'youtube_channel_id']);

        if ($channels->isEmpty()) {
            $this->info('No YouTube outlier channels missing a country.');

            return self::SUCCESS;
        }

        $channelsMap = $channelService->getChannelsBatch($channels->pluck('youtube_channel_id')->all());

        $updated = 0;
        foreach ($channels as $channel) {
            $country = $channelsMap[$channel->youtube_channel_id]['snippet']['country'] ?? null;
            if (is_string($country) && $country !== '') {
                OutlierChannel::where('id', $channel->id)->update(['country' => strtoupper($country)]);
                $updated++;
            }
        }

        $summary = "Looked up {$channels->count()} channels, set country on {$updated} (rest publish none).";
        $this->info($summary);
        Log::info('outliers:backfill-channel-countries ran', ['looked_up' => $channels->count(), 'updated' => $updated]);

        return self::SUCCESS;
    }
}
