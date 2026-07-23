<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Video;
use Illuminate\Support\Facades\Log;

class TrackingService
{
    protected $youTubeChannelService;

    protected BeehiivService $beehiiv;

    public function __construct(YouTubeChannelService $youTubeChannelService, BeehiivService $beehiiv)
    {
        $this->youTubeChannelService = $youTubeChannelService;
        $this->beehiiv = $beehiiv;
    }
    /**
     * Create an Event, Goals, and optional Links in a single transaction.
     */
    public function createEventWithLinksAndGoals(array $data)
    {
        return DB::transaction(function () use ($data) {
            // 1. Create Event
            $event = Offer::create([
                'user_id' => Auth::id(),
                'name' => $data['name'] ?? null,
                'offer_url' => $data['offer_url'],
                'conversion_value' => $data['conversion_value'] ?? 0,
            ]);

            // 2. Create Goals
            if (!empty($data['goals']) && is_array($data['goals'])) {
                foreach ($data['goals'] as $goalData) {
                    $event->goals()->create([
                        'event_type' => $goalData['event_type'],
                        'conversion_url' => $goalData['conversion_url'],
                        'conversion_value' => $goalData['conversion_value'] ?? 0,
                    ]);
                }
            }

            // 3. Create Links if provided
            if (!empty($data['links']) && is_array($data['links'])) {
                foreach ($data['links'] as $linkData) {
                    $this->createLink($event->id, $linkData);
                }
            }

            return $event->load(['links', 'goals']);
        });
    }

    /**
     * Create a single Tracking Link.
     */
    public function createLink(int $eventId, array $data)
    {
        // Generate a random 6-char hash for parameter_id
        $hash = $this->generateUniqueHash();

        $reach = $this->resolveReach($eventId, $data);
        $initialViewCount = $reach['initial'];
        $currentViewCount = $reach['current'];
        $reachSyncedAt = $reach['synced'];

        $link = TrackingLink::create([
            'tracking_event_id' => $eventId,
            'video_id' => $data['video_id'] ?? null,
            'youtube_video_id' => $data['youtube_video_id'] ?? null,
            'beehiiv_post_id' => $data['beehiiv_post_id'] ?? null,
            'x_post_id' => $data['x_post_id'] ?? null,
            'instagram_media_id' => $data['instagram_media_id'] ?? null,
            'placement' => $data['placement'] ?? TrackingLink::PLACEMENT_VIDEO,
            'placements' => $data['placements'] ?? null,
            'content_title' => $this->resolveContentTitle($eventId, $data),
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'parameter_id' => $hash,
            'initial_view_count' => $initialViewCount,
            'current_view_count' => $currentViewCount,
            'reach_synced_at' => $reachSyncedAt,
        ]);

        // Seed a same-day snapshot for Beehiiv/Instagram links so the views chart
        // has a baseline to diff against on the next refresh.
        if ($currentViewCount !== null && (! empty($data['beehiiv_post_id']) || ! empty($data['instagram_media_id']))) {
            TrackingReachSnapshot::updateOrCreate(
                ['tracking_link_id' => $link->id, 'snapshot_date' => now()->toDateString()],
                ['view_count' => $currentViewCount],
            );
        }

        return $link;
    }

    /**
     * Update an existing link. Reach fields are recomputed ONLY when the content
     * source actually changes, so editing a name never resets a YouTube link's
     * baseline. Switching to a non-reach platform clears the source + reach.
     */
    public function updateLink(TrackingLink $link, array $data): TrackingLink
    {
        $attrs = [];
        if (array_key_exists('name', $data)) {
            $attrs['name'] = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $attrs['description'] = $data['description'];
        }

        $newYoutube = ! empty($data['youtube_video_id']) ? $data['youtube_video_id'] : null;
        $newBeehiiv = ! empty($data['beehiiv_post_id']) ? $data['beehiiv_post_id'] : null;
        $sourceTouched = array_key_exists('youtube_video_id', $data) || array_key_exists('beehiiv_post_id', $data);
        $sourceChanged = $sourceTouched && ($newYoutube !== $link->youtube_video_id || $newBeehiiv !== $link->beehiiv_post_id);

        if ($sourceChanged) {
            $reach = $this->resolveReach($link->tracking_event_id, $data);
            $attrs['youtube_video_id'] = $newYoutube;
            $attrs['beehiiv_post_id'] = $newBeehiiv;
            $attrs['initial_view_count'] = $reach['initial'];
            $attrs['current_view_count'] = $reach['current'];
            $attrs['reach_synced_at'] = $reach['synced'];
            $attrs['placement'] = $newYoutube ? TrackingLink::PLACEMENT_VIDEO
                : ($newBeehiiv ? TrackingLink::PLACEMENT_BEEHIIV : ($data['placement'] ?? $link->placement));
        } elseif (array_key_exists('placement', $data)) {
            // Placement can change without a source change (non-reach platforms).
            $attrs['placement'] = $data['placement'];
        }

        if (array_key_exists('placements', $data)) {
            $attrs['placements'] = $data['placements'] ?: null;
        }

        if (array_key_exists('x_post_id', $data)) {
            $attrs['x_post_id'] = $data['x_post_id'] ?: null;
        }
        if (array_key_exists('instagram_media_id', $data)) {
            $attrs['instagram_media_id'] = $data['instagram_media_id'] ?: null;
        }

        // Any content-source change refreshes the title snapshot.
        if ($sourceChanged || array_key_exists('x_post_id', $data) || array_key_exists('instagram_media_id', $data)) {
            $attrs['content_title'] = $this->resolveContentTitle($link->tracking_event_id, [
                'youtube_video_id' => $sourceChanged ? $newYoutube : $link->youtube_video_id,
                'beehiiv_post_id' => $sourceChanged ? $newBeehiiv : $link->beehiiv_post_id,
                'x_post_id' => $attrs['x_post_id'] ?? $link->x_post_id,
                'instagram_media_id' => $attrs['instagram_media_id'] ?? $link->instagram_media_id,
            ]);
        }

        $link->update($attrs);

        if ($sourceChanged) {
            $this->snapshotIfBeehiiv($link, $data, $attrs['current_view_count'] ?? null);
        }

        return $link->fresh();
    }

    /** Seed a same-day snapshot for a Beehiiv link so the views chart has a baseline. */
    private function snapshotIfBeehiiv(TrackingLink $link, array $data, ?int $current): void
    {
        if (! empty($data['beehiiv_post_id']) && $current !== null) {
            TrackingReachSnapshot::updateOrCreate(
                ['tracking_link_id' => $link->id, 'snapshot_date' => now()->toDateString()],
                ['view_count' => $current],
            );
        }
    }

    /**
     * Compute the reach baseline for a link's content source.
     *
     * @return array{initial:int, current:?int, synced:?\Illuminate\Support\Carbon}
     */
    /**
     * Snapshot the linked content's title so the links table can show WHAT the
     * link points at (video title / newsletter subject / tweet excerpt).
     * Preference order mirrors reachKey(): YouTube, then Beehiiv, then X.
     */
    private function resolveContentTitle(int $eventId, array $data): ?string
    {
        $title = null;

        if (! empty($data['youtube_video_id'])) {
            $title = Video::where('youtube_video_id', $data['youtube_video_id'])->value('title');
        }

        if ($title === null && ! empty($data['beehiiv_post_id'])) {
            try {
                $conn = Offer::find($eventId)?->user?->beehiivConnection;
                if ($conn && $conn->publication_id) {
                    $posts = $this->beehiiv->fetchPosts($conn->api_key, $conn->publication_id);
                    $title = collect($posts)->firstWhere('id', $data['beehiiv_post_id'])['title'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::warning('Beehiiv title fetch failed', ['error' => $e->getMessage()]);
            }
        }

        if ($title === null && ! empty($data['x_post_id'])) {
            $userId = Offer::find($eventId)?->user_id;
            if ($userId) {
                $title = app(\App\Services\XPublishedPostsService::class)->textFor($userId, $data['x_post_id']);
            }
        }

        if ($title === null && ! empty($data['instagram_media_id'])) {
            $userId = Offer::find($eventId)?->user_id;
            if ($userId) {
                $title = app(\App\Services\InstagramPublishedPostsService::class)->textFor($userId, $data['instagram_media_id']);
            }
        }

        return $title !== null ? mb_substr($title, 0, 255) : null;
    }

    private function resolveReach(int $eventId, array $data): array
    {
        $initial = 0;
        $current = null;
        $synced = null;

        // YouTube first: when a multi-platform link carries several content
        // sources, YouTube's views-since-attach semantics win (mirrors reachKey).
        if (! empty($data['youtube_video_id'])) {
            $video = Video::where('youtube_video_id', $data['youtube_video_id'])->first();
            try {
                if ($video && $video->channel) {
                    $d = $this->youTubeChannelService->getVideoDetails($video->channel, $data['youtube_video_id']);
                    if (! empty($d)) {
                        $initial = (int) ($d[0]['statistics']['viewCount'] ?? 0);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch initial view count', ['error' => $e->getMessage()]);
                if (isset($video) && $video->view_count) {
                    $initial = $video->view_count;
                }
            }

            // Also record the count as "current" so the UI can show the video's
            // views immediately (the reach delta legitimately starts at 0).
            if ($initial > 0) {
                $current = $initial;
                $synced = now();
            }

            return compact('initial', 'current', 'synced');
        }

        // Beehiiv: a newsletter is a one-time send — reach = total opens, so keep
        // initial at 0 and let reach = current (opens).
        if (! empty($data['beehiiv_post_id'])) {
            $conn = Offer::find($eventId)?->user?->beehiivConnection;
            if ($conn && $conn->publication_id) {
                try {
                    $views = $this->beehiiv->fetchPostViews($conn->api_key, $conn->publication_id, $data['beehiiv_post_id']);
                    if ($views !== null) {
                        $current = $views;
                        $synced = now();
                    }
                } catch (\Throwable $e) {
                    Log::warning('Beehiiv view fetch failed', ['error' => $e->getMessage()]);
                }
            }

            return compact('initial', 'current', 'synced');
        }

        // Instagram: baseline the media's insights `views` at attach time so the
        // reach delta starts at 0 (mirrors YouTube). Gated behind the reach flag
        // — until the insights scope is granted we store the link but skip this.
        if (! empty($data['instagram_media_id']) && config('social.platforms.instagram.reach_enabled')) {
            $userId = Offer::find($eventId)?->user_id;
            if ($userId) {
                try {
                    $account = app(\App\Services\InstagramPublishedPostsService::class)
                        ->accountFor($userId, $data['instagram_media_id']);
                    if ($account) {
                        $views = app(\App\Services\Social\SocialProviderManager::class)
                            ->for('instagram')
                            ->fetchMediaViews($account, $data['instagram_media_id']);
                        if ($views !== null) {
                            $initial = $views;
                            $current = $views;
                            $synced = now();
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Instagram initial view fetch failed', ['error' => $e->getMessage()]);
                }
            }

            return compact('initial', 'current', 'synced');
        }

        if (! empty($data['video_id'])) {
            try {
                $video = Video::with('channel.user')->find($data['video_id']);
                if ($video && $video->channel) {
                    $d = $this->youTubeChannelService->getVideoDetails($video->channel, $video->youtube_video_id);
                    if (! empty($d)) {
                        $initial = (int) ($d[0]['statistics']['viewCount'] ?? 0);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch initial view count for tracking link', ['error' => $e->getMessage()]);
                if (isset($video) && $video->view_count) {
                    $initial = $video->view_count;
                }
            }
        }

        return compact('initial', 'current', 'synced');
    }

    private function generateUniqueHash()
    {
        do {
            $hash = Str::random(6);
        } while (TrackingLink::where('parameter_id', $hash)->exists());

        return $hash;
    }

    /**
     * Update an Event, its Goals, and its Links.
     */
    public function updateEventWithLinksAndGoals(Offer $event, array $data)
    {
        return DB::transaction(function () use ($event, $data) {
            // 1. Update Event fields
            $event->update([
                'name' => $data['name'] ?? $event->name,
                'offer_url' => $data['offer_url'] ?? $event->offer_url,
                'conversion_value' => $data['conversion_value'] ?? $event->conversion_value,
            ]);

            // 2. Handle Goals 
            if (isset($data['goals']) && is_array($data['goals'])) {
                 // Get IDs of goals present in the request
                 $goalIds = [];
                 foreach ($data['goals'] as $goalData) {
                     if (isset($goalData['id'])) {
                         $goalIds[] = $goalData['id'];
                         // Update existing
                         $goal = $event->goals()->find($goalData['id']);
                         if ($goal) {
                             $goal->update([
                                 'event_type' => $goalData['event_type'],
                                 'conversion_url' => $goalData['conversion_url'],
                                 'conversion_value' => $goalData['conversion_value'] ?? 0,
                             ]);
                         }
                     } else {
                         // Create new
                         $newGoal = $event->goals()->create([
                             'event_type' => $goalData['event_type'],
                             'conversion_url' => $goalData['conversion_url'],
                             'conversion_value' => $goalData['conversion_value'] ?? 0,
                         ]);
                         $goalIds[] = $newGoal->id;
                     }
                 }
                 // Delete goals not in the request
                 $event->goals()->whereNotIn('id', $goalIds)->delete();
            }


            // 3. Handle Link Updates/Creation
            if (isset($data['links']) && is_array($data['links'])) {
                $linkIds = [];
                foreach ($data['links'] as $linkData) {
                    if (isset($linkData['id'])) {
                        $linkIds[] = $linkData['id'];
                        // Update existing link (ensure it belongs to this event)
                        $link = $event->links()->where('id', $linkData['id'])->first();
                        if ($link) {
                            $link->update([
                                'video_id' => $linkData['video_id'] ?? $link->video_id,
                                'youtube_video_id' => $linkData['youtube_video_id'] ?? $link->youtube_video_id,
                                'placement' => $linkData['placement'] ?? $link->placement,
                                'name' => $linkData['name'] ?? $link->name,
                                'description' => $linkData['description'] ?? $link->description,
                            ]);
                        }
                    } else {
                        // Create new link
                        $newLink = $this->createLink($event->id, $linkData);
                        $linkIds[] = $newLink->id;
                    }
                }
                
                // Delete links not in the request
                $event->links()->whereNotIn('id', $linkIds)->delete();
            }

            return $event->load(['links', 'goals']);
        });
    }
}
