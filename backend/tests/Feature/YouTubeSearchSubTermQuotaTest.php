<?php

namespace Tests\Feature;

use App\Contracts\Quota\QuotaDecision;
use App\Contracts\Quota\QuotaGate;
use App\Jobs\ProcessYouTubeSearchTermJob;
use App\Models\OutlierVideo;
use App\Models\SearchTerm;
use App\Models\SearchTermsRequest;
use App\Models\TermsDataFetch;
use App\Models\User;
use App\Services\YouTubeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A multi-word search fans out to a search per word. Each of those is a
 * Google-bound fetch of its own, so it must pass the user's daily allowance
 * and count against it like the term they typed — and function words such as
 * "to" or "how" never earn a search at all.
 */
class YouTubeSearchSubTermQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.youtube.key', 'yt_test_key');
        config()->set('services.youtube.min_outlier_score', 0);
        Queue::fake();
        $this->fakeYouTube();
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
            '*/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'vidA']], ['id' => ['videoId' => 'vidB']]]], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => [$this->videoItem('vidA', 50000), $this->videoItem('vidB', 40000)]], 200),
            '*/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCsearch',
                'snippet' => ['title' => 'Search Channel', 'thumbnails' => ['high' => ['url' => 'https://example.com/av.jpg']]],
                'statistics' => ['subscriberCount' => '9000', 'videoCount' => '12'],
            ]]], 200),
            '*/youtube/v3/playlistItems*' => Http::response(['items' => []], 200),
        ]);
    }

    /** A gate that records every question and every charge, with a per-user budget of fresh fetches. */
    private function spyGate(int $userAllowance = PHP_INT_MAX): object
    {
        $gate = new class($userAllowance) implements QuotaGate
        {
            public array $asked = [];

            public array $charged = [];

            public function __construct(private int $allowance) {}

            public function allows(string $resource, string $tier, int $calls = 1, ?int $userId = null): QuotaDecision
            {
                $this->asked[] = ['resource' => $resource, 'user_id' => $userId, 'calls' => $calls];
                $resetsAt = new \DateTimeImmutable('2026-11-04 08:00:00', new \DateTimeZone('UTC'));

                if ($userId !== null && count($this->charged) >= $this->allowance) {
                    return QuotaDecision::denyUser($resource, $tier, count($this->charged), $this->allowance, $resetsAt);
                }

                return QuotaDecision::allow($resource, $tier, 0, null, $resetsAt);
            }

            public function recordUserFetch(int $userId, string $resource): void
            {
                $this->charged[] = [$userId, $resource];
            }
        };
        $this->app->instance(QuotaGate::class, $gate);

        return $gate;
    }

    private function queuedTerm(string $term): SearchTerm
    {
        $searchTerm = SearchTerm::create(['term' => $term]);
        TermsDataFetch::create(['term_id' => $searchTerm->id, 'status' => TermsDataFetch::STATUS_QUEUED]);

        return $searchTerm;
    }

    /** Terms whose fetch the run queued (the typed term itself ends up done). */
    private function queuedSubTerms(): array
    {
        return SearchTerm::whereHas('termsDataFetch', fn ($q) => $q->where('status', TermsDataFetch::STATUS_QUEUED))
            ->orderBy('term')->pluck('term')->all();
    }

    public function test_each_content_word_is_gated_with_the_user_and_charged(): void
    {
        $gate = $this->spyGate();
        $user = User::factory()->create();

        app(YouTubeSearchService::class)->fetchAndProcess('how to grow on youtube', $this->queuedTerm('how to grow on youtube'), $user);

        // "how", "to" and "on" are function words; "grow" and "youtube" get a search each.
        Queue::assertPushed(ProcessYouTubeSearchTermJob::class, 2);
        $this->assertSame(['grow', 'youtube'], $this->queuedSubTerms());
        $this->assertSame([[$user->id, 'search_list'], [$user->id, 'search_list']], $gate->charged);
        // The typed term's own job-stage check is the shared cap; every sub-term asks with the user.
        $this->assertSame([null, $user->id, $user->id], array_column($gate->asked, 'user_id'));
    }

    public function test_a_term_made_of_function_words_fans_out_to_nothing(): void
    {
        $gate = $this->spyGate();

        app(YouTubeSearchService::class)->fetchAndProcess('how to', $this->queuedTerm('how to'), User::factory()->create());

        Queue::assertNotPushed(ProcessYouTubeSearchTermJob::class);
        $this->assertSame([], $gate->charged);
    }

    public function test_sub_terms_the_allowance_refuses_are_skipped_not_queued(): void
    {
        $gate = $this->spyGate(userAllowance: 0);
        $term = $this->queuedTerm('how to grow on youtube');

        app(YouTubeSearchService::class)->fetchAndProcess('how to grow on youtube', $term, User::factory()->create());

        // The typed term still ran…
        $this->assertSame(TermsDataFetch::STATUS_DONE, $term->termsDataFetch()->first()->status);
        $this->assertSame(2, OutlierVideo::count());
        // …its words were refused and left alone: nothing queued for the next requester to pay for.
        Queue::assertNotPushed(ProcessYouTubeSearchTermJob::class);
        $this->assertSame([], $gate->charged);
        $this->assertSame([], $this->queuedSubTerms());
        $this->assertNull(SearchTerm::where('term', 'grow')->first()?->termsDataFetch);
    }

    public function test_a_sub_term_fetched_recently_is_neither_queued_nor_charged(): void
    {
        $gate = $this->spyGate();
        $grow = SearchTerm::create(['term' => 'grow']);
        TermsDataFetch::create(['term_id' => $grow->id, 'status' => TermsDataFetch::STATUS_DONE, 'fetched_at' => now()]);

        app(YouTubeSearchService::class)->fetchAndProcess('how to grow on youtube', $this->queuedTerm('how to grow on youtube'), User::factory()->create());

        Queue::assertPushed(ProcessYouTubeSearchTermJob::class, 1);
        $this->assertSame(['youtube'], $this->queuedSubTerms());
        $this->assertCount(1, $gate->charged);
    }

    public function test_a_search_request_is_recorded_once_and_charged_once(): void
    {
        $gate = $this->spyGate();
        $user = User::factory()->create();

        $this->assertNull(app(YouTubeSearchService::class)->search('ai agents', $user));

        $this->assertSame(1, SearchTermsRequest::where('user_id', $user->id)->count());
        Queue::assertPushed(ProcessYouTubeSearchTermJob::class, 1);
        $this->assertSame([[$user->id, 'search_list']], $gate->charged);
        // Words fan out when the job runs, not at request time.
        $this->assertFalse(SearchTerm::where('term', 'agents')->exists());
    }
}
