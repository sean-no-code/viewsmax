<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Services\VideoFormatClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shorts vs long-form classification from the stored duration: 1–180s is a
 * Short, longer (or 0s live streams / premieres) is long-form, no duration is
 * unknown. TikTok/Instagram are short-form by platform. Nothing is fetched.
 */
class VideoFormatClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function classifier(): VideoFormatClassifier
    {
        return app(VideoFormatClassifier::class);
    }

    private function makeVideo(string $platform = 'youtube', array $extra = []): OutlierVideo
    {
        $channel = OutlierChannel::firstOrCreate(
            ['platform' => $platform, 'youtube_channel_id' => 'ch-'.$platform],
            ['channel_name' => 'Channel'],
        );

        return OutlierVideo::create(array_merge([
            'platform' => $platform, 'channel_id' => $channel->id, 'youtube_video_id' => 'vid'.uniqid(),
            'title' => 'T', 'thumbnail_url' => 'x', 'published_at' => now(),
        ], $extra));
    }

    public function test_tiktok_and_instagram_are_always_short(): void
    {
        $this->assertTrue($this->classifier()->classify($this->makeVideo('tiktok', ['duration' => 'PT10M'])));
        $this->assertTrue($this->classifier()->classify($this->makeVideo('instagram')));
    }

    public function test_youtube_up_to_three_minutes_is_short(): void
    {
        $this->assertTrue($this->classifier()->classify($this->makeVideo('youtube', ['duration' => 'PT1S'])));
        $this->assertTrue($this->classifier()->classify($this->makeVideo('youtube', ['duration' => 'PT59S'])));
        $this->assertTrue($this->classifier()->classify($this->makeVideo('youtube', ['duration' => 'PT3M'])));
    }

    public function test_youtube_over_three_minutes_is_long(): void
    {
        $this->assertFalse($this->classifier()->classify($this->makeVideo('youtube', ['duration' => 'PT3M1S'])));
        $this->assertFalse($this->classifier()->classify($this->makeVideo('youtube', ['duration' => 'PT1H2M'])));
    }

    public function test_zero_duration_live_streams_and_premieres_are_long(): void
    {
        $this->assertFalse($this->classifier()->classify($this->makeVideo('youtube', ['duration' => 'P0D'])));
        $this->assertFalse($this->classifier()->classify($this->makeVideo('youtube', ['duration' => 'PT0S'])));
    }

    public function test_youtube_without_a_duration_is_unknown(): void
    {
        $this->assertNull($this->classifier()->classify($this->makeVideo('youtube')));
    }

    public function test_classification_never_calls_out(): void
    {
        Http::fake();

        $this->classifier()->ensureClassified($this->makeVideo('youtube', ['duration' => 'PT2M']));

        Http::assertNothingSent();
    }

    public function test_ensure_classified_skips_rows_already_classified(): void
    {
        // A row classified as long stays long even though its duration says short.
        $video = $this->makeVideo('youtube', ['is_short' => false, 'format_checked_at' => now(), 'duration' => 'PT30S']);

        $this->assertFalse($this->classifier()->ensureClassified($video));
        $this->assertFalse($video->fresh()->is_short);
    }

    public function test_ensure_classified_stores_the_result(): void
    {
        $video = $this->makeVideo('youtube', ['duration' => 'PT45S']);

        $this->assertTrue($this->classifier()->ensureClassified($video));

        $this->assertTrue($video->fresh()->is_short);
        $this->assertNotNull($video->fresh()->format_checked_at);
    }

    public function test_ensure_classified_stamps_checked_at_even_when_unknown(): void
    {
        $video = $this->makeVideo('youtube');

        $this->assertNull($this->classifier()->ensureClassified($video));

        $fresh = $video->fresh();
        $this->assertNull($fresh->is_short);
        $this->assertNotNull($fresh->format_checked_at); // so backfills don't loop on it
    }

    public function test_ensure_classified_marks_tiktok_short(): void
    {
        $video = $this->makeVideo('tiktok');

        $this->assertTrue($this->classifier()->ensureClassified($video));
        $this->assertTrue($video->fresh()->is_short);
    }
}
