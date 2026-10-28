<?php

namespace Tests\Feature;

use App\Contracts\Quota\QuotaDecision;
use App\Contracts\Quota\QuotaGate;
use App\Models\SearchTerm;
use App\Models\TermsDataFetch;
use App\Models\User;
use App\Services\YouTubeSearchService;
use App\Support\Quota\QuotaLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * storage/logs/youtube-quota.log answers "did that search hit Google?": one
 * line per search saying it was served from cache, fetched from YouTube, or
 * paused for quota — and one per YouTube API call with its cost.
 */
class YouTubeQuotaLogTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new TestHandler;
        config(['logging.channels.youtube_quota' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::channel(QuotaLog::CHANNEL)->getLogger()->setHandlers([$this->log]);
        Queue::fake();
        // Pin what "stale" means so a dev box's YOUTUBE_FETCH_PERIOD_DAYS can't change the outcome.
        config(['services.youtube.fetch_period_days' => 30, 'services.youtube.search_pages' => 1]);
    }

    /** @return list<array> the context of every youtube_quota.search line */
    private function searchLines(): array
    {
        return array_values(array_map(fn ($r) => $r->context, array_filter($this->log->getRecords(), fn ($r) => $r->message === 'youtube_quota.search')));
    }

    public function test_a_new_term_is_logged_as_fetched_from_youtube(): void
    {
        $user = User::factory()->create();

        app(YouTubeSearchService::class)->search('ai agents', $user);

        $this->assertCount(1, $this->searchLines());
        $line = $this->searchLines()[0];
        $this->assertSame('youtube', $line['served']);
        $this->assertSame('ai agents', $line['term']);
        $this->assertSame($user->id, $line['user_id']);
        $this->assertSame('free', $line['tier']);
        $this->assertSame('never fetched', $line['reason']);
        $this->assertSame(2, $line['calls']);
    }

    public function test_a_recently_fetched_term_is_logged_as_served_from_cache(): void
    {
        $term = SearchTerm::create(['term' => 'ai agents']);
        TermsDataFetch::create(['term_id' => $term->id, 'status' => TermsDataFetch::STATUS_DONE, 'fetched_at' => now()->subDays(3)]);

        app(YouTubeSearchService::class)->search('ai agents', User::factory()->create());

        Queue::assertNothingPushed();
        $line = $this->searchLines()[0];
        $this->assertSame('cache', $line['served']);
        $this->assertStringStartsWith('done, fetched 3d ago', $line['reason']);
    }

    public function test_a_quota_paused_term_is_logged_with_when_it_resumes(): void
    {
        $this->app->instance(QuotaGate::class, new class implements QuotaGate {
            public function allows(string $resource, string $tier, int $calls = 1, ?int $userId = null): QuotaDecision
            {
                return QuotaDecision::deny($resource, $tier, 48, 48, new \DateTimeImmutable('2026-10-29 07:00:00', new \DateTimeZone('UTC')));
            }

            public function recordUserFetch(int $userId, string $resource): void {}
        });

        app(YouTubeSearchService::class)->search('ai agents', User::factory()->create());

        $line = $this->searchLines()[0];
        $this->assertSame('paused', $line['served']);
        $this->assertSame(48, $line['used']);
        $this->assertSame('2026-10-29T07:00:00+00:00', $line['resets_at']);
    }

    public function test_every_youtube_api_call_is_logged_with_its_cost(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        Http::get('https://www.googleapis.com/youtube/v3/search?q=x');

        $spend = array_values(array_filter($this->log->getRecords(), fn ($r) => $r->message === 'youtube_quota.spend'))[0]->context;
        $this->assertSame('search_list', $spend['resource']);
        $this->assertSame(100, $spend['units']);
        $this->assertSame(200, $spend['status']);
        $this->assertSame(1, $spend['used_today']);
    }
}
