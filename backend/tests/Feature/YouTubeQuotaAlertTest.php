<?php

namespace Tests\Feature;

use App\Mail\YouTubeQuotaAlertMail;
use App\Models\User;
use App\Support\Quota\Keys;
use App\Support\Quota\QuotaAlerts;
use App\Support\Quota\QuotaLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * The admin hears when the day's YouTube calls near or hit Google's
 * allocation — once per resource per day per event — by email (like the
 * queue heartbeat) and an error-level youtube_quota line (Sentry).
 */
class YouTubeQuotaAlertTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->log = new TestHandler;
        config([
            'logging.channels.youtube_quota' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'monitoring.youtube_quota' => ['enabled' => true, 'alert_at' => 80, 'alert_email' => 'ops@example.test'],
            'services.youtube.quota.daily_limits.search_list' => 100,
        ]);
        Log::channel(QuotaLog::CHANNEL)->getLogger()->setHandlers([$this->log]);
        // Everything answers 200 unless a test fakes a 403 itself (stubs match
        // in registration order, so those tests must register theirs first).
        if (! str_contains($this->name(), '403') && ! str_contains($this->name(), 'refusing')) {
            Http::fake(['*' => Http::response([], 200)]);
        }
    }

    private function search(): void
    {
        Http::get('https://www.googleapis.com/youtube/v3/search?q=x');
    }

    private function alertLogs(): array
    {
        return array_values(array_filter($this->log->getRecords(), fn ($r) => $r->message === 'youtube_quota.alert'));
    }

    public function test_reaching_80_percent_emails_the_admin_once(): void
    {
        Cache::put(Keys::counter('search_list'), 78);

        $this->search(); // 79
        Mail::assertNothingSent();

        $this->search(); // 80
        Mail::assertSent(YouTubeQuotaAlertMail::class, function (YouTubeQuotaAlertMail $mail) {
            return $mail->hasTo('ops@example.test')
                && $mail->event === QuotaAlerts::EVENT_WARNING
                && $mail->context['resource'] === 'search_list'
                && $mail->context['used'] === 80
                && $mail->context['limit'] === 100
                && $mail->context['percent'] === 80;
        });
        $this->assertCount(1, $this->alertLogs());
        $this->assertSame('ERROR', $this->alertLogs()[0]->level->getName(), 'error level so Sentry sees it');

        $this->search(); // 81: no second warning
        $this->search(); // 82
        Mail::assertSent(YouTubeQuotaAlertMail::class, 1);
    }

    public function test_reaching_the_whole_allocation_is_its_own_alert(): void
    {
        Cache::put(Keys::counter('search_list'), 99);

        $this->search(); // 100

        Mail::assertSent(YouTubeQuotaAlertMail::class, fn ($mail) => $mail->event === QuotaAlerts::EVENT_EXHAUSTED && $mail->context['used'] === 100);
        Mail::assertSent(YouTubeQuotaAlertMail::class, 1);
        $this->assertStringContainsString('used up for today (100/100)', (new YouTubeQuotaAlertMail(QuotaAlerts::EVENT_EXHAUSTED, Mail::sent(YouTubeQuotaAlertMail::class)->first()->context))->envelope()->subject);
    }

    public function test_google_refusing_a_call_is_reported_even_if_our_count_disagrees(): void
    {
        // Our counter can lag or miss (another process, a reset we got wrong):
        // a quotaExceeded from Google is the ground truth.
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'quotaExceeded', 'domain' => 'youtube.quota']]]], 403)]);

        $this->search();
        $this->search();

        Mail::assertSent(YouTubeQuotaAlertMail::class, fn ($mail) => $mail->event === QuotaAlerts::EVENT_REFUSED);
        Mail::assertSent(YouTubeQuotaAlertMail::class, 1);
    }

    public function test_an_ordinary_403_is_not_a_quota_alert(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]], 403)]);

        $this->search();

        Mail::assertNothingSent();
    }

    public function test_the_subject_names_the_resource_and_the_numbers(): void
    {
        $subject = (new YouTubeQuotaAlertMail(QuotaAlerts::EVENT_WARNING, ['resource' => 'search_list', 'used' => 80, 'limit' => 100, 'percent' => 80, 'resets_at' => 'x']))->envelope()->subject;

        $this->assertStringContainsString('search_list quota at 80% (80/100)', $subject);
    }

    public function test_resources_without_a_daily_limit_never_alert(): void
    {
        Cache::put(Keys::counter('videos_list'), 5_000);

        Http::get('https://www.googleapis.com/youtube/v3/videos?id=v');

        Mail::assertNothingSent();
        $this->assertSame([], $this->alertLogs());
    }

    public function test_alerts_can_be_switched_off(): void
    {
        config(['monitoring.youtube_quota.enabled' => false]);
        Cache::put(Keys::counter('search_list'), 99);

        $this->search();

        Mail::assertNothingSent();
        $this->assertSame([], $this->alertLogs());
    }

    public function test_without_an_email_the_log_line_still_fires(): void
    {
        config(['monitoring.youtube_quota.alert_email' => null]);
        Cache::put(Keys::counter('search_list'), 79);

        $this->search();

        Mail::assertNothingSent();
        $this->assertCount(1, $this->alertLogs());
    }

    public function test_the_admin_summary_shows_the_same_gauge(): void
    {
        Cache::put(Keys::counter('search_list'), 80);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.User::factory()->admin()->create()->createToken('t')->plainTextToken])
            ->getJson('/api/admin/youtube-quota/summary')->assertOk();

        $search = collect($response->json('data.resources'))->firstWhere('resource', 'search_list');
        $this->assertSame(100, $search['daily_limit']);
        $this->assertSame(80, $search['percent_used']);
        $this->assertSame(80, $response->json('data.alert_at'));
        $this->assertNull(collect($response->json('data.resources'))->firstWhere('resource', 'videos_list')['daily_limit']);
    }
}
