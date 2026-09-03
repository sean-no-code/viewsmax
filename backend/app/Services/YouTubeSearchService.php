<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\OutlierVideo;
use App\Models\SearchTerm;
use App\Models\SearchTermsRequest;
use App\Models\TermsDataFetch;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use App\Models\SearchResult;
use App\Jobs\ClassifyOutlierVideoFormatsJob;
use App\Jobs\ProcessYouTubeSearchTermJob;
use App\Traits\LogsYouTubeRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeSearchService
{
    use LogsYouTubeRequests;
    private const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';
    /** ISO-8601 duration → seconds, Postgres only (regex SUBSTRING + ::INTEGER casts). */
    private const DURATION_SECONDS_SQL = "(
        COALESCE(NULLIF(SUBSTRING(videos.duration FROM '(\\d+)H'), '')::INTEGER, 0) * 3600
        + COALESCE(NULLIF(SUBSTRING(videos.duration FROM '(\\d+)M'), '')::INTEGER, 0) * 60
        + COALESCE(NULLIF(SUBSTRING(videos.duration FROM '(\\d+)S'), '')::INTEGER, 0)
    )";

    private string $apiKey;

    public function __construct(
        private YouTubeChannelService $channelService
    ) {
        $this->apiKey = config('services.youtube.key');
    }

    /**
     * Get search results (videos) for a term.
     * Checks cache first, fetches if needed.
     */
    public function getResults(string $term, User $user, array $filters = []): array
    {
        $term = strtolower(trim($term));

        // Only the full phrase's results count. Aggregating each individual
        // word's results (the old behavior) flooded multi-word searches with
        // unrelated videos — "ai app builder" pulled in everything ever
        // fetched for "ai" alone.
        $termModels = SearchTerm::whereIn('term', [$term])->get();

        $page    = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);

        // --- COMPOSITE STATUS LOGIC ---
        // Status is 'done' only if ALL terms are done/failed.
        // If ANY is in_progress or queued, overall status is in_progress.
        // We prioritize 'in_progress' over 'queued'.
        // No term row yet (scrape not even queued) → 'queued'; the DB-first
        // union below still returns already-ingested title matches immediately.

        $statuses = [];
        foreach ($termModels as $tm) {
            $fetch = $tm->termsDataFetch;
            $statuses[] = $fetch ? $fetch->status : TermsDataFetch::STATUS_QUEUED;
        }

        $compositeStatus = TermsDataFetch::STATUS_DONE;
        if ($termModels->isEmpty()) {
            $compositeStatus = TermsDataFetch::STATUS_QUEUED;
        } elseif (in_array(TermsDataFetch::STATUS_IN_PROGRESS, $statuses)) {
            $compositeStatus = TermsDataFetch::STATUS_IN_PROGRESS;
        } elseif (in_array(TermsDataFetch::STATUS_QUEUED, $statuses)) {
             $compositeStatus = TermsDataFetch::STATUS_QUEUED;
             //if we have mix of done and queued, we should say in_progress to keep polling.
             if (in_array(TermsDataFetch::STATUS_DONE, $statuses)) {
                 $compositeStatus = TermsDataFetch::STATUS_IN_PROGRESS;
             }
        } elseif (in_array(TermsDataFetch::STATUS_FAILED, $statuses)) {
            // If all failed? Or just some?
            // If we have some done and some failed, we treat as DONE (partial success).
            if (!in_array(TermsDataFetch::STATUS_DONE, $statuses)) {
                $compositeStatus = TermsDataFetch::STATUS_FAILED;
            }
        }

        // --- AGGREGATE RESULTS ---
        // DB first: everything we've EVER ingested whose title carries the phrase,
        // unioned with this term's scraped SearchResult set. The scrape rebuilds
        // its SearchResults on every re-fetch (delete + reinsert), so counts used
        // to shrink mid-refresh and old finds vanished when YouTube stopped
        // returning them — the title union keeps them, and gives instant results
        // while a first-time scrape is still running.
        $videoIds = SearchResult::whereIn('term_id', $termModels->pluck('id'))
            ->pluck('video_youtube_id')->unique()->values();

        $query = OutlierVideo::query()->with('channel')
            ->join('channels', 'videos.channel_id', '=', 'channels.id')
            ->where(function ($q) use ($videoIds, $term) {
                $this->applyTitlePhraseMatch($q, $term);
                if ($videoIds->isNotEmpty()) {
                    $q->orWhereIn('youtube_video_id', $videoIds->all());
                }
            });

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters['sort_by'] ?? 'score');

        $query->select('videos.*');

        $videos = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data'         => $videos->items(),
            'status'       => $compositeStatus,
            'current_page' => $videos->currentPage(),
            'per_page'     => $videos->perPage(),
            'total'        => $videos->total(),
            'last_page'    => $videos->lastPage(),
        ];
    }

    /**
     * Browse all outlier videos (no search query).
     * Applies the same filters and sorting as getResults.
     */
    public function browseAll(array $filters = []): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = min(max($perPage, 1), 100);

        $query = OutlierVideo::query()->with('channel')
            ->join('channels', 'videos.channel_id', '=', 'channels.id');

        // Instagram videos have no views and no views-based outlier_score, so the
        // score gate would exclude them all — skip it for that platform. With no
        // platform filter (blended browse) the gate applies to non-IG rows only.
        // Manually-added videos (pasted URLs) are deliberate — never score-gated.
        // Featured (admin-curated) requests skip the gate entirely: curation wins.
        $platform = $filters['platform'] ?? null;
        if ($platform !== 'instagram' && empty($filters['featured'])) {
            $minScore = $filters['min_score'] ?? OutlierVideo::minScore();
            if ($platform === null) {
                $query->where(function ($q) use ($minScore) {
                    $q->where('videos.platform', 'instagram')
                        ->orWhere('outlier_score', '>=', $minScore)
                        ->orWhere('videos.manually_added', true);
                });
            } else {
                $query->where(function ($q) use ($minScore) {
                    $q->where('outlier_score', '>=', $minScore)
                        ->orWhere('videos.manually_added', true);
                });
            }
        }

        $this->applyFilters($query, $filters, true);
        $this->applySorting($query, $filters['sort_by'] ?? 'recent');

        $query->select('videos.*');

        $videos = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data'         => $videos->items(),
            'status'       => 'done',
            'current_page' => $videos->currentPage(),
            'per_page'     => $videos->perPage(),
            'total'        => $videos->total(),
            'last_page'    => $videos->lastPage(),
        ];
    }

    /**
     * Whole-phrase title match for keyword search. Postgres gets word boundaries
     * (\m…\M — "seo" can't match "Seoul"); other drivers (SQLite tests) fall back
     * to a plain substring LIKE.
     */
    private function applyTitlePhraseMatch($q, string $term): void
    {
        if ($q->getConnection()->getDriverName() === 'pgsql') {
            $q->whereRaw("videos.title ~* ('\\m' || ? || '\\M')", [preg_quote($term)]);
        } else {
            $q->whereRaw('LOWER(videos.title) LIKE ?', ['%'.$term.'%']);
        }
    }

    /**
     * Apply shared filters to an OutlierVideo query.
     *
     * @param bool $skipMinScore  When true, min_score filter is skipped
     *                            (browseAll handles it separately with a default).
     */
    private function applyFilters($query, array $filters, bool $skipMinScore = false): void
    {
        if (!$skipMinScore && isset($filters['min_score'])) {
            $query->where('outlier_score', '>=', $filters['min_score']);
        }
        if (isset($filters['min_views'])) {
            $query->where('videos.views', '>=', $filters['min_views']);
        }
        if (isset($filters['max_views'])) {
            $query->where('videos.views', '<=', $filters['max_views']);
        }
        if (isset($filters['min_subs'])) {
            $query->where('channels.subscriber_count', '>=', $filters['min_subs']);
        }
        if (isset($filters['max_subs'])) {
            $query->where('channels.subscriber_count', '<=', $filters['max_subs']);
        }
        if (isset($filters['published_after'])) {
            $query->where('videos.published_at', '>=', $filters['published_after']);
        }
        if (isset($filters['published_before'])) {
            $query->where('videos.published_at', '<=', $filters['published_before']);
        }
        if (isset($filters['platform'])) {
            $query->where('videos.platform', $filters['platform']);
        }
        if (! empty($filters['featured'])) {
            $query->where('videos.featured', true);
        }
        if (! empty($filters['channels'])) {
            $query->whereIn('channels.youtube_channel_id', (array) $filters['channels']);
        }
        if (! empty($filters['countries'])) {
            // Channel country is stored uppercase (ISO 3166-1 alpha-2); NULL-country
            // channels (incl. all TikTok/Instagram) don't match while this is active.
            $query->whereIn('channels.country', array_map('strtoupper', (array) $filters['countries']));
        }
        if (isset($filters['duration_type'])) {
            $wantShorts = $filters['duration_type'] === OutlierVideo::DURATION_TYPE_SHORTS;
            // Classified rows are authoritative (is_short comes from YouTube's own
            // /shorts/ URL, or the platform rule for TikTok/Instagram). Rows not yet
            // classified fall back to the legacy platform + duration rule.
            $query->where(function ($q) use ($wantShorts) {
                $q->whereRaw('videos.is_short IS '.($wantShorts ? 'TRUE' : 'FALSE'))
                    ->orWhere(function ($qq) use ($wantShorts) {
                        $qq->whereNull('videos.is_short');
                        $this->applyDurationFallback($qq, $wantShorts);
                    });
            });
        }
    }

    /**
     * Legacy rule for rows without a classification: TikTok/Instagram are shorts;
     * YouTube goes by duration (≤ SHORTS_MAX_SECONDS = short; null, > max, or the
     * 0s of live streams/premieres = long). The duration parsing is Postgres-only,
     * so on other drivers (SQLite tests) unclassified YouTube rows count as long.
     */
    private function applyDurationFallback($q, bool $wantShorts): void
    {
        $maxSeconds = OutlierVideo::SHORTS_MAX_SECONDS;
        $alwaysShort = ['tiktok', 'instagram'];

        if ($wantShorts) {
            $q->where(function ($w) use ($alwaysShort, $maxSeconds) {
                $w->whereIn('videos.platform', $alwaysShort);
                if ($this->supportsDurationSql($w)) {
                    $w->orWhere(function ($yt) use ($maxSeconds) {
                        $yt->where('videos.platform', 'youtube')
                            ->whereNotNull('videos.duration')
                            ->whereRaw(self::DURATION_SECONDS_SQL.' between 1 and ?', [$maxSeconds]);
                    });
                }
            });

            return;
        }

        $q->where('videos.platform', 'youtube');
        if ($this->supportsDurationSql($q)) {
            $q->where(function ($yt) use ($maxSeconds) {
                $yt->whereNull('videos.duration')
                    ->orWhereRaw(self::DURATION_SECONDS_SQL.' > ?', [$maxSeconds])
                    ->orWhereRaw(self::DURATION_SECONDS_SQL.' < 1'); // P0D live streams / premieres
            });
        }
    }

    private function supportsDurationSql($q): bool
    {
        return $q->getConnection()->getDriverName() === 'pgsql';
    }

    /**
     * Apply shared sorting to an OutlierVideo query.
     */
    private function applySorting($query, string $sortBy): void
    {
        // NULLS LAST: in a blended (all-platform) feed Instagram rows have null
        // score/views, and Postgres would otherwise float them to the top on DESC.
        switch ($sortBy) {
            case 'date':
            case 'recent':
                $query->orderByRaw('DATE(videos.published_at) DESC')
                    ->orderByRaw('videos.outlier_score DESC NULLS LAST');
                break;
            case 'views':
                $query->orderByRaw('videos.views DESC NULLS LAST');
                break;
            case 'score':
            default:
                $query->orderByRaw('videos.outlier_score DESC NULLS LAST');
                break;
        }
    }

    /**
     * Search for a term, fetch data if needed.
     * Word splitting (when not exact match) is handled inside fetchAndProcess.
     */
    public function search(string $term, User $user, bool $exactMatch = false): void
    {
        $term = strtolower(trim($term));
        if (empty($term) || !$this->isValidTerm($term)) {
            Log::info("Skipping invalid search term: {$term}");
            return;
        }

        // 1. Find or create SearchTerm
        $searchTerm = SearchTerm::firstOrCreate(['term' => $term]);

        // 2. Record the request
        SearchTermsRequest::create([
            'user_id' => $user->id,
            'term_id' => $searchTerm->id,
        ]);

        // 3. Check if we need to fetch data
        if ($this->shouldFetch($searchTerm)) {
            Log::info("Dispatching async job for term: {$term}");

            TermsDataFetch::updateOrCreate(
                ['term_id' => $searchTerm->id],
                ['status' => TermsDataFetch::STATUS_QUEUED]
            );

            ProcessYouTubeSearchTermJob::dispatch($term, $user, $exactMatch);
        }
    }

    private function shouldFetch(SearchTerm $searchTerm): bool
    {
        $lastFetch = $searchTerm->termsDataFetch;
        $term = $searchTerm->term;

        if (!$lastFetch) {
            Log::info("shouldFetch [{$term}]: No fetch record found → will fetch");
            return true;
        }

        $status = $lastFetch->status;

        if ($status === TermsDataFetch::STATUS_QUEUED || $status === TermsDataFetch::STATUS_FAILED) {
            Log::info("shouldFetch [{$term}]: Status is '{$status}' → will fetch");
            return true;
        }

        if ($status === TermsDataFetch::STATUS_IN_PROGRESS) {
            $minutesSinceUpdate = $lastFetch->updated_at ? $lastFetch->updated_at->diffInMinutes(now()) : PHP_INT_MAX;
            if ($minutesSinceUpdate > 15) {
                Log::warning("shouldFetch [{$term}]: Status is 'in_progress' but stuck for {$minutesSinceUpdate}m → treating as crashed, will re-fetch");
                return true;
            }
            Log::info("shouldFetch [{$term}]: Status is 'in_progress' (started {$minutesSinceUpdate}m ago) → skipping");
            return false;
        }

        if ($status === TermsDataFetch::STATUS_DONE) {
            $periodDays  = (int) config('services.youtube.fetch_period_days', 2);
            $daysSince   = $lastFetch->fetched_at->diffInDays(now());
            $shouldFetch = $daysSince >= $periodDays;
            Log::info("shouldFetch [{$term}]: Status is 'done' — {$daysSince}d since last fetch, period is {$periodDays}d → " . ($shouldFetch ? 'will fetch' : 'skipping'));
            return $shouldFetch;
        }

        Log::warning("shouldFetch [{$term}]: Unknown status '{$status}' → skipping");
        return false;
    }


    public function fetchAndProcess(string $term, SearchTerm $searchTerm, User $user, bool $exactMatch = false): void
    {
        Log::info("Fetching data for term: {$term}");

        $fetch = $searchTerm->termsDataFetch;
        $crashedJob = false;

        if ($fetch && $fetch->status === TermsDataFetch::STATUS_IN_PROGRESS) {
            $minutesSinceUpdate = $fetch->updated_at ? $fetch->updated_at->diffInMinutes(now()) : PHP_INT_MAX;
            if ($minutesSinceUpdate < 10) {
                Log::info("Term '{$term}' is currently IN_PROGRESS (started {$minutesSinceUpdate}m ago). Skipping duplicate job.");
                return;
            }
            // Job has been IN_PROGRESS for > 10 minutes — assumed crashed. Force re-run.
            Log::warning("Term '{$term}' has been IN_PROGRESS for {$minutesSinceUpdate}m — assumed crashed. Forcing re-run.");
            $crashedJob = true;
        }

        if (!$crashedJob && !$this->shouldFetch($searchTerm)) {
            Log::info("Term '{$term}' was already fetched recently. Skipping redundant fetch.");
            return;
        }

        TermsDataFetch::updateOrCreate(
            ['term_id' => $searchTerm->id],
            ['status' => TermsDataFetch::STATUS_IN_PROGRESS]
        );

        YouTubeCreditTracker::reset();

        try {
            Log::info("Now searching term \"{$term}\"");

            $maxResults = config('services.youtube.max_search_results', 50);

            // Use intitle: operator for YouTube title matching
            $quotedTerm = 'intitle:"' . $term . '"';

            $this->logYouTubeRequest('/search', ['q' => $quotedTerm, 'maxResults' => $maxResults]);

            $videosData = $this->searchVideosWithApiKey($quotedTerm, $maxResults);

            if (empty($videosData)) {
                Log::info("found 0 videos");
                TermsDataFetch::updateOrCreate(
                    ['term_id' => $searchTerm->id],
                    ['status' => TermsDataFetch::STATUS_DONE, 'fetched_at' => now()]
                );
                return;
            }

            $count = count($videosData);
            Log::info("found {$count} amount of videos");

            SearchResult::where('term_id', $searchTerm->id)->delete();

            Log::info("Getting outliers for each video");

            // Collect all unique channel IDs from the results
            $channelIds = collect($videosData)
                ->pluck('snippet.channelId')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            // Batch-fetch all channel details in one API call (up to 50 per request)
            Log::info('Batch fetching channel details for ' . count($channelIds) . ' unique channels');
            $channelsMap = $this->channelService->getChannelsBatch($channelIds);

            $savedIds = [];
            foreach ($videosData as $videoItem) {
                $idData  = $videoItem['id'] ?? null;
                $videoId = is_array($idData) ? ($idData['videoId'] ?? null) : $idData;

                if (!$videoId) continue;

                $videoDbData = $this->channelService->mapVideoDataToDatabase($videoItem);
                $channelId   = $videoItem['snippet']['channelId'] ?? null;

                // Pass pre-fetched channel data — skips the individual /channels API call
                $saved = $this->processOutlierVideo($videoDbData, $channelId, $channelsMap[$channelId] ?? null);

                if ($saved) {
                    $savedIds[] = $videoId;
                    SearchResult::create([
                        'term_id'          => $searchTerm->id,
                        'video_youtube_id' => $videoId,
                    ]);
                }
            }

            Log::info("finished outliers for each video");

            $totalCredits = YouTubeCreditTracker::get();
            Log::info("Overall estimated youtube api credit used: {$totalCredits}");

            TermsDataFetch::updateOrCreate(
                ['term_id' => $searchTerm->id],
                ['status' => TermsDataFetch::STATUS_DONE, 'fetched_at' => now()]
            );

            // Shorts/long classification runs as a follow-up job (one /shorts/ probe
            // per saved video) so it never eats into this job's time budget.
            if ($savedIds !== []) {
                ClassifyOutlierVideoFormatsJob::dispatch($savedIds);
            }

            // Dispatch sub-term jobs for multi-word terms (only when not exact match)
            if (!$exactMatch) {
                $words = explode(' ', $term);
                if (count($words) > 1) {
                    foreach ($words as $word) {
                        $word = trim($word);
                        if (strtolower($word) !== strtolower($term) && $this->isValidTerm($word)) {
                            Log::info("now searching {$word} from {$term}");
                            ProcessYouTubeSearchTermJob::dispatch($word, $user);
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error("Error processing term {$term}: " . $e->getMessage());
            TermsDataFetch::updateOrCreate(
                ['term_id' => $searchTerm->id],
                ['status' => TermsDataFetch::STATUS_FAILED]
            );
        }
    }

    private function isValidTerm(string $term): bool
    {
        // Must be at least 2 chars and contain at least one alphanumeric character
        // This prevents searches like "'", ".", "?", or single letters like "a" which are too broad/wasteful
        if (mb_strlen($term) < 2) {
            return false;
        }

        if (!preg_match('/[a-zA-Z0-9]/', $term)) {
            return false;
        }

        return true;
    }

    /**
     * Search videos using API key only (no OAuth).
     */
    private function searchVideosWithApiKey(string $query, int $maxResults): array
    {
        // Discover both long-form and short-form (Shorts) candidates. YouTube's
        // videoDuration=short means < 4 min (broader than true Shorts); the ≤180s
        // Shorts classification is applied at query time in applyFilters().
        $videoIds = array_merge(
            $this->searchVideoIds($query, $maxResults, 'long'),
            $this->searchVideoIds($query, $maxResults, 'short'),
        );
        $videoIds = array_values(array_unique($videoIds));

        if (empty($videoIds)) {
            return [];
        }

        return $this->channelService->getVideoDetailsWithApiKey($videoIds);
    }

    /**
     * Run search.list for the given duration bucket, following page tokens up to
     * services.youtube.search_pages pages (each page = 100 quota units). One page
     * caps discovery at ~50 candidates per bucket, which is why result sets felt
     * thin next to vidIQ's index.
     */
    private function searchVideoIds(string $query, int $maxResults, string $videoDuration): array
    {
        $pages = max(1, (int) config('services.youtube.search_pages', 2));
        $videoIds = [];
        $pageToken = null;

        for ($i = 0; $i < $pages; $i++) {
            $params = [
                'part' => 'snippet',
                'q' => $query,
                'type' => 'video',
                'videoDuration' => $videoDuration,
                'order' => 'viewCount',
                'maxResults' => $maxResults,
                'key' => $this->apiKey,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::get(self::API_BASE_URL . '/search', $params);

            if (!$response->successful()) {
                // First page failing is a real failure; a broken deeper page
                // shouldn't throw away what earlier pages already found.
                if ($i === 0) {
                    throw new \Exception('Failed to search videos: ' . $response->body());
                }
                Log::warning("YouTube search page ".($i + 1)." failed for \"{$query}\" — keeping earlier pages", ['status' => $response->status()]);
                break;
            }

            foreach ($response->json()['items'] ?? [] as $item) {
                $vId = $item['id']['videoId'] ?? ($item['id'] ?? null);
                if (is_array($vId)) {
                    $vId = $vId['videoId'] ?? null;
                }
                if ($vId && is_string($vId)) {
                    $videoIds[] = $vId;
                }
            }

            $pageToken = $response->json('nextPageToken');
            if (!$pageToken) {
                break;
            }
        }

        return $videoIds;
    }



    private function processOutlierVideo(array $videoDbData, string $channelId, ?array $prefetchedChannelData = null): bool
    {
        $videoId = $videoDbData['youtube_video_id'] ?? 'unknown';
        Log::info("Processing outlier video [{$videoId}] for channel [{$channelId}]");

        // 1. Get Outlier Channel Average (uses pre-fetched data if available — skips /channels API call)
        $averageData    = $this->channelService->getOutlierChannelAverage($channelId, $videoId, $prefetchedChannelData);
        $outlierChannel = $averageData['channel'];

        // 2. Calculate score via shared helper (single source of truth for formula)
        $videoViews = (int) ($videoDbData['view_count'] ?? 0);
        $score      = $this->channelService->computeOutlierScore($videoViews, $averageData['average']);

        // 3. Skip if below minimum outlier score threshold
        if ($score < OutlierVideo::minScore()) {
            Log::info("Skipping outlier video [{$videoId}] — score {$score} below minimum " . OutlierVideo::minScore());
            return false;
        }

        // 4. Normalize view_count → views key for saveOutlierVideo
        $videoDbData['views'] = $videoViews;

        // 5. Persist via shared helper (single source of truth for upsert)
        $this->channelService->saveOutlierVideo($videoDbData, $outlierChannel, $score);
        return true;
    }

    private function updateFetchLog(SearchTerm $searchTerm): void
    {
        TermsDataFetch::updateOrCreate(
            ['term_id' => $searchTerm->id],
            ['fetched_at' => now()]
        );
    }
}
