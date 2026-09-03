<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClassifyOutlierFormatsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeVideo(string $platform, string $id, array $extra = []): OutlierVideo
    {
        $channel = OutlierChannel::firstOrCreate(
            ['platform' => $platform, 'youtube_channel_id' => 'ch-'.$platform],
            ['channel_name' => 'Channel'],
        );

        return OutlierVideo::create(array_merge([
            'platform' => $platform, 'channel_id' => $channel->id, 'youtube_video_id' => $id,
            'title' => $id, 'thumbnail_url' => 'x', 'published_at' => now(),
        ], $extra));
    }

    /** 200 for ids starting "ytshort", 303 → /watch otherwise (every probe URL contains "/shorts/"). */
    private function fakeProbes(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => function ($request) {
            return str_contains($request->url(), '/shorts/ytshort')
                ? Http::response('', 200)
                : Http::response('', 303, ['Location' => 'https://www.youtube.com/watch?v=x']);
        }]);
    }

    public function test_marks_tiktok_and_instagram_rows_short_without_probing(): void
    {
        Http::fake();
        $tt = $this->makeVideo('tiktok', 'tt1');
        $ig = $this->makeVideo('instagram', 'ig1');

        $this->artisan('outliers:classify-formats', ['--delay-ms' => 0])->assertExitCode(0);

        $this->assertTrue($tt->fresh()->is_short);
        $this->assertTrue($ig->fresh()->is_short);
        Http::assertNothingSent();
    }

    public function test_probes_unclassified_youtube_rows_and_stores_result(): void
    {
        $this->fakeProbes();
        $short = $this->makeVideo('youtube', 'ytshort1');
        $long = $this->makeVideo('youtube', 'ytlong1');

        $this->artisan('outliers:classify-formats', ['--delay-ms' => 0])
            ->expectsOutputToContain('short 1, long 1')
            ->assertExitCode(0);

        $this->assertTrue($short->fresh()->is_short);
        $this->assertFalse($long->fresh()->is_short);
        Http::assertSentCount(2);
    }

    public function test_skips_rows_already_classified_or_recently_checked(): void
    {
        $this->fakeProbes();
        $this->makeVideo('youtube', 'ytshortDone', ['is_short' => true, 'format_checked_at' => now()]);
        $this->makeVideo('youtube', 'ytlongRecent', ['is_short' => null, 'format_checked_at' => now()->subHour()]);

        $this->artisan('outliers:classify-formats', ['--delay-ms' => 0])->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_leaves_inconclusive_rows_null_but_stamps_checked_at(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 404)]);
        $video = $this->makeVideo('youtube', 'ytgone');

        $this->artisan('outliers:classify-formats', ['--delay-ms' => 0])->assertExitCode(0);

        $fresh = $video->fresh();
        $this->assertNull($fresh->is_short);
        $this->assertNotNull($fresh->format_checked_at);
    }

    public function test_retries_stale_unknowns_after_the_configured_days(): void
    {
        $this->fakeProbes();
        $stale = $this->makeVideo('youtube', 'ytshortStale', ['format_checked_at' => now()->subDays(8)]);

        $this->artisan('outliers:classify-formats', ['--delay-ms' => 0, '--retry-unknown-days' => 7])->assertExitCode(0);

        $this->assertTrue($stale->fresh()->is_short);
        Http::assertSentCount(1);
    }

    public function test_respects_limit_option(): void
    {
        $this->fakeProbes();
        foreach (range(1, 5) as $i) {
            $this->makeVideo('youtube', "ytlong{$i}");
        }

        $this->artisan('outliers:classify-formats', ['--delay-ms' => 0, '--limit' => 2])->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertSame(3, OutlierVideo::whereNull('is_short')->count());
    }

    public function test_stops_on_rate_limit_and_leaves_remaining_rows_untouched(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::sequence()->push('', 429)->push('', 200)]);
        $a = $this->makeVideo('youtube', 'yta');
        $b = $this->makeVideo('youtube', 'ytb');

        $this->artisan('outliers:classify-formats', ['--delay-ms' => 0])
            ->expectsOutputToContain('Rate limited')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertNull($a->fresh()->is_short);
        $this->assertNull($b->fresh()->is_short);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Http::fake();
        $tt = $this->makeVideo('tiktok', 'tt1');
        $yt = $this->makeVideo('youtube', 'ytshort1');

        $this->artisan('outliers:classify-formats', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($tt->fresh()->is_short);
        $this->assertNull($yt->fresh()->is_short);
        Http::assertNothingSent();
    }
}
