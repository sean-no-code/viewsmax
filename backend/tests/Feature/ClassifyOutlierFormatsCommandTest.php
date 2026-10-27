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

    public function test_marks_tiktok_and_instagram_rows_short(): void
    {
        $tt = $this->makeVideo('tiktok', 'tt1');
        $ig = $this->makeVideo('instagram', 'ig1');

        $this->artisan('outliers:classify-formats')->assertExitCode(0);

        $this->assertTrue($tt->fresh()->is_short);
        $this->assertTrue($ig->fresh()->is_short);
    }

    public function test_classifies_unclassified_youtube_rows_from_their_duration_without_calling_out(): void
    {
        Http::fake();
        $short = $this->makeVideo('youtube', 'ytshort1', ['duration' => 'PT58S']);
        $long = $this->makeVideo('youtube', 'ytlong1', ['duration' => 'PT12M']);

        $this->artisan('outliers:classify-formats')
            ->expectsOutputToContain('short 1, long 1')
            ->assertExitCode(0);

        $this->assertTrue($short->fresh()->is_short);
        $this->assertFalse($long->fresh()->is_short);
        Http::assertNothingSent();
    }

    public function test_skips_rows_already_classified_or_recently_checked(): void
    {
        $done = $this->makeVideo('youtube', 'ytDone', ['is_short' => true, 'format_checked_at' => now(), 'duration' => 'PT10M']);
        $recent = $this->makeVideo('youtube', 'ytRecent', ['is_short' => null, 'format_checked_at' => now()->subHour(), 'duration' => 'PT10S']);

        $this->artisan('outliers:classify-formats')
            ->expectsOutputToContain('classified 0')
            ->assertExitCode(0);

        $this->assertTrue($done->fresh()->is_short);
        $this->assertNull($recent->fresh()->is_short);
    }

    public function test_leaves_duration_less_rows_null_but_stamps_checked_at(): void
    {
        $video = $this->makeVideo('youtube', 'ytnodur');

        $this->artisan('outliers:classify-formats')->assertExitCode(0);

        $fresh = $video->fresh();
        $this->assertNull($fresh->is_short);
        $this->assertNotNull($fresh->format_checked_at);
    }

    public function test_retries_stale_unknowns_after_the_configured_days(): void
    {
        // An unknown row that has since received a duration gets decided on the next backfill.
        $stale = $this->makeVideo('youtube', 'ytStale', ['format_checked_at' => now()->subDays(8), 'duration' => 'PT20S']);

        $this->artisan('outliers:classify-formats', ['--retry-unknown-days' => 7])->assertExitCode(0);

        $this->assertTrue($stale->fresh()->is_short);
    }

    public function test_respects_limit_option(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeVideo('youtube', "ytlong{$i}", ['duration' => 'PT10M']);
        }

        $this->artisan('outliers:classify-formats', ['--limit' => 2])->assertExitCode(0);

        $this->assertSame(3, OutlierVideo::whereNull('is_short')->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $tt = $this->makeVideo('tiktok', 'tt1');
        $yt = $this->makeVideo('youtube', 'ytshort1', ['duration' => 'PT10S']);

        $this->artisan('outliers:classify-formats', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($tt->fresh()->is_short);
        $this->assertNull($yt->fresh()->is_short);
    }
}
