<?php

namespace Tests\Feature;

use App\Jobs\ClassifyOutlierVideoFormatsJob;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\SearchTerm;
use App\Models\TermsDataFetch;
use App\Models\User;
use App\Services\YouTubeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        config()->set('services.youtube.format_probe_delay_ms', 0);
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
            'www.youtube.com/shorts/*' => Http::response('', 303, ['Location' => 'https://www.youtube.com/watch?v=x']),
        ]);
    }

    public function test_search_ingest_dispatches_format_classification_for_saved_videos(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $user = User::factory()->create();
        $term = SearchTerm::create(['term' => 'ai']);
        TermsDataFetch::create(['term_id' => $term->id, 'status' => TermsDataFetch::STATUS_QUEUED]);

        app(YouTubeSearchService::class)->fetchAndProcess('ai', $term, $user);

        $this->assertSame(2, OutlierVideo::count());
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

    public function test_classify_job_probes_only_unclassified_rows_and_stores_results(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => function ($request) {
            return str_ends_with($request->url(), 'short') // ids "alreadyshort"/"newshort" (every probe URL has "/shorts/")
                ? Http::response('', 200)
                : Http::response('', 303, ['Location' => 'https://www.youtube.com/watch?v=x']);
        }]);
        $done = $this->makeYoutubeVideo('alreadyshort', ['is_short' => true, 'format_checked_at' => now()]);
        $short = $this->makeYoutubeVideo('newshort');
        $long = $this->makeYoutubeVideo('newlong');

        app()->call([new ClassifyOutlierVideoFormatsJob(['alreadyshort', 'newshort', 'newlong']), 'handle']);

        Http::assertSentCount(2);
        $this->assertTrue($done->fresh()->is_short);
        $this->assertTrue($short->fresh()->is_short);
        $this->assertFalse($long->fresh()->is_short);
    }

    public function test_classify_job_requeues_remaining_ids_when_rate_limited(): void
    {
        Queue::fake();
        Http::fake(['www.youtube.com/shorts/*' => Http::sequence()->push('', 200)->push('', 429)]);
        $first = $this->makeYoutubeVideo('vid1');
        $second = $this->makeYoutubeVideo('vid2');
        $third = $this->makeYoutubeVideo('vid3');

        app()->call([new ClassifyOutlierVideoFormatsJob(['vid1', 'vid2', 'vid3']), 'handle']);

        $this->assertTrue($first->fresh()->is_short);
        $this->assertNull($second->fresh()->is_short);
        $this->assertNull($third->fresh()->is_short);
        // The rest is retried later rather than hammering YouTube now.
        Queue::assertPushed(ClassifyOutlierVideoFormatsJob::class, fn ($job) => $job->videoIds === ['vid2', 'vid3']);
    }
}
