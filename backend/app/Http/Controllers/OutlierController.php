<?php

namespace App\Http\Controllers;

use App\Jobs\IngestOutlierByUrlJob;
use App\Models\OutlierBreakdown;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Services\CaptApiOutlierService;
use App\Services\YouTubeChannelService;
use App\Services\YouTubeSearchService;
use App\Support\UserSafeError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @group Outliers
 *
 * Outlier videos: content that massively over-performed its channel's average
 * (`outlier_score` = views ÷ channel average views) across YouTube, TikTok and
 * Instagram. Browse the shared database, pull in specific URLs, and get AI
 * breakdowns of why a video worked.
 */
class OutlierController extends Controller
{
    protected $youtube;
    private $searchService;
    private CaptApiOutlierService $captApiOutliers;

    public function __construct(YouTubeChannelService $youtube, YouTubeSearchService $searchService, CaptApiOutlierService $captApiOutliers)
    {
        $this->youtube = $youtube;
        $this->searchService = $searchService;
        $this->captApiOutliers = $captApiOutliers;
    }

    /**
     * List outlier channels
     *
     * Distinct channels in the outlier database (for the `channels` filter of the browse endpoint).
     */
    public function channels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'nullable|string|in:youtube,tiktok,instagram',
            'q' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = OutlierChannel::query();

        if (! empty($validated['platform'])) {
            $query->where('platform', $validated['platform']);
        }

        // Match the display name, the @handle (as typed, "@" optional), or the
        // platform-native id — an IG/TikTok creator is usually known by handle.
        $q = ltrim(trim((string) ($validated['q'] ?? '')), '@');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('channel_name', 'like', "%{$q}%")
                    ->orWhere('handle', 'like', "%{$q}%")
                    ->orWhere('youtube_channel_id', 'like', "%{$q}%");
            });
        }

        $channels = $query->orderByDesc('subscriber_count')
            ->limit($validated['limit'] ?? 50)
            ->get(['youtube_channel_id', 'channel_name', 'profile_image_url', 'platform', 'subscriber_count']);

        return response()->json([
            'data' => $channels->map(fn ($c) => [
                'id' => $c->youtube_channel_id,
                'name' => $c->channel_name,
                'avatar' => $c->profile_image_url,
                'platform' => $c->platform,
                'subscriber_count' => $c->subscriber_count,
            ]),
        ]);
    }

    /**
     * Fetch an outlier by URL
     *
     * Ingest a single video by URL so it can be analysed. Known videos return
     * immediately; otherwise ingestion is queued (HTTP 202, `queued: true`) —
     * poll the show endpoint with the returned platform + video_id. YouTube goes
     * through the YouTube API; TikTok/Instagram go through CaptAPI (no
     * channel-listing endpoint there, so those are pulled one URL at a time).
     */
    public function fetchByUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'url' => 'required|url',
        ]);
        $platform = $validated['platform'];
        $url = $validated['url'];

        $videoId = $platform === 'youtube'
            ? YouTubeChannelService::extractVideoId($url)
            : CaptApiOutlierService::extractVideoId($platform, $url);

        // TikTok share links (vm.tiktok.com/…) carry no id and CaptAPI rejects
        // them — resolve the redirect to the canonical URL first.
        if ($videoId === null && $platform === 'tiktok') {
            $resolved = CaptApiOutlierService::resolveTiktokShortLink($url);
            if ($resolved !== null) {
                $url = $resolved;
                $videoId = CaptApiOutlierService::extractVideoId('tiktok', $resolved);
            }
        }

        if ($videoId !== null) {
            $existing = OutlierVideo::with('channel')
                ->where('platform', $platform)->where('youtube_video_id', $videoId)->first();
            if ($existing) {
                return response()->json(['data' => $existing, 'queued' => false, 'video_id' => $videoId, 'platform' => $platform]);
            }

            // Fresh attempt — a stale failed row would read as "download failed" on the page.
            OutlierBreakdown::where('platform', $platform)->where('video_id', $videoId)
                ->where('status', OutlierBreakdown::STATUS_FAILED)->delete();

            // Slow providers (Instagram scrapes run minutes) make sync ingest hostile;
            // queue it and let the breakdown page's spinner poll the video in.
            IngestOutlierByUrlJob::dispatch($platform, $url, $videoId);

            return response()->json(['queued' => true, 'video_id' => $videoId, 'platform' => $platform], 202);
        }

        // No id in the URL (vanity/short links): resolve synchronously via the provider.
        try {
            $video = $platform === 'youtube'
                ? $this->youtube->ingestOutlierByUrl($url)
                : $this->captApiOutliers->fetchAndStore($platform, $url);
        } catch (ConnectionException $e) {
            Log::warning('Outlier fetchByUrl timed out', ['platform' => $platform]);

            return response()->json(['error' => UserSafeError::message($e, '')], 504);
        } catch (\Throwable $e) {
            // Only exact-class service exceptions carry user-safe messages —
            // subclasses (QueryException, ConnectionException) leak SQL/cURL text.
            if (UserSafeError::isSafe($e)) {
                return response()->json(['error' => UserSafeError::message($e, 'Could not fetch that video.')], 422);
            }

            Log::error('Outlier fetchByUrl failed', ['platform' => $platform, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Could not fetch that video. Check the URL and try again.'], 502);
        }

        return response()->json(['data' => $video->load('channel'), 'queued' => false, 'video_id' => $video->youtube_video_id, 'platform' => $platform]);
    }

    /**
     * Browse outliers
     *
     * Paginated outlier videos. Without `query` this is the curated feed; with
     * `query` it returns title matches already in the database plus a `status`
     * (queued / in_progress / done) for the background scrape of that term —
     * start one with the search endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'nullable|string',
            'min_score' => 'nullable|numeric|min:0',
            'min_views' => 'nullable|numeric|min:0',
            'max_views' => 'nullable|numeric|min:0',
            'min_subs' => 'nullable|numeric|min:0',
            'max_subs' => 'nullable|numeric|min:0',
            'published_before' => 'nullable|date',
            'published_after' => 'nullable|date',
            'sort_by' => 'nullable|string|in:score,date,views,recent',
            'keyword_match' => 'nullable|string',
            'duration_type' => 'nullable|string|in:' . OutlierVideo::DURATION_TYPE_LONG . ',' . OutlierVideo::DURATION_TYPE_SHORTS,
            'featured' => 'nullable|boolean',
            'platform' => 'nullable|string|in:youtube,tiktok,instagram',
            'channels' => 'nullable|array',
            'channels.*' => 'string',
            'countries' => 'nullable|array',
            'countries.*' => 'string|size:2|alpha',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        
        $user = $request->user();
        
        if (!$user) {
             return response()->json(['error' => 'Unauthorized'], 401);
        }

        $filters = $request->only([
            'min_score', 
            'min_views', 'max_views', 
            'min_subs', 'max_subs', 
            'published_before', 'published_after',
            'sort_by',
            'keyword_match',
            'duration_type',
            'featured',
            'platform',
            'channels',
            'countries',
            'page', 'per_page',
        ]);

        $query = trim($validated['query'] ?? '');

        $result = empty($query)
            ? $this->searchService->browseAll($filters)
            : $this->searchService->getResults($query, $user, $filters);

        return response()->json($result);
    }

    /**
     * Re-fetch a video's expiring CDN media URL (Instagram) so the breakdown
     * page can keep playing it natively. Only hits the provider when the stored
     * URL is missing or expired.
     */
    public function refreshMedia(Request $request, string $platform, string $videoId): JsonResponse
    {
        $video = OutlierVideo::with('channel')
            ->where('platform', $platform)
            ->where('youtube_video_id', $videoId)
            ->first();

        if (! $video) {
            return response()->json(['success' => false, 'message' => 'Video not found'], 404);
        }

        // Re-fetch when the media URL is missing/expired OR the row was ingested from
        // a sparse provider response (no views) — fills in views/score/followers.
        $fresh = $video->video_url && $video->video_url_expires_at && $video->video_url_expires_at->isFuture()
            && $video->views !== null;
        if (! $fresh) {
            try {
                $video = $this->captApiOutliers
                    ->fetchAndStore($platform, \App\Jobs\GenerateOutlierBreakdownJob::nativeUrl($platform, $videoId))
                    ->load('channel');
            } catch (\Throwable $e) {
                return response()->json(['error' => UserSafeError::message($e, 'Could not refresh the video.')], 422);
            }
        }

        return response()->json(['data' => $video]);
    }

    /** Admin: toggle a video in/out of the featured (default-feed) list. */
    public function toggleFeature(Request $request, string $platform, string $videoId): JsonResponse
    {
        $video = OutlierVideo::where('platform', $platform)
            ->where('youtube_video_id', $videoId)
            ->first();

        if (! $video) {
            return response()->json(['success' => false, 'message' => 'Video not found'], 404);
        }

        $video->featured = ! $video->featured;
        $video->save();

        return response()->json(['data' => ['featured' => $video->featured]]);
    }

    /**
     * Get an outlier
     *
     * A single outlier video with its channel.
     *
     * @urlParam platform string required youtube, tiktok, or instagram. Example: youtube
     * @urlParam videoId string required The platform's video id. Example: dQw4w9WgXcQ
     */
    public function show(Request $request, string $platform, string $videoId): JsonResponse
    {
        $video = OutlierVideo::with('channel')
            ->where('platform', $platform)
            ->where('youtube_video_id', $videoId)
            ->first();

        if (! $video) {
            return response()->json(['success' => false, 'message' => 'Video not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $video]);
    }

    /**
     * Start an outlier search
     *
     * Queue a background scrape for a keyword/topic. Poll the browse endpoint
     * with the same `query` until its `status` is `done`.
     *
     * @bodyParam term string required Keyword or topic. Example: faceless youtube automation
     * @bodyParam exact_match boolean Match the whole phrase only. Example: false
     */
    public function search(Request $request): JsonResponse
    {
        $term = $request->input('term');
        $exactMatch = (bool) $request->input('exact_match', false);

        if (!$term) {
             return response()->json(['error' => 'Term is required'], 400);
        }

        // Trigger the search job(s) — respects exact_match flag
        $this->searchService->search($term, $request->user(), $exactMatch);

        return response()->json([
            'message' => 'Search started',
            'status' => 'queued',
            'term' => $term
        ]);
    }

    public function getMultiplier(Request $request, string $videoId): JsonResponse
    {
        // Sanitize Video ID (remove query params like &t=1s)
        $videoId = strtok(strtok($videoId, '&'), '?');
        
        Log::info('getMultiplier', ['videoId' => $videoId]);
        try {
            $result = $this->youtube->calculateMultiplier($videoId);
        } catch (\Exception $e) {
            Log::error('getMultiplier error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'YouTube API Error'], 502);
        }

        if (isset($result['error'])) {
            return response()->json(['error' => ucfirst(str_replace('_', ' ', $result['error']))], 404);
        }

        $video = $result['video'];

        return response()->json([
            'video_id' => $videoId,
            'title' => $video->title,
            'video_views' => $video->views,
            'video_views_fmt' => number_format($video->views),
            'channel_avg' => $result['average'],
            'channel_avg_fmt' => number_format($result['average']),
            'multiplier' => round($result['multiplier'], 2),
            'multiplier_label' => round($result['multiplier'], 2) . 'x',
            'debug' => [
                'video_source' => $result['video_source'],
                'avg_source' => $result['avg_source'],
                'avg_video_ids' => $result['channel']->average_video_ids ?? [],
            ]
        ]);
    }
}
