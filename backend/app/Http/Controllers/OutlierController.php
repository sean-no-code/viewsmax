<?php

namespace App\Http\Controllers;

use App\Models\OutlierVideo;
use App\Services\YouTubeChannelService;
use App\Services\YouTubeSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OutlierController extends Controller
{
    protected $youtube;
    private $searchService;

    public function __construct(YouTubeChannelService $youtube, YouTubeSearchService $searchService)
    {
        $this->youtube = $youtube;
        $this->searchService = $searchService;
    }

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
            'page', 'per_page',
        ]);

        $query = trim($validated['query'] ?? '');

        $result = empty($query)
            ? $this->searchService->browseAll($filters)
            : $this->searchService->getResults($query, $user, $filters);

        return response()->json($result);
    }

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
