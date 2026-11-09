<?php

namespace Tests\Feature;

use App\Jobs\ClassifyOutlierVideoFormatsJob;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\SearchResult;
use App\Models\SearchTerm;
use App\Models\TermsDataFetch;
use App\Models\User;
use App\Services\YouTubeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** First coverage of the keyword-search ingest path + the format-classification follow-up job. */
class OutlierSearchIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.youtube.key', 'yt_test_key');
        config()->set('services.youtube.min_outlier_score', 0); // save everything the search returns
    }

    private function videoItem(string $id, int $views): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'title' => "Video {$id}", 'channelId' => 'UCsearch', 'publishedAt' => '2026-08-01T10:00:00Z',
                'thumbnails' => ['medium' => ['url' => 'https://example.com/m.jpg'], 'default' => ['url' => 'https://example.com/d.jpg']],
            ],
            'statistics' => ['viewCount' => (string) $views, 'likeCount' => '1', 'commentCount' => '1'],
            'contentDetails' => ['duration' => 'PT2M5S'],
        ];
    }

    private function fakeYouTube(): void
    {
        Http::fake([
            '*/youtube/v3/search*' => Http::response(['items' => [
                ['id' => ['videoId' => 'vidA']], ['id' => ['videoId' => 'vidB']],
            ]], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => [$this->videoItem('vidA', 50000), $this->videoItem('vidB', 40000)]], 200),
            '*/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCsearch',
                'snippet' => ['title' => 'Search Channel', 'thumbnails' => ['high' => ['url' => 'https://example.com/av.jpg']]],
                'statistics' => ['subscriberCount' => '9000', 'videoCount' => '12'],
            ]]], 200),
            '*/youtube/v3/playlistItems*' => Http::response(['items' => []], 200),
        ]);
    }

    /**
     * search_terms.term is varchar(255). A pasted paragraph (Sentry PHP-LARAVEL-5A:
     * a whole breakdown dropped into the search box) used to reach the insert
     * and 500. The REST endpoint now refuses it like the MCP tool does.
     */
    public function test_a_search_term_over_the_limit_is_refused_before_anything_is_stored(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $term = str_repeat('a', YouTubeSearchService::MAX_TERM_LENGTH + 1);

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->postJson('/api/outliers/search', ['term' => $term])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, SearchTerm::count());
        Queue::assertNothingPushed();
    }

    public function test_a_search_term_at_the_limit_is_accepted(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $term = str_repeat('a', YouTubeSearchService::MAX_TERM_LENGTH);

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->postJson('/api/outliers/search', ['term' => $term, 'exact_match' => true])
            ->assertOk();

        $this->assertSame(1, SearchTerm::where('term', $term)->count());
    }

    /** The service is the last stop before the insert, so it refuses an over-long term on its own too. */
    public function test_the_service_skips_an_over_long_term_instead_of_inserting_it(): void
    {
        Queue::fake();
        $term = str_repeat('word ', 60); // 300 chars

        $this->assertNull(app(YouTubeSearchService::class)->search($term, User::factory()->create()));

        $this->assertSame(0, SearchTerm::count());
        Queue::assertNothingPushed();
    }

    public function test_search_ingest_dispatches_format_classification_for_saved_videos(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $user = User::factory()->create();
        $term = SearchTerm::create(['term' => 'ai']);
        TermsDataFetch::create(['term_id' => $term->id, 'status' => TermsDataFetch::STATUS_QUEUED]);

        DB::connection('outlier_db')->enableQueryLog();

        app(YouTubeSearchService::class)->fetchAndProcess('ai', $term, $user);

        $this->assertSame(2, OutlierVideo::count());
        $this->assertSame(['vidA', 'vidB'], SearchResult::where('term_id', $term->id)->orderBy('video_youtube_id')->pluck('video_youtube_id')->all());
        // One insert for all saved ids, not one per video (Sentry flagged the per-row create as an N+1).
        $inserts = collect(DB::connection('outlier_db')->getQueryLog())
            ->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'insert into "search_results"'));
        $this->assertCount(1, $inserts);
        // Classification is NOT probed inline (keeps the search job fast) — it's queued for the saved ids.
        Queue::assertPushed(ClassifyOutlierVideoFormatsJob::class, function (ClassifyOutlierVideoFormatsJob $job) {
            return collect($job->videoIds)->sort()->values()->all() === ['vidA', 'vidB'];
        });
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'youtube.com/shorts/'));
    }

    private function makeYoutubeVideo(string $id, array $extra = []): OutlierVideo
    {
        $channel = OutlierChannel::firstOrCreate(['platform' => 'youtube', 'youtube_channel_id' => 'UCx'], ['channel_name' => 'Ch']);

        return OutlierVideo::create(array_merge([
            'platform' => 'youtube', 'channel_id' => $channel->id, 'youtube_video_id' => $id,
            'title' => $id, 'thumbnail_url' => 'x', 'published_at' => now(),
        ], $extra));
    }

    public function test_search_follows_page_tokens_for_deeper_result_sets(): void
    {
        Queue::fake();
        config()->set('services.youtube.search_pages', 2);
        Http::fake([
            '*/youtube/v3/search*' => function ($request) {
                // Page 1 hands back a token; page 2 ends the pagination.
                return str_contains($request->url(), 'pageToken')
                    ? Http::response(['items' => [['id' => ['videoId' => 'vidC']], ['id' => ['videoId' => 'vidD']]]], 200)
                    : Http::response(['items' => [['id' => ['videoId' => 'vidA']], ['id' => ['videoId' => 'vidB']]], 'nextPageToken' => 'tok2'], 200);
            },
            '*/youtube/v3/videos*' => Http::response(['items' => [
                $this->videoItem('vidA', 50000), $this->videoItem('vidB', 40000),
                $this->videoItem('vidC', 30000), $this->videoItem('vidD', 20000),
            ]], 200),
            '*/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCsearch',
                'snippet' => ['title' => 'Search Channel', 'thumbnails' => ['high' => ['url' => 'https://example.com/av.jpg']]],
                'statistics' => ['subscriberCount' => '9000', 'videoCount' => '12'],
            ]]], 200),
            '*/youtube/v3/playlistItems*' => Http::response(['items' => []], 200),
        ]);
        $user = User::factory()->create();
        $term = SearchTerm::create(['term' => 'seo']);
        TermsDataFetch::create(['term_id' => $term->id, 'status' => TermsDataFetch::STATUS_QUEUED]);

        app(YouTubeSearchService::class)->fetchAndProcess('seo', $term, $user);

        // Both pages ingested, for each of the two duration buckets (long + short).
        $this->assertSame(4, OutlierVideo::count());
        $searchCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/youtube/v3/search'));
        $this->assertCount(4, $searchCalls); // 2 buckets × 2 pages
    }

    public function test_classify_job_classifies_only_unclassified_rows_from_their_duration(): void
    {
        Http::fake();
        $done = $this->makeYoutubeVideo('alreadyshort', ['is_short' => true, 'format_checked_at' => now(), 'duration' => 'PT10M']);
        $short = $this->makeYoutubeVideo('newshort', ['duration' => 'PT59S']);
        $long = $this->makeYoutubeVideo('newlong', ['duration' => 'PT3M1S']);
        $noDuration = $this->makeYoutubeVideo('nodur');

        app()->call([new ClassifyOutlierVideoFormatsJob(['alreadyshort', 'newshort', 'newlong', 'nodur']), 'handle']);

        Http::assertNothingSent(); // the duration rule needs no request
        $this->assertTrue($done->fresh()->is_short); // classified rows are never re-decided
        $this->assertTrue($short->fresh()->is_short);
        $this->assertFalse($long->fresh()->is_short);
        $this->assertNull($noDuration->fresh()->is_short);
        $this->assertNotNull($noDuration->fresh()->format_checked_at);
    }
}
