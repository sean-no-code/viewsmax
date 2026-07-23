<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\User;
use App\Models\Video;
use App\Traits\LogsYouTubeRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class YouTubeChannelService
{
    use LogsYouTubeRequests;

    private const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';
    private $apiKey;
    private $videoCacheDurationHours;
    private $channelCacheDurationHours;
    private $sampleSize;

    public function __construct(
        private YouTubeAnalyticsService $analyticsService
    ) {
        $this->apiKey = config('services.youtube.key');
        $this->videoCacheDurationHours = config('services.youtube.video_cache_duration_hours', 1);
        $this->channelCacheDurationHours = config('services.youtube.channel_cache_duration_hours', 24);
        $this->sampleSize = config('services.youtube.sample_size', 30);
    }
    
    /**
     * Get channel data from YouTube API
     * 
     * @param string $accessToken OAuth access token
     * @param string|null $channelId Specific channel ID (optional, uses mine=true if null)
     * @return array Channel data
     * @throws \Exception
     */
    public function getChannelData(string $accessToken, ?string $channelId = null): array
    {
        $params = [
            'part' => 'snippet,statistics,contentDetails',
        ];
        
        if ($channelId) {
            $params['id'] = $channelId;
        } else {
            $params['mine'] = 'true';
        }
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(self::API_BASE_URL . '/channels', $params);
        
        if (!$response->successful()) {
            Log::error('YouTube API error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new \Exception('Failed to fetch channel data: ' . $response->body());
        }
        
        $data = $response->json();
        
        if (empty($data['items'])) {
            throw new \Exception('No channel found');
        }
        
        return $data['items'][0];
    }

    /**
     * Get channel videos from YouTube API
     * Uses search.list to get video IDs, then videos.list for details
     * 
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @param int $maxResults Maximum number of videos (1-50)
     * @param string|null $pageToken Pagination token
     * @return array{videos: array, nextPageToken: string|null} ['videos' => [...], 'nextPageToken' => '...']
     * @throws \Exception
     */
    /**
     * Raw fetch of video details from API. Returns array or null if not found.
     * Does NOT save to database.
     */
    public function fetchVideoDetailsFromApi(string $videoId): ?array
    {
        $params = [
            'part' => 'statistics,snippet,contentDetails',
            'id'   => $videoId,
            'key'  => $this->apiKey,
        ];

        $this->logYouTubeRequest('/videos', $params);

        $response = Http::get(self::API_BASE_URL . '/videos', $params);

        if ($response->failed()) {
            Log::error('YouTube API Request Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new \Exception('YouTube API Error: ' . $response->body());
        }

        if (empty($response->json('items'))) {
            return null;
        }

        return $response->json('items')[0];
    }

    /**
     * Get details for multiple video IDs using API key only (no OAuth).
     * Used for outlier scope to avoid consuming OAuth quota.
     */
    public function getVideoDetailsWithApiKey(array $videoIds): array
    {
        if (empty($videoIds)) {
            return [];
        }

        $params = [
            'part' => 'snippet,statistics,contentDetails',
            'id'   => implode(',', $videoIds),
            'key'  => $this->apiKey,
        ];

        $this->logYouTubeRequest('/videos', $params);

        $response = Http::get(self::API_BASE_URL . '/videos', $params);

        if ($response->failed()) {
            Log::error('YouTube API (getVideoDetailsWithApiKey) failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('YouTube API Error: ' . $response->body());
        }

        return $response->json('items') ?? [];
    }

    /**
     * Raw fetch of channel details from API. Returns array or null if not found.
     * Does NOT save to database.
     */
    public function fetchChannelDetailsFromApi(string $channelId): ?array
    {
        $params = [
            'part' => 'snippet,statistics',
            'id'   => $channelId,
            'key'  => $this->apiKey,
        ];

        $this->logYouTubeRequest('/channels', $params);

        $response = Http::get(self::API_BASE_URL . '/channels', $params);

        if ($response->successful() && !empty($response->json('items'))) {
            return $response->json('items')[0];
        }

        return null;
    }

    /**
     * Batch fetch channel details for multiple channel IDs using API key.
     * YouTube allows up to 50 IDs per request — this method handles chunking automatically.
     * Returns a map of [youtube_channel_id => channelData].
     */
    public function getChannelsBatch(array $channelIds): array
    {
        if (empty($channelIds)) {
            return [];
        }

        $chunks     = array_chunk(array_unique($channelIds), 50);
        $channelMap = [];

        foreach ($chunks as $chunk) {
            $params = [
                'part' => 'snippet,statistics',
                'id'   => implode(',', $chunk),
                'key'  => $this->apiKey,
            ];

            $this->logYouTubeRequest('/channels', $params);

            $response = Http::get(self::API_BASE_URL . '/channels', $params);

            if ($response->successful()) {
                foreach ($response->json('items') ?? [] as $item) {
                    $id = $item['id'] ?? null;
                    if ($id) {
                        $channelMap[$id] = $item;
                    }
                }
            }
        }

        return $channelMap;
    }

    /**
     * Raw fetch of recent videos for a channel (used for average calculation).
     * Returns Collection of videos with 'id', 'title', 'views', 'duration'.
     * Does NOT save to DB.
     */
    public function fetchRecentChannelVideosFromApi(string $youtubeChannelId, ?string $excludeVideoId = null, ?bool $includeShorts = true): Collection
    {
        $uploadsPlaylistId = 'UU' . substr($youtubeChannelId, 2);
        $validVideos = collect();
        $nextPageToken = null;
        $pagesFetched = 0;
        $maxPages = config('services.youtube.max_pages', 5);
        $sampleSize = $this->sampleSize;

        do {
            $pagesFetched++;

            $params = [
                'part'        => 'snippet,contentDetails',
                'playlistId'  => $uploadsPlaylistId,
                'maxResults'  => 50,
                'key'         => $this->apiKey,
                'pageToken'   => $nextPageToken,
            ];

            $this->logYouTubeRequest('/playlistItems', $params);
            $playlistResponse = Http::get(self::API_BASE_URL . '/playlistItems', $params);

            if ($playlistResponse->failed()) {
                Log::error("Failed to fetch playlist items: " . $playlistResponse->body());
                break;
            }

            $nextPageToken = $playlistResponse->json('nextPageToken');
            $items = collect($playlistResponse->json('items') ?? [])
                ->reject(fn($item) => $item['contentDetails']['videoId'] === $excludeVideoId);

            if ($items->isEmpty()) {
                break;
            }

            $videoIds = $items->pluck('contentDetails.videoId')->toArray();

            $statsParams = [
                'part' => 'statistics,contentDetails,snippet',
                'id'   => implode(',', $videoIds),
                'key'  => $this->apiKey,
            ];

            $this->logYouTubeRequest('/videos', $statsParams);
            $statsResponse = Http::get(self::API_BASE_URL . '/videos', $statsParams);
            $statsItems = collect($statsResponse->json('items') ?? []);

            foreach ($statsItems as $item) {
                // Determine if valid based on shorts logic
                $durationIso = $item['contentDetails']['duration'] ?? null;
                $isShort = false;
                
                if ($durationIso) {
                    try {
                        $interval = new \DateInterval($durationIso);
                        $seconds = ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
                        if ($seconds <= 180) $isShort = true;
                    } catch (\Exception $e) { $isShort = true; }
                } else {
                    $isShort = true;
                }

                if (!$includeShorts && $isShort) continue;

                $validVideos->push([
                    'id' => $item['id'],
                    'title' => $item['snippet']['title'] ?? 'Unknown Title',
                    'views' => (int) ($item['statistics']['viewCount'] ?? 0),
                    'duration' => $durationIso ?? 'PT0S',
                ]);

                if ($validVideos->count() >= $sampleSize) break 2;
            }

        } while ($nextPageToken && $pagesFetched < $maxPages && $validVideos->count() < $sampleSize);

        return $validVideos;
    }

    /**
     * Get Channel Average for Outliers (using outlier_db)
     * Creates/Updates OutlierChannel in outlier_db.
     * Pass $prefetchedChannelData from getChannelsBatch() to skip the individual /channels API call.
     */
    public function getOutlierChannelAverage(string $youtubeChannelId, ?string $excludeVideoId = null, ?array $prefetchedChannelData = null): array
    {
        // 1. Check OutlierChannel in outlier_db
        $outlierChannel = OutlierChannel::where('youtube_channel_id', $youtubeChannelId)->first();

        // Check freshness
        $shouldRefresh = true;
        if ($outlierChannel && $outlierChannel->average_calculated_at) {
            if ($outlierChannel->average_calculated_at->diffInHours(now()) < $this->channelCacheDurationHours) {
                $shouldRefresh = false;
            }
        }

        // Return cached if valid
        if ($outlierChannel && !$shouldRefresh) {
            Log::info("Outlier channel average cache hit for {$youtubeChannelId}", [
                'video_id'      => $excludeVideoId,
                'average_views' => $outlierChannel->average_views,
                'calculated_at' => $outlierChannel->average_calculated_at,
            ]);
            return [
                'average' => $outlierChannel->average_views,
                'channel' => $outlierChannel,
                'source'  => 'db_cache',
            ];
        }

        // 2. Refresh Data
        Log::info("Calculating channel average for {$youtubeChannelId} (cache miss/stale)");

        // Use pre-fetched batch data if available, otherwise fall back to individual API call
        $channelData = $prefetchedChannelData ?? $this->fetchChannelDetailsFromApi($youtubeChannelId);

        $channelAttributes = [];
        if ($channelData) {
            $channelAttributes = [
                'channel_name'      => $channelData['snippet']['title'] ?? null,
                'profile_image_url' => $channelData['snippet']['thumbnails']['high']['url']
                    ?? $channelData['snippet']['thumbnails']['default']['url'] ?? null,
                'subscriber_count'  => (int)($channelData['statistics']['subscriberCount'] ?? 0),
                'video_count'       => (int)($channelData['statistics']['videoCount'] ?? 0),
            ];
        }

        // Fetch Recent Videos for Average Calculation
        Log::info("Fetching channel median for {$youtubeChannelId} from Public API");
        $recentVideos = $this->fetchRecentChannelVideosFromApi($youtubeChannelId, $excludeVideoId, true);

        $average  = 0;
        $videoIds = [];

        if ($recentVideos->isNotEmpty()) {
            $average  = (int) $recentVideos->median('views');
            $videoIds = $recentVideos->pluck('id')->toArray();
            Log::info("Outlier channel median calculated for {$youtubeChannelId}", [
                'median_views' => $average,
                'sample_size'  => $recentVideos->count(),
            ]);
        } else {
            Log::warning("No videos found for outlier channel average: {$youtubeChannelId}");
        }

        $channelAttributes['average_views']        = $average;
        $channelAttributes['average_calculated_at'] = now();
        $channelAttributes['average_video_ids']    = $videoIds;
        $channelAttributes['youtube_channel_id']   = $youtubeChannelId;

        $outlierChannel = OutlierChannel::updateOrCreate(
            ['youtube_channel_id' => $youtubeChannelId],
            $channelAttributes
        );

        return [
            'average' => $average,
            'channel' => $outlierChannel,
            'source'  => 'api_fresh',
        ];
    }


    /**
     * Get (or fetch and save) OutlierVideo.
     * Returns ['video' => OutlierVideo, 'source' => 'db_cache'|'api_fresh'], or null if not found.
     * Uses $videoCacheDurationHours to determine if a cached record is still fresh.
     */
    public function getOutlierVideo(string $videoId, ?string $source = null): ?array
    {
        // 1. Check DB
        $video = OutlierVideo::where('youtube_video_id', $videoId)->first();

        // 2. Freshness check — return from cache if still within the cache window
        if ($video) {
            $isStale = $video->updated_at
                ? $video->updated_at->diffInHours(now()) >= $this->videoCacheDurationHours
                : true;

            if (!$isStale) {
                Log::info('getOutlierVideo: cache hit (fresh)', [
                    'video_id'   => $videoId,
                    'updated_at' => $video->updated_at,
                    'cache_hours' => $this->videoCacheDurationHours,
                ]);
                return ['video' => $video, 'source' => 'db_cache'];
            }

            Log::info('getOutlierVideo: cache stale, re-fetching from API', [
                'video_id'   => $videoId,
                'updated_at' => $video->updated_at,
                'cache_hours' => $this->videoCacheDurationHours,
            ]);
        }

        // 2. Fetch from API (cache miss)
        $apiData = $this->fetchVideoDetailsFromApi($videoId);
        if (!$apiData) {
            return null;
        }

        // 3. Ensure Channel Exists
        $channelId = $apiData['snippet']['channelId'] ?? null;
        if (!$channelId) return null;

        $channel = OutlierChannel::firstOrCreate(
            ['youtube_channel_id' => $channelId],
            ['channel_name' => $apiData['snippet']['channelTitle'] ?? 'Unknown']
        );

        // 4. Save or Update Video (handles both cache-miss and stale refresh)
        $snippet        = $apiData['snippet'] ?? [];
        $statistics     = $apiData['statistics'] ?? [];
        $contentDetails = $apiData['contentDetails'] ?? [];
        $thumbnails     = $snippet['thumbnails'] ?? [];

        $video = OutlierVideo::updateOrCreate(
            ['youtube_video_id' => $videoId],
            [
                'channel_id'          => $channel->id,
                'title'               => $snippet['title'] ?? null,
                'description'         => $snippet['description'] ?? null,
                'thumbnail_url'       => $thumbnails['default']['url'] ?? null,
                'thumbnail_medium_url'=> $thumbnails['medium']['url'] ?? null,
                'views'               => (int)($statistics['viewCount'] ?? 0),
                'duration'            => $contentDetails['duration'] ?? null,
                'published_at'        => isset($snippet['publishedAt'])
                    ? Carbon::parse($snippet['publishedAt'])
                    : null,
            ]
        );

        return ['video' => $video, 'source' => 'api_fresh'];
    }

    /**
     * Orchestrates the full outlier multiplier calculation for a single video.
     * Single responsibility: fetch video, fetch average, calculate score, persist.
     * Returns result array or ['error' => string] on failure.
     */
    public function calculateMultiplier(string $videoId): array
    {
        // 1. Get video with freshness check
        $videoData = $this->getOutlierVideo($videoId, 'outliers_multiplier');
        if (!$videoData) {
            return ['error' => 'not_found'];
        }

        $video = $videoData['video'];

        // 2. Resolve channel — must exist since getOutlierVideo created it
        $channel = $video->channel;
        if (!$channel) {
            return ['error' => 'channel_not_found'];
        }

        // 3. Get channel average — ONLY place /channels is called for this flow.
        //    Has its own freshness check (channelCacheDurationHours).
        $averageData = $this->getOutlierChannelAverage($channel->youtube_channel_id, $videoId);
        $average     = $averageData['average'];
        $multiplier  = $this->computeOutlierScore($video->views, $average);

        // 4. Persist updated score if changed
        if ($video->outlier_score != $multiplier) {
            if ($multiplier < OutlierVideo::minScore()) {
                $video->delete();
                return ['error' => 'below_minimum_score'];
            }
            $video->update(['outlier_score' => $multiplier]);
        }

        Log::info('calculateMultiplier', [
            'video_id'     => $videoId,
            'views'        => $video->views,
            'average'      => $average,
            'multiplier'   => $multiplier,
            'video_source' => $videoData['source'],
            'avg_source'   => $averageData['source'],
        ]);

        return [
            'video'        => $video,
            'channel'      => $averageData['channel'],
            'average'      => $average,
            'multiplier'   => $multiplier,
            'video_source' => $videoData['source'],
            'avg_source'   => $averageData['source'],
        ];
    }

    /**
     * Single source of truth for the outlier score formula.
     * Both the search flow and the multiplier endpoint use this.
     */
    public function computeOutlierScore(int $views, float $average): float
    {
        return $average > 0 ? $views / $average : 0.0;
    }

    /**
     * Single source of truth for persisting an OutlierVideo record.
     * Both the search flow and the multiplier endpoint use this.
     *
     * @param array $videoData  Normalized video data (keys: youtube_video_id, title, description,
     *                          thumbnail_url, thumbnail_medium_url, views, duration, published_at)
     * @param OutlierChannel $channel
     * @param float $score
     */
    public function saveOutlierVideo(array $videoData, OutlierChannel $channel, float $score): ?OutlierVideo
    {
        if ($score < OutlierVideo::minScore()) {
            return null;
        }

        return OutlierVideo::updateOrCreate(
            ['youtube_video_id' => $videoData['youtube_video_id']],
            [
                'channel_id'           => $channel->id,
                'title'                => $videoData['title'] ?? null,
                'description'          => $videoData['description'] ?? null,
                'thumbnail_url'        => $videoData['thumbnail_url'] ?? null,
                'thumbnail_medium_url' => $videoData['thumbnail_medium_url'] ?? null,
                'views'                => (int) ($videoData['views'] ?? 0),
                'duration'             => $videoData['duration'] ?? null,
                'published_at'         => $videoData['published_at'] ?? null,
                'outlier_score'        => $score,
            ]
        );
    }
    public function getChannelVideos(string $accessToken, string $channelId, int $maxResults = 50, ?string $pageToken = null): array
    {
        // Step 1: Get video IDs using search.list
        $searchParams = [
            'part' => 'snippet',
            'channelId' => $channelId,
            'type' => 'video',
            'order' => 'date',
            'maxResults' => min($maxResults, 50), // API limit is 50
        ];
        
        if ($pageToken) {
            $searchParams['pageToken'] = $pageToken;
        }
        
        $searchResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(self::API_BASE_URL . '/search', $searchParams);
        
        if (!$searchResponse->successful()) {
            Log::error('YouTube search API error', [
                'status' => $searchResponse->status(),
                'body' => $searchResponse->body()
            ]);
            throw new \Exception('Failed to search videos: ' . $searchResponse->body());
        }
        
        $searchData = $searchResponse->json();
        Log::info('Search data', ['searchData' => $searchData]);
        
        // Extract video IDs from search results
        $videoIds = [];
        foreach ($searchData['items'] ?? [] as $item) {
            if (isset($item['id']['videoId'])) {
                $videoIds[] = $item['id']['videoId'];
            }
        }
        
        Log::info('Extracted video IDs', ['videoIds' => $videoIds, 'count' => count($videoIds)]);
        
        if (empty($videoIds)) {
            Log::warning('No video IDs extracted from search results', [
                'items_count' => count($searchData['items'] ?? []),
                'items' => $searchData['items'] ?? []
            ]);
            return [
                'videos' => [],
                'nextPageToken' => $searchData['nextPageToken'] ?? null
            ];
        }
        
        // Step 2: Get detailed video information using videos.list
        $videoParams = [
            'part' => 'snippet,statistics,contentDetails',
            'id' => implode(',', $videoIds),
        ];
        
        $videoResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(self::API_BASE_URL . '/videos', $videoParams);
        
        if (!$videoResponse->successful()) {
            Log::error('YouTube videos API error', [
                'status' => $videoResponse->status(),
                'body' => $videoResponse->body()
            ]);
            throw new \Exception('Failed to fetch video details: ' . $videoResponse->body());
        }
        
        $videoData = $videoResponse->json();
        
        Log::info('Videos API response', [
            'video_ids_requested' => $videoIds,
            'videos_received' => count($videoData['items'] ?? []),
            'video_ids_received' => array_column($videoData['items'] ?? [], 'id')
        ]);
        
        return [
            'videos' => $videoData['items'] ?? [],
            'nextPageToken' => $searchData['nextPageToken'] ?? null
        ];
    }

    /**
     * Execute an API callback with auto-refresh if token is expired
     * 
     * @param callable $callback Function that takes an access token string and returns the response
     * @param Channel|string $authSource Channel model (for refresh) or access token string
     * @return mixed Response from the callback
     * @throws \Exception
     */
    private function executeWithTokenRefresh(callable $callback, $authSource)
    {
        // Resolve initial access token
        $accessToken = $authSource instanceof Channel ? $authSource->youtube_access_token : $authSource;

        // Preemptive Token Refresh Check
        if ($authSource instanceof Channel && $authSource->youtube_refresh_token && $authSource->youtube_token_expires_at) {
            // Check if token is expired or expiring in the next 5 minutes
            if ($authSource->youtube_token_expires_at->lessThanOrEqualTo(now()->addMinutes(5))) {
                Log::info('Token is expired or expiring soon, refreshing...', [
                    'channel_id' => $authSource->id,
                    'excludes_at' => $authSource->youtube_token_expires_at
                ]);

                try {
                    // Refresh token
                    $tokenData = $this->refreshAccessToken($authSource->youtube_refresh_token);
                    
                    // Update channel with new token
                    $authSource->update([
                        'youtube_access_token' => $tokenData['access_token'],
                        'youtube_token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                    ]);
                    
                    // Use new token
                    $accessToken = $tokenData['access_token'];
                    Log::info('Token refresh successful');
                } catch (\Exception $refreshException) {
                    Log::error('Preemptive token refresh failed', [
                        'error' => $refreshException->getMessage()
                    ]);
               
                    throw $refreshException;
                }
            }
        }

        // execute callback with valid (or existing) token
        return $callback($accessToken);
    }

    /**
     * Get details for specific video IDs
     * 
     * @param Channel|string $authSource Channel model or OAuth access token
     * @param array|string $videoIds Single video ID or array of IDs
     * @return array Video details from YouTube API
     * @throws \Exception
     */
    public function getVideoDetails($authSource, array|string $videoIds): array
    {
        $ids = is_array($videoIds) ? implode(',', $videoIds) : $videoIds;

        $videoParams = [
            'part' => 'snippet,statistics,contentDetails',
            'id' => $ids,
        ];
        
        return $this->executeWithTokenRefresh(function ($token) use ($videoParams) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get(self::API_BASE_URL . '/videos', $videoParams);
            
            if (!$response->successful()) {
                Log::error('YouTube videos API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception('Failed to fetch video details: ' . $response->body());
            }
            
            $data = $response->json();
            return $data['items'] ?? [];
        }, $authSource);
    }

    /**
     * Get all videos from a channel (handles pagination)
     * 
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @param int|null $maxTotal Maximum total videos to fetch (optional)
     * @return array All videos
     * @throws \Exception
     */
    public function getAllChannelVideos(string $accessToken, string $channelId, ?int $maxTotal = null): array
    {
        $allVideos = [];
        $pageToken = null;
        $fetched = 0;
        
        do {
            $result = $this->getChannelVideos($accessToken, $channelId, 50, $pageToken);
            $allVideos = array_merge($allVideos, $result['videos']);
            $pageToken = $result['nextPageToken'];
            $fetched += count($result['videos']);
            
            // Stop if we've reached maxTotal
            if ($maxTotal && $fetched >= $maxTotal) {
                break;
            }
            
        } while ($pageToken);
        
        return $allVideos;
    }

    /**
     * Refresh access token using refresh token
     * 
     * @param string $refreshToken OAuth refresh token
     * @return array{access_token: string, expires_in: int} ['access_token' => '...', 'expires_in' => 3600]
     * @throws \Exception
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        
        if (!$response->successful()) {
            Log::error('Token refresh failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new \Exception('Token refresh failed: ' . $response->body());
        }
        
        Log::info('Token refresh response', ['response' => $response->json()]);

        $tokenData = $response->json();
        
        return [
            'access_token' => $tokenData['access_token'],
            'expires_in' => $tokenData['expires_in'] ?? 3600,
        ];
    }

    /**
     * Map YouTube API video data to database format
     * 
     * @param array $videoData YouTube API video item
     * @return array Mapped data for database storage
     */
    public function mapVideoDataToDatabase(array $videoData): array
    {
        $snippet = $videoData['snippet'] ?? [];
        $statistics = $videoData['statistics'] ?? [];
        $contentDetails = $videoData['contentDetails'] ?? [];
        $thumbnails = $snippet['thumbnails'] ?? [];

        return [
            'youtube_video_id' => $videoData['id'] ?? null,
            'title' => $snippet['title'] ?? null,
            'description' => $snippet['description'] ?? null,
            'thumbnail_url' => $thumbnails['default']['url'] ?? null,
            'thumbnail_medium_url' => $thumbnails['medium']['url'] ?? null,
            'thumbnail_high_url' => $thumbnails['high']['url'] ?? null,
            'published_at' => isset($snippet['publishedAt']) 
                ? \Carbon\Carbon::parse($snippet['publishedAt']) 
                : null,
            'view_count' => (int)($statistics['viewCount'] ?? 0),
            'like_count' => (int)($statistics['likeCount'] ?? 0),
            'comment_count' => (int)($statistics['commentCount'] ?? 0),
            'duration' => $contentDetails['duration'] ?? null, // ISO 8601 format
            'definition' => $contentDetails['definition'] ?? null, // "hd" or "sd"
            'has_captions' => isset($contentDetails['caption']) && $contentDetails['caption'] === 'true',
        ];
    }

    /**
     * Get channel playlists from YouTube API
     * 
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @return array Playlists data formatted for storage
     * @throws \Exception
     */
    public function getChannelPlaylists(string $accessToken, string $channelId): array
    {
        $params = [
            'part' => 'snippet,contentDetails,status',
            'channelId' => $channelId,
            'maxResults' => 50,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(self::API_BASE_URL . '/playlists', $params);

        if (!$response->successful()) {
            Log::error('YouTube Playlists API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'channel_id' => $channelId
            ]);
            throw new \Exception('Failed to fetch playlists: ' . $response->body());
        }

        $data = $response->json();
        $playlists = [];

        foreach ($data['items'] ?? [] as $item) {
            $snippet = $item['snippet'] ?? [];
            $contentDetails = $item['contentDetails'] ?? [];
            $status = $item['status'] ?? [];
            $thumbnails = $snippet['thumbnails'] ?? [];

            $playlists[] = [
                'id' => $item['id'] ?? null,
                'title' => $snippet['title'] ?? null,
                'description' => $snippet['description'] ?? null,
                'thumbnail' => $thumbnails['default']['url'] 
                    ?? $thumbnails['medium']['url'] 
                    ?? $thumbnails['high']['url'] 
                    ?? null,
                'itemCount' => (int)($contentDetails['itemCount'] ?? 0),
                'publishedAt' => isset($snippet['publishedAt']) 
                    ? \Carbon\Carbon::parse($snippet['publishedAt'])->format('Y-m-d') 
                    : null,
                'privacy' => $status['privacyStatus'] ?? 'unknown',
            ];
        }

        Log::info('Channel playlists fetched successfully', [
            'channel_id' => $channelId,
            'playlists_count' => count($playlists)
        ]);

        return $playlists;
    }

    /**
     * Create or update channel with data and analytics
     * Handles both OAuth exchange (create/update) and refresh scenarios
     * Fetches channel data, creates/updates the channel, and fetches analytics
     * 
     * @param User $user The user who owns the channel
     * @param string $accessToken OAuth access token
     * @param string|null $channelId Optional YouTube channel ID (if null, uses mine=true)
     * @param array|null $tokenData Optional token data from OAuth exchange (for OAuth scenarios)
     * @return Channel The created or updated channel
     */
    public function createOrUpdateChannel(User $user, string $accessToken, ?string $channelId = null, ?array $tokenData = null): Channel
    {
        // Fetch channel data from YouTube API
        $channelData = $this->getChannelData($accessToken, $channelId);
        
        // Prepare base channel data
        $channelAttributes = [
            // Channel data from YouTube API
            'channel_name' => $channelData['snippet']['title'] ?? null,
            'channel_description' => $channelData['snippet']['description'] ?? null,
            'subscriber_count' => (int)($channelData['statistics']['subscriberCount'] ?? 0),
            'video_count' => (int)($channelData['statistics']['videoCount'] ?? 0),
            'view_count' => (int)($channelData['statistics']['viewCount'] ?? 0),
            'custom_url' => $channelData['snippet']['customUrl'] ?? null,
            'country' => $channelData['snippet']['country'] ?? null,
            'published_at' => isset($channelData['snippet']['publishedAt']) 
                ? \Carbon\Carbon::parse($channelData['snippet']['publishedAt']) 
                : null,
            'profile_image_url' => $channelData['snippet']['thumbnails']['high']['url'] 
                ?? $channelData['snippet']['thumbnails']['default']['url'] 
                ?? null,
        ];

        // If tokenData is provided (OAuth exchange scenario), add OAuth data
        if ($tokenData !== null) {
            // Extract scopes from token response (space-separated string)
            $scopes = isset($tokenData['scope']) 
                ? explode(' ', $tokenData['scope']) 
                : [];
            
            $channelAttributes['youtube_access_token'] = $tokenData['access_token'];
            $channelAttributes['youtube_refresh_token'] = $tokenData['refresh_token'] ?? null;
            $channelAttributes['youtube_token_expires_at'] = now()->addSeconds($tokenData['expires_in'] ?? 3600);
            $channelAttributes['oauth_scopes'] = json_encode($scopes);
        }

        // Check for an existing public channel (orphan) and claim it
        $publicChannel = Channel::where('youtube_channel_id', $channelData['id'])
            ->whereNull('user_id')
            ->first();

        if ($publicChannel) {
            Log::info("User {$user->id} claiming public channel {$publicChannel->id}");
            $publicChannel->update(['user_id' => $user->id]);
        }

        // Create or update channel
        $channel = Channel::updateOrCreate(
            [
                'user_id' => $user->id,
                'youtube_channel_id' => $channelData['id']
            ],
            $channelAttributes
        );
        
        Log::info('Channel created/updated', [
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'youtube_channel_id' => $channel->youtube_channel_id,
            'is_oauth_exchange' => $tokenData !== null
        ]);
        
        // Fetch and store comprehensive analytics data
        $this->fetchAndStoreChannelAnalytics($channel, $accessToken);
        
        return $channel->fresh();
    }

    /**
     * Fetch and store comprehensive analytics data for a channel
     * This includes playlists, views over time, demographics, and watch time analytics
     * 
     * @param Channel $channel The channel model to update
     * @param string $accessToken OAuth access token
     * @return void
     */
    public function fetchAndStoreChannelAnalytics(Channel $channel, string $accessToken): void
    {
        try {
            Log::info('Fetching comprehensive analytics for channel', [
                'channel_id' => $channel->id,
                'youtube_channel_id' => $channel->youtube_channel_id
            ]);

            // Fetch playlists
            $playlists = null;
            try {
                $playlists = $this->getChannelPlaylists($accessToken, $channel->youtube_channel_id);
                Log::info('Playlists fetched successfully', ['count' => count($playlists)]);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch playlists', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Fetch comprehensive analytics
            $comprehensiveAnalytics = null;
            try {
                $comprehensiveAnalytics = $this->analyticsService->getComprehensiveAnalytics(
                    $accessToken,
                    $channel->youtube_channel_id
                );
                Log::info('Comprehensive analytics fetched successfully', [
                    'eligible' => $comprehensiveAnalytics['analytics_eligible'] ?? false
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch comprehensive analytics', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Update channel with analytics data
            $updateData = [
                'analytics_last_updated' => now(),
            ];

            if ($playlists !== null) {
                $updateData['playlists'] = $playlists;
            }

            if ($comprehensiveAnalytics !== null) {
                $updateData['analytics_eligible'] = $comprehensiveAnalytics['analytics_eligible'] ?? false;
                $updateData['analytics_reason'] = $comprehensiveAnalytics['analytics_reason'] ?? null;
                
                if (isset($comprehensiveAnalytics['views_over_time'])) {
                    $updateData['views_over_time'] = $comprehensiveAnalytics['views_over_time'];
                }
                
                if (isset($comprehensiveAnalytics['audience_demographics'])) {
                    $updateData['audience_demographics'] = $comprehensiveAnalytics['audience_demographics'];
                }
                
                if (isset($comprehensiveAnalytics['watch_time_analytics'])) {
                    $updateData['watch_time_analytics'] = $comprehensiveAnalytics['watch_time_analytics'];
                }
            }

            $channel->update($updateData);

            Log::info('Channel analytics stored successfully', [
                'channel_id' => $channel->id,
                'has_playlists' => $playlists !== null,
                'analytics_eligible' => $updateData['analytics_eligible'] ?? false
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch and store channel analytics', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw - analytics fetching should not break channel operations
        }
    }

    /**
     * Fetch and import videos for a channel from YouTube API
     * 
     * @param Channel $channel The channel model
     * @param string $accessToken OAuth access token
     * @param int|null $maxTotal Maximum number of videos to fetch (optional, defaults to 100)
     * @return array{imported: int, updated: int, total: int} Import statistics
     * @throws \Exception
     */
    public function fetchAndImportChannelVideos(Channel $channel, string $accessToken, ?int $maxTotal = 100): array
    {
        Log::info('Fetching videos for channel', [
            'channel_id' => $channel->id,
            'youtube_channel_id' => $channel->youtube_channel_id,
            'max_total' => $maxTotal
        ]);

        $videos = $this->getAllChannelVideos(
            $accessToken,
            $channel->youtube_channel_id,
            $maxTotal
        );

        $importedCount = 0;
        $updatedCount = 0;

        foreach ($videos as $videoData) {
            $mappedData = $this->mapVideoDataToDatabase($videoData);
            $mappedData['channel_id'] = $channel->id;

            $video = Video::updateOrCreate(
                ['youtube_video_id' => $videoData['id']],
                $mappedData
            );

            if ($video->wasRecentlyCreated) {
                $importedCount++;
            } else {
                $updatedCount++;
            }
        }

        Log::info('Videos imported successfully', [
            'channel_id' => $channel->id,
            'imported_count' => $importedCount,
            'updated_count' => $updatedCount,
            'total_fetched' => count($videos)
        ]);

        return [
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'total' => count($videos)
        ];
    }
    /**
     * Public method to get video details using API Key (no OAuth)
     * Checks database first, then falls back to API
     */
    public function getVideoDetailsPublic($videoId)
    {
        // 1. Check Database first
        $video = Video::where('youtube_video_id', $videoId)->first();

        // Check if data is fresh
        $bypassCache = config('services.youtube.always_fetch_api_for_multiplier', false);
        
        if (!$bypassCache && $video && $video->updated_at->diffInHours(now()) < $this->videoCacheDurationHours) {
            Log::info('Video found in database', [
                'video_id' => $videoId,
                'source' => 'database'
            ]);
            return [
                'source' => 'database',
                'video' => $video,
                'channel_id' => $video->channel->youtube_channel_id ?? null
            ];
        }

        Log::info('Fetching video from API', [
            'video_id' => $videoId,
            'source' => 'api',
            'apikey' => $this->apiKey,
            'bypass_cache' => $bypassCache
        ]);

        // 2. Fetch from YouTube API
        $response = Http::get(self::API_BASE_URL . '/videos', [
            'part' => 'statistics,snippet,contentDetails',
            'id' => $videoId,
            'key' => $this->apiKey,
        ]);

        if ($response->failed()) {
            Log::error('YouTube API Request Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new \Exception('YouTube API Error: ' . $response->body());
        }

        if (empty($response->json('items'))) {
            Log::info('Video not found in YouTube API', ['video_id' => $videoId]);
            return null;
        }

        $item = $response->json('items')[0];
        $channelId = $item['snippet']['channelId'];

        // 3. Update/Create Channel and Video in DB
        // Prioritize existing User channel if available to share cache
        $channel = Channel::where('youtube_channel_id', $channelId)
            ->orderByRaw('user_id IS NULL') // Prefer User (False=0) over Public (True=1)
            ->first();

        if (!$channel) {
            $channel = Channel::create([
                'youtube_channel_id' => $channelId,
                'user_id' => null
            ]);
        }

        $mappedData = $this->mapVideoDataToDatabase($item);
        $mappedData['channel_id'] = $channel->id;
        // user_id removed from videos table
        $mappedData['updated_at'] = now();

        $video = Video::updateOrCreate(
            ['youtube_video_id' => $videoId],
            $mappedData
        );

        return [
            'source' => 'api',
            'video' => $video,
            'channel_id' => $channelId
        ];
    }

    /**
     * Public method to get Channel Average using API Key (no OAuth)
     * Uses Atomic Lock and Batch Request optimization
     */
    public function getChannelAveragePublic($youtubeChannelId, $excludeVideoId = null)
    {
        // Prioritize existing User channel
        $channel = Channel::where('youtube_channel_id', $youtubeChannelId)
            ->orderByRaw('user_id IS NULL') // Prefer User (False=0) over Public (True=1)
            ->first();

        if (!$channel) {
             $channel = Channel::create([
                'youtube_channel_id' => $youtubeChannelId,
                'user_id' => null
            ]);
        }

        // Check Freshness
        $bypassCache = config('services.youtube.always_fetch_api_for_multiplier', false);

        $isFresh = $channel->average_calculated_at && 
                   $channel->average_calculated_at->diffInHours(now()) < $this->channelCacheDurationHours;

        if (!$bypassCache && $isFresh) {
            Log::info('Channel average found in cache', [
                'channel_id' => $channel->id,
                'average' => $channel->average_views,
                'source' => 'cache',
                'video_ids' => $channel->average_video_ids
            ]);
            return [
                'average' => $channel->average_views,
                'source' => 'cache',
                'video_ids' => $channel->average_video_ids
            ];
        }

        // Atomic Lock
        return Cache::lock("sync_channel_avg_{$channel->id}", 10)->block(5, function () use ($channel, $youtubeChannelId, $excludeVideoId, $bypassCache) {
            
             $channel->refresh();
             if (!$bypassCache && $channel->average_calculated_at && 
                 $channel->average_calculated_at->diffInHours(now()) < $this->channelCacheDurationHours) {
                 return [
                    'average' => $channel->average_views,
                    'source' => 'cache_locked',
                    'video_ids' => $channel->average_video_ids
                ];
            }

            Log::info("Fetching channel median for {$youtubeChannelId} from Public API");
            $uploadsPlaylistId = 'UU' . substr($youtubeChannelId, 2);

            $validVideos = collect();
            $nextPageToken = null;
            $pagesFetched = 0;
            $maxPages = config('services.youtube.max_pages', 5);

            do {
                $pagesFetched++;
                
                // 1. Fetch Playlist Items
                $playlistResponse = Http::get(self::API_BASE_URL . '/playlistItems', [
                    'part' => 'snippet,contentDetails',
                    'playlistId' => $uploadsPlaylistId,
                    'maxResults' => 50,
                    'key' => $this->apiKey,
                    'pageToken' => $nextPageToken,
                ]);

                if ($playlistResponse->failed()) {
                    Log::error("Failed to fetch playlist items: " . $playlistResponse->body());
                    break; 
                }

                $nextPageToken = $playlistResponse->json('nextPageToken');
                $items = collect($playlistResponse->json('items') ?? [])
                    ->reject(fn($item) => $item['contentDetails']['videoId'] === $excludeVideoId);

                if ($items->isEmpty()) {
                    break;
                }

                $videoIds = $items->pluck('contentDetails.videoId')->toArray();

                // 2. Batch Fetch Video Details (Stats + Duration)
                $statsResponse = Http::get(self::API_BASE_URL . '/videos', [
                    'part' => 'statistics,contentDetails,snippet',
                    'id' => implode(',', $videoIds),
                    'key' => $this->apiKey,
                ]);

                $statsItems = collect($statsResponse->json('items') ?? []);

                // 3. Filter Long-Form Videos (>= 60s)
                foreach ($statsItems as $item) {
                    $durationIso = $item['contentDetails']['duration'];
                    try {
                        $interval = new \DateInterval($durationIso);
                        $seconds = ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
                        
                        // Check if Long Form (> 3 minutes)
                        if ($seconds > 180) {
                            $validVideos->push([
                                'id' => $item['id'],
                                'title' => $item['snippet']['title'],
                                'views' => (int) ($item['statistics']['viewCount'] ?? 0),
                                'duration' => round($seconds / 60, 1) . ' minutes',
                            ]);
                        }
                    } catch (\Exception $e) {
                         Log::warning("Failed to parse duration for video {$item['id']}: $durationIso");
                    }

                    // Stop early if we have enough
                    if ($validVideos->count() >= $this->sampleSize) {
                        break 2; // Break both foreach and do-while
                    }
                }

            } while ($nextPageToken && $pagesFetched < $maxPages);

            if ($validVideos->isEmpty()) {
                 return ['average' => 0, 'source' => 'empty_valid_videos'];
            }

            // Log Details (Pretty Print)
            Log::info("Channel Median Calculation Data (Sample: {$validVideos->count()}):\n" . json_encode($validVideos->toArray(), JSON_PRETTY_PRINT));

            // Calculate Trimmed Median
            // 1. Sort by Views (Highest to Lowest)
            $sortedVideos = $validVideos->sortByDesc('views')->values();
            $totalCount = $sortedVideos->count();
            Log::info("Sorted Videos for Calc (Count: {$totalCount}):\n" . json_encode($sortedVideos->toArray(), JSON_PRETTY_PRINT));

            // 2. Trim Top 15%
            // $trimCount = (int) ceil($totalCount * 0.15);
            // $sortedVideos = $sortedVideos->slice($trimCount);
            
            // Log::info("Trimmed Top 15% ({$trimCount} videos). Remaining: {$sortedVideos->count()} videos.");

            // 3. Calculate Median of Remaining
            $viewCounts = $sortedVideos->pluck('views')->sort()->values();
            $count = $viewCounts->count();
            
            if ($count === 0) {
                 $median = 0;
            } else {
                $middle = (int) floor($count / 2);

                if ($count % 2) {
                    $median = $viewCounts[$middle];
                } else {
                    $median = ($viewCounts[$middle - 1] + $viewCounts[$middle]) / 2;
                }
            }
            
            $average = $median; 

            $channel->update([
                'average_views' => (int) round($average),
                'average_video_ids' => $sortedVideos->pluck('id')->toArray(),
                'average_calculated_at' => now(),
            ]);

            return [
                'average' => $average,
                'source' => 'api_refreshed',
                'video_ids' => $sortedVideos->pluck('id')->toArray()
            ];
        });
    }
}

