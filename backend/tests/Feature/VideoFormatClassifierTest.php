<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Services\Exceptions\ProbeRateLimited;
use App\Services\VideoFormatClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shorts vs long-form classification. YouTube exposes the answer via its own URL:
 * HEAD /shorts/{id} → 200 for a Short, 303 → /watch for long-form, 404 for unknown.
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

    public function test_tiktok_and_instagram_are_always_short_without_probing(): void
    {
        Http::fake();

        $this->assertTrue($this->classifier()->classify('tiktok', '7646812028874673439'));
        $this->assertTrue($this->classifier()->classify('instagram', 'DbYaLffE2DD'));
        Http::assertNothingSent();
    }

    public function test_youtube_shorts_url_returning_200_is_short(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 200)]);

        $this->assertTrue($this->classifier()->classify('youtube', 'abc123'));
    }

    public function test_youtube_redirect_to_watch_is_long(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 303, ['Location' => 'https://www.youtube.com/watch?v=abc123'])]);

        $this->assertFalse($this->classifier()->classify('youtube', 'abc123'));
    }

    public function test_youtube_404_is_unknown(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 404)]);

        $this->assertNull($this->classifier()->classify('youtube', 'gone'));
    }

    public function test_youtube_timeout_is_unknown(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->assertNull($this->classifier()->classify('youtube', 'slow'));
    }

    public function test_redirect_to_consent_page_raises_rate_limited(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 302, ['Location' => 'https://consent.youtube.com/m?continue=...'])]);

        $this->expectException(ProbeRateLimited::class);
        $this->classifier()->classify('youtube', 'abc123');
    }

    public function test_429_raises_rate_limited(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 429)]);

        $this->expectException(ProbeRateLimited::class);
        $this->classifier()->classify('youtube', 'abc123');
    }

    public function test_probe_is_a_head_request_to_the_shorts_url(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 200)]);

        $this->classifier()->classify('youtube', 'abc123');

        Http::assertSent(fn ($r) => $r->method() === 'HEAD' && str_contains($r->url(), 'youtube.com/shorts/abc123'));
    }

    public function test_ensure_classified_skips_rows_already_classified(): void
    {
        Http::fake();
        $video = $this->makeVideo('youtube', ['is_short' => false, 'format_checked_at' => now()]);

        $this->assertFalse($this->classifier()->ensureClassified($video));

        Http::assertNothingSent();
        $this->assertFalse($video->fresh()->is_short);
    }

    public function test_ensure_classified_stores_the_probe_result(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 200)]);
        $video = $this->makeVideo('youtube');

        $this->assertTrue($this->classifier()->ensureClassified($video));

        $this->assertTrue($video->fresh()->is_short);
        $this->assertNotNull($video->fresh()->format_checked_at);
    }

    public function test_ensure_classified_stamps_checked_at_even_when_unknown(): void
    {
        Http::fake(['www.youtube.com/shorts/*' => Http::response('', 404)]);
        $video = $this->makeVideo('youtube');

        $this->assertNull($this->classifier()->ensureClassified($video));

        $fresh = $video->fresh();
        $this->assertNull($fresh->is_short);
        $this->assertNotNull($fresh->format_checked_at); // so backfills don't loop on it
    }

    public function test_ensure_classified_marks_tiktok_short_without_probing(): void
    {
        Http::fake();
        $video = $this->makeVideo('tiktok');

        $this->assertTrue($this->classifier()->ensureClassified($video));
        $this->assertTrue($video->fresh()->is_short);
        Http::assertNothingSent();
    }

    public function test_probe_disabled_by_config_returns_null(): void
    {
        config()->set('services.youtube.format_probe_enabled', false);
        Http::fake();

        $this->assertNull($this->classifier()->classify('youtube', 'abc123'));
        Http::assertNothingSent();
    }
}
