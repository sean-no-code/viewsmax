<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use App\Models\Video;
use App\Services\BeehiivService;
use App\Services\YouTubeChannelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refresh the "current" view count for the YouTube content backing active
 * tracking links, so the reach-based conversion rate (conversions ÷ views since
 * the link was created) uses fresh numbers instead of the stale value from the
 * last manual channel refresh. Runs daily.
 *
 * A tracked video is always one of the user's OWN videos — link creation
 * validates that the video belongs to a channel they connected — so we read its
 * view count with that owner's YouTube OAuth token (their own connected-account
 * data), grouping video ids by the channel that owns them. Videos whose channel
 * has no usable token fall back to the public Data API key when one is set;
 * outlier lookups elsewhere still use the API key.
 */
class RefreshTrackingReach extends Command
{
    protected $signature = 'tracking:refresh-reach {--chunk=50}';

    protected $description = 'Refresh content view counts (YouTube + Beehiiv + Instagram) for tracking links (reach denominator).';

    public function handle(YouTubeChannelService $youtube, BeehiivService $beehiiv): int
    {
        $videoIds = TrackingLink::query()
            ->whereNotNull('youtube_video_id')
            ->where('youtube_video_id', '!=', '')
            ->distinct()
            ->pluck('youtube_video_id')
            ->filter()
            ->values();

        $chunkSize = max(1, min(50, (int) $this->option('chunk'))); // YouTube caps id list at 50

        // Resolve each tracked video to the channel that owns it so we can pull
        // its views with that connected account's OAuth token. Anything without a
        // channel token drops to the public API-key fallback.
        $videoOwners = Video::query()
            ->whereIn('youtube_video_id', $videoIds->all())
            ->with('channel')
            ->get()
            ->keyBy('youtube_video_id');

        /** @var array<int, array{channel: Channel, ids: array<int, string>}> $byChannel */
        $byChannel = [];
        $apiKeyIds = [];
        foreach ($videoIds as $vid) {
            $channel = $videoOwners->get($vid)?->channel;
            if ($channel && $channel->youtube_access_token) {
                $byChannel[$channel->id]['channel'] = $channel;
                $byChannel[$channel->id]['ids'][] = $vid;
            } else {
                $apiKeyIds[] = $vid;
            }
        }

        $updated = 0;
        $failures = 0;

        // 1) Owner's OAuth token, one connected channel at a time.
        foreach ($byChannel as $bucket) {
            foreach (collect($bucket['ids'])->chunk($chunkSize) as $chunk) {
                try {
                    $updated += $this->applyViews($youtube->getVideoDetails($bucket['channel'], $chunk->all()));
                } catch (\Throwable $e) {
                    $failures++;
                    Log::error('tracking:refresh-reach OAuth batch failed', [
                        'channel_id' => $bucket['channel']->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Let the public API key try to cover this batch if we have one.
                    $apiKeyIds = array_merge($apiKeyIds, $chunk->all());
                }
            }
        }

        // 2) Public API-key fallback for videos with no connected channel token
        // (or whose OAuth fetch failed). A missing key is no longer an error now
        // that OAuth is the primary path — those videos are simply skipped.
        $apiKeyIds = array_values(array_unique($apiKeyIds));
        if (! empty($apiKeyIds)) {
            if (config('services.youtube.key')) {
                foreach (collect($apiKeyIds)->chunk($chunkSize) as $chunk) {
                    try {
                        $updated += $this->applyViews($youtube->getVideoDetailsWithApiKey($chunk->all()));
                    } catch (\Throwable $e) {
                        $failures++;
                        Log::error('tracking:refresh-reach api-key batch failed', ['error' => $e->getMessage()]);
                    }
                }
            } else {
                Log::warning('tracking:refresh-reach skipped videos with no connected channel and no YOUTUBE_API_KEY', [
                    'skipped' => count($apiKeyIds),
                ]);
            }
        }

        // Beehiiv-backed links: fetch each post's current views via the owner's
        // stored key (fetchPostViews is best-effort and never throws).
        $beehiivUpdated = 0;
        $beehiivLinks = TrackingLink::whereNotNull('beehiiv_post_id')
            ->where('beehiiv_post_id', '!=', '')
            ->with('event.user.beehiivConnection')
            ->get();

        foreach ($beehiivLinks as $link) {
            $conn = $link->event?->user?->beehiivConnection;
            if (! $conn || ! $conn->publication_id) {
                continue;
            }

            $views = $beehiiv->fetchPostViews($conn->api_key, $conn->publication_id, $link->beehiiv_post_id);
            if ($views === null) {
                continue;
            }

            $link->update(['current_view_count' => $views, 'reach_synced_at' => now()]);
            TrackingReachSnapshot::updateOrCreate(
                ['tracking_link_id' => $link->id, 'snapshot_date' => now()->toDateString()],
                ['view_count' => $views],
            );
            $beehiivUpdated++;
        }

        // Instagram-backed links: read each media's insights `views` with the
        // owning connected account's token. Per-media (no batch endpoint), and
        // gated behind the reach flag until the insights scope is granted.
        $igUpdated = 0;
        if (config('social.platforms.instagram.reach_enabled')) {
            $igLinks = TrackingLink::whereNotNull('instagram_media_id')
                ->where('instagram_media_id', '!=', '')
                ->with('event')
                ->get();

            $igPosts = app(\App\Services\InstagramPublishedPostsService::class);
            $igProvider = app(\App\Services\Social\SocialProviderManager::class)->for('instagram');

            foreach ($igLinks as $link) {
                $userId = $link->event?->user_id;
                if (! $userId) {
                    continue;
                }
                $account = $igPosts->accountFor($userId, $link->instagram_media_id);
                if (! $account) {
                    continue;
                }

                try {
                    $views = $igProvider->fetchMediaViews($account, $link->instagram_media_id);
                } catch (\Throwable $e) {
                    Log::warning('tracking:refresh-reach instagram fetch failed', ['link_id' => $link->id, 'error' => $e->getMessage()]);
                    continue;
                }
                if ($views === null) {
                    continue;
                }

                $link->update(['current_view_count' => $views, 'reach_synced_at' => now()]);
                TrackingReachSnapshot::updateOrCreate(
                    ['tracking_link_id' => $link->id, 'snapshot_date' => now()->toDateString()],
                    ['view_count' => $views],
                );
                $igUpdated++;
            }
        }

        Log::info('tracking:refresh-reach ran', ['videos' => $videoIds->count(), 'links_updated' => $updated, 'beehiiv_links_updated' => $beehiivUpdated, 'instagram_links_updated' => $igUpdated]);
        $this->info("Refreshed reach: {$videoIds->count()} video(s) across {$updated} link(s); {$beehiivUpdated} Beehiiv link(s); {$igUpdated} Instagram link(s).");

        // Loudly diagnosable: if there were videos to refresh but every fetch
        // failed (nothing updated), fail the run so the scheduler's onFailure
        // logs it instead of the command exiting green on stale data.
        if ($videoIds->isNotEmpty() && $updated === 0 && $failures > 0) {
            $this->error('tracking:refresh-reach: every YouTube fetch failed — reach is stale.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Apply fetched view counts to every tracking link pointing at each video,
     * writing a daily cumulative snapshot (powers the views chart). Returns the
     * number of links updated.
     *
     * @param  array  $items  YouTube videos.list items (id + statistics.viewCount)
     */
    private function applyViews(array $items): int
    {
        $updated = 0;
        foreach ($items as $item) {
            $vid = $item['id'] ?? null;
            $views = $item['statistics']['viewCount'] ?? null;
            if ($vid === null || $views === null) {
                continue;
            }

            // All links pointing at this video share the same current reach.
            $links = TrackingLink::where('youtube_video_id', $vid)->get(['id']);
            foreach ($links as $link) {
                $link->update(['current_view_count' => (int) $views, 'reach_synced_at' => now()]);
                TrackingReachSnapshot::updateOrCreate(
                    ['tracking_link_id' => $link->id, 'snapshot_date' => now()->toDateString()],
                    ['view_count' => (int) $views],
                );
                $updated++;
            }
        }

        return $updated;
    }
}
