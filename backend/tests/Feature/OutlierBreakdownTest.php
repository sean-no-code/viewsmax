<?php

namespace Tests\Feature;

use App\Exceptions\GeminiVideoException;
use App\Exceptions\TranscriptUnavailableException;
use App\Jobs\GenerateOutlierBreakdownJob;
use App\Models\OutlierBreakdown;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\User;
use App\Services\CaptApiOutlierService;
use App\Services\GeminiVideoService;
use App\Support\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutlierBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeVideo(): OutlierVideo
    {
        $channel = OutlierChannel::create([
            'platform' => 'youtube',
            'youtube_channel_id' => 'UC123',
            'channel_name' => 'kaiexplains',
            'subscriber_count' => 250000,
            'average_views' => 140000,
        ]);

        return OutlierVideo::create([
            'platform' => 'youtube',
            'channel_id' => $channel->id,
            'youtube_video_id' => 'abc123xyz',
            'title' => 'Gas station sushi is a $2B business',
            'thumbnail_url' => 'https://example.com/t.jpg',
            'views' => 2100000,
            'like_count' => 150000,
            'comment_count' => 9000,
            'outlier_score' => 92,
            'duration' => 'PT38S',
            'published_at' => now()->subWeeks(2),
        ]);
    }

    // ---- Single-outlier show endpoint ----

    public function test_show_returns_video_with_channel(): void
    {
        $this->makeVideo();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/youtube/abc123xyz')
            ->assertOk()
            ->assertJsonPath('data.title', 'Gas station sushi is a $2B business')
            ->assertJsonPath('data.channel.channel_name', 'kaiexplains');
    }

    public function test_show_unknown_video_is_404(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/youtube/nope')
            ->assertNotFound();
    }

    public function test_show_does_not_shadow_literal_outlier_routes(): void
    {
        // /api/outliers/tags must still hit the tags endpoint, not show().
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/tags')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ---- Breakdown lifecycle ----

    public function test_breakdown_status_is_none_before_generation(): void
    {
        $this->makeVideo();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'none');
    }

    public function test_requesting_breakdown_queues_job_and_creates_pending_row(): void
    {
        Queue::fake();
        $this->makeVideo();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 1);
        $this->assertDatabaseHas('outlier_breakdowns', [
            'platform' => 'youtube', 'video_id' => 'abc123xyz', 'status' => 'pending',
        ], 'outlier_db');
    }

    public function test_requesting_breakdown_for_unknown_video_is_404(): void
    {
        Queue::fake();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/nope/breakdown')
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_completed_breakdown_is_returned_without_requeue(): void
    {
        Queue::fake();
        $this->makeVideo();
        OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_COMPLETED,
            'payload' => ['idea' => ['topic' => 'Convenience-store food economics']],
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payload.idea.topic', 'Convenience-store food economics');

        Queue::assertNothingPushed();
    }

    public function test_failed_breakdown_is_requeued_on_request(): void
    {
        Queue::fake();
        $this->makeVideo();
        OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_FAILED,
            'error' => 'boom',
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 1);
    }

    public function test_repasting_a_link_requeues_a_breakdown_stuck_in_processing(): void
    {
        Queue::fake();
        $this->makeVideo();
        $stuck = OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PROCESSING,
        ]);
        // Its job is gone (queue cleared / worker killed) — nothing will ever finish it.
        $stuck->forceFill(['updated_at' => now()->subMinutes(OutlierBreakdown::STALE_MINUTES + 1)])->saveQuietly();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', ['platform' => 'youtube', 'url' => 'https://youtu.be/abc123xyz'])
            ->assertOk();

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 1);
        $this->assertSame(OutlierBreakdown::STATUS_PENDING, $stuck->fresh()->status);
    }

    public function test_repasting_a_link_leaves_a_running_breakdown_alone(): void
    {
        Queue::fake();
        $this->makeVideo();
        OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PROCESSING,
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', ['platform' => 'youtube', 'url' => 'https://youtu.be/abc123xyz'])
            ->assertOk();

        Queue::assertNotPushed(GenerateOutlierBreakdownJob::class);
    }

    public function test_job_that_crashes_outside_handle_marks_the_breakdown_failed(): void
    {
        $breakdown = OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        // What the worker calls when handle()'s dependencies can't be built (e.g. no API key).
        (new GenerateOutlierBreakdownJob('youtube', 'abc123xyz'))
            ->failed(new \TypeError('Cannot assign null to property App\Services\GeminiVideoService::$apiKey of type string'));

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_FAILED, $breakdown->status);
        $this->assertStringNotContainsString('GeminiVideoService', $breakdown->error);
    }

    public function test_job_timeout_stays_below_the_queue_retry_window(): void
    {
        // A job that outlives retry_after is handed to a second worker → Gemini runs twice.
        $this->assertLessThan((int) config('queue.connections.database.retry_after', 330), (new GenerateOutlierBreakdownJob('youtube', 'x'))->timeout);
    }

    private static function payload(): array
    {
        return [
            'has_speech' => true,
            'idea' => ['topic' => 'Convenience-store food economics', 'idea_seed' => 'seed', 'unique_angle' => 'angle'],
            'hook' => [['time' => '0.0s', 'line' => 'Gas station sushi…', 'note' => 'Stat shock.']],
            'structure' => ['summary' => '5 beats over 38s', 'beats' => []],
            'visual' => [['title' => 'Talking head · 60%', 'note' => 'Centered.']],
            'transcript' => [['time' => '0:00', 'text' => 'Gas station sushi…', 'label' => 'hook']],
        ];
    }

    public function test_job_sends_the_youtube_url_to_gemini_and_stores_the_payload(): void
    {
        $this->makeVideo();
        $breakdown = OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $this->mock(GeminiVideoService::class, function ($mock) {
            $mock->shouldReceive('breakdown')->once()
                ->with(
                    \Mockery::on(fn (array $meta) => $meta['title'] === 'Gas station sushi is a $2B business'
                        && $meta['channel'] === 'kaiexplains' && $meta['platform'] === 'youtube'
                        && $meta['duration_seconds'] === 38 && $meta['views'] === 2100000 && $meta['video_id'] === 'abc123xyz'),
                    \Mockery::on(fn (VideoSource $src) => $src->isYoutube() && $src->url === 'https://www.youtube.com/watch?v=abc123xyz'),
                )
                ->andReturn(['payload' => self::payload(), 'usage' => ['model' => 'gemini-3.8-flash', 'video_tokens' => 3400, 'cost_usd' => 0.0051]]);
        });
        $this->mock(CaptApiOutlierService::class, fn ($m) => $m->shouldNotReceive('fetchAndStore'));

        app()->call([new GenerateOutlierBreakdownJob('youtube', 'abc123xyz'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_COMPLETED, $breakdown->status);
        $this->assertSame('Convenience-store food economics', $breakdown->payload['idea']['topic']);
        $this->assertTrue($breakdown->payload['has_speech']);
        $this->assertNull($breakdown->error);
    }

    public function test_job_marks_failed_and_reports_an_unexpected_error_with_a_safe_message(): void
    {
        Exceptions::fake();
        config(['app.debug' => false]);
        $this->makeVideo();
        $breakdown = OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $this->mock(GeminiVideoService::class, function ($mock) {
            $mock->shouldReceive('breakdown')->once()->andThrow(new \LogicException('internal: schema builder exploded'));
        });

        app()->call([new GenerateOutlierBreakdownJob('youtube', 'abc123xyz'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_FAILED, $breakdown->status);
        $this->assertSame('Breakdown generation failed. Try again in a few minutes.', $breakdown->error);

        // The failure must reach the exception handler (Sentry, error log, Slack),
        // not just the row — a swallowed exception is how failures went unnoticed.
        Exceptions::assertReported(fn (\LogicException $e) => str_contains($e->getMessage(), 'exploded'));
    }

    public function test_job_marks_failed_without_reporting_when_the_video_is_unavailable(): void
    {
        Exceptions::fake();
        $this->makeVideo();
        $breakdown = OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $this->mock(GeminiVideoService::class, function ($mock) {
            $mock->shouldReceive('breakdown')->once()->andThrow(GeminiVideoException::videoUnavailable('HTTP 400 not available'));
        });

        app()->call([new GenerateOutlierBreakdownJob('youtube', 'abc123xyz'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_FAILED, $breakdown->status);
        $this->assertStringContainsString("couldn't access this video", $breakdown->error);
        $this->assertStringNotContainsString('HTTP 400', $breakdown->error);

        // A private / removed video is the provider's answer, not our bug: the row
        // carries the message for the user, and Sentry is left alone.
        Exceptions::assertNothingReported();
    }

    public function test_job_reports_a_reportable_gemini_failure(): void
    {
        Exceptions::fake();
        $this->makeVideo();
        OutlierBreakdown::create(['platform' => 'youtube', 'video_id' => 'abc123xyz', 'status' => OutlierBreakdown::STATUS_PENDING]);

        $this->mock(GeminiVideoService::class, function ($mock) {
            $mock->shouldReceive('breakdown')->once()->andThrow(GeminiVideoException::badResponse('not json'));
        });

        app()->call([new GenerateOutlierBreakdownJob('youtube', 'abc123xyz'), 'handle']);

        // The provider's answer must travel with the report: laravel.log and Sentry
        // read context(), so a generic "analysis failed" line is never all we have.
        Exceptions::assertReported(fn (GeminiVideoException $e) => $e->reason === GeminiVideoException::BAD_RESPONSE
            && $e->context() === ['reason' => GeminiVideoException::BAD_RESPONSE, 'detail' => 'not json']);
    }

    // ---- TikTok: no media file is obtainable, so Gemini works from CaptAPI's transcript ----

    private function makeTiktokVideo(?string $handle, string $displayName = 'Tunde Alao'): OutlierVideo
    {
        $channel = OutlierChannel::create([
            'platform' => 'tiktok',
            'youtube_channel_id' => '7000000000000000001',
            'handle' => $handle,
            'channel_name' => $displayName,
        ]);

        return OutlierVideo::create([
            'platform' => 'tiktok',
            'channel_id' => $channel->id,
            'youtube_video_id' => '7685768676645113110',
            'title' => 'How I got my first 10k',
            'views' => 500000,
            'duration' => 'PT41S',
            'published_at' => now()->subDays(3),
        ]);
    }

    private const TT_TRANSCRIPT = ['text' => 'hello there', 'segments' => [['text' => 'hello there', 'startMs' => 0, 'endMs' => 1500]], 'language' => 'en'];

    private function expectTranscriptBreakdown(): void
    {
        $this->mock(GeminiVideoService::class, function ($mock) {
            $mock->shouldNotReceive('breakdown');
            $mock->shouldReceive('breakdownFromTranscript')->once()
                ->with(\Mockery::on(fn (array $m) => $m['platform'] === 'tiktok' && $m['video_id'] === '7685768676645113110'), 'hello there', self::TT_TRANSCRIPT['segments'])
                ->andReturn(['payload' => self::payload() + ['visual_inferred' => true], 'usage' => ['model' => 'gemini-3.8-flash', 'source' => 'transcript']]);
        });
    }

    public function test_native_url_uses_handle_and_strips_display_name_noise(): void
    {
        $this->assertSame(
            'https://www.tiktok.com/@itstundealao/video/1',
            GenerateOutlierBreakdownJob::nativeUrl('tiktok', '1', '@itstundealao'),
        );
        // A display name with spaces/emoji must never reach CaptAPI as-is — it reads as a profile link.
        $this->assertSame(
            'https://www.tiktok.com/@TundeAlao/video/1',
            GenerateOutlierBreakdownJob::nativeUrl('tiktok', '1', 'Tunde Alao 🔥'),
        );
    }

    public function test_tiktok_job_sends_captapi_transcript_to_gemini_with_the_stored_handle(): void
    {
        $this->makeTiktokVideo('itstundealao');
        $breakdown = OutlierBreakdown::create([
            'platform' => 'tiktok', 'video_id' => '7685768676645113110', 'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $this->mock(CaptApiOutlierService::class, function ($mock) {
            $mock->shouldNotReceive('fetchAndStore');
            $mock->shouldNotReceive('fetchMediaUrl');
            $mock->shouldReceive('fetchTranscript')->once()
                ->with('tiktok', 'https://www.tiktok.com/@itstundealao/video/7685768676645113110')
                ->andReturn(self::TT_TRANSCRIPT);
        });
        $this->expectTranscriptBreakdown();

        app()->call([new GenerateOutlierBreakdownJob('tiktok', '7685768676645113110'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_COMPLETED, $breakdown->status);
        $this->assertTrue($breakdown->payload['visual_inferred']);
    }

    public function test_tiktok_job_backfills_a_missing_handle_before_fetching_the_transcript(): void
    {
        // Channel ingested before handles were stored: only the display name is known.
        $video = $this->makeTiktokVideo(null);
        $breakdown = OutlierBreakdown::create([
            'platform' => 'tiktok', 'video_id' => '7685768676645113110', 'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $this->mock(CaptApiOutlierService::class, function ($mock) use ($video) {
            // One video-details call (with a parseable placeholder handle) stores the real @handle.
            $mock->shouldReceive('fetchAndStore')->once()
                ->with('tiktok', 'https://www.tiktok.com/@TundeAlao/video/7685768676645113110')
                ->andReturnUsing(function () use ($video) {
                    $video->channel->update(['handle' => 'itstundealao']);

                    return $video;
                });
            $mock->shouldReceive('fetchTranscript')->once()
                ->with('tiktok', 'https://www.tiktok.com/@itstundealao/video/7685768676645113110')
                ->andReturn(self::TT_TRANSCRIPT);
        });
        $this->expectTranscriptBreakdown();

        app()->call([new GenerateOutlierBreakdownJob('tiktok', '7685768676645113110'), 'handle']);

        $this->assertSame(OutlierBreakdown::STATUS_COMPLETED, $breakdown->fresh()->status);
        $this->assertSame('itstundealao', $video->channel->fresh()->handle);
    }

    public function test_tiktok_job_marks_failed_without_reporting_when_there_is_no_transcript(): void
    {
        Exceptions::fake();
        $this->makeTiktokVideo('itstundealao');
        $breakdown = OutlierBreakdown::create([
            'platform' => 'tiktok', 'video_id' => '7685768676645113110', 'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $this->mock(CaptApiOutlierService::class, fn ($m) => $m->shouldReceive('fetchTranscript')->once()
            ->andThrow(new TranscriptUnavailableException('Transcript not available for this video')));
        $this->mock(GeminiVideoService::class, fn ($m) => $m->shouldNotReceive('breakdownFromTranscript'));

        app()->call([new GenerateOutlierBreakdownJob('tiktok', '7685768676645113110'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_FAILED, $breakdown->status);
        $this->assertSame('Transcript not available for this video', $breakdown->error);
        Exceptions::assertNothingReported();
    }

    // ---- Instagram: Gemini watches the file from CaptAPI's media URL ----

    private function makeInstagramVideo(array $attrs = []): OutlierVideo
    {
        $channel = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'kallaway', 'handle' => 'kallaway', 'channel_name' => 'Kallaway']);

        return OutlierVideo::create([
            'platform' => 'instagram', 'channel_id' => $channel->id, 'youtube_video_id' => 'DVzhP3Cj8ZL',
            'title' => 'Openclaw agent', 'views' => 612094, 'duration' => 'PT56S', 'published_at' => now()->subDays(3),
        ] + $attrs);
    }

    private function expectRemoteBreakdown(string $mediaUrl): void
    {
        $this->mock(GeminiVideoService::class, function ($mock) use ($mediaUrl) {
            $mock->shouldReceive('breakdown')->once()
                ->with(\Mockery::type('array'), \Mockery::on(fn (VideoSource $src) => ! $src->isYoutube() && $src->url === $mediaUrl))
                ->andReturn(['payload' => self::payload(), 'usage' => []]);
        });
    }

    public function test_instagram_job_uses_a_fresh_stored_media_url_without_calling_captapi(): void
    {
        $this->makeInstagramVideo(['video_url' => 'https://cdn.test/fresh.mp4', 'video_url_expires_at' => now()->addHours(2)]);
        $breakdown = OutlierBreakdown::create(['platform' => 'instagram', 'video_id' => 'DVzhP3Cj8ZL', 'status' => OutlierBreakdown::STATUS_PENDING]);

        $this->mock(CaptApiOutlierService::class, fn ($m) => $m->shouldNotReceive('fetchAndStore'));
        $this->expectRemoteBreakdown('https://cdn.test/fresh.mp4');

        app()->call([new GenerateOutlierBreakdownJob('instagram', 'DVzhP3Cj8ZL'), 'handle']);

        $this->assertSame(OutlierBreakdown::STATUS_COMPLETED, $breakdown->fresh()->status);
    }

    public function test_instagram_job_refreshes_an_expired_media_url_through_captapi(): void
    {
        $video = $this->makeInstagramVideo(['video_url' => 'https://cdn.test/old.mp4', 'video_url_expires_at' => now()->addMinutes(5)]);
        $breakdown = OutlierBreakdown::create(['platform' => 'instagram', 'video_id' => 'DVzhP3Cj8ZL', 'status' => OutlierBreakdown::STATUS_PENDING]);

        $this->mock(CaptApiOutlierService::class, function ($mock) use ($video) {
            $mock->shouldReceive('fetchAndStore')->once()
                ->with('instagram', 'https://www.instagram.com/p/DVzhP3Cj8ZL/')
                ->andReturnUsing(function () use ($video) {
                    $video->update(['video_url' => 'https://cdn.test/new.mp4', 'video_url_expires_at' => now()->addHours(2)]);

                    return $video->fresh();
                });
        });
        $this->expectRemoteBreakdown('https://cdn.test/new.mp4');

        app()->call([new GenerateOutlierBreakdownJob('instagram', 'DVzhP3Cj8ZL'), 'handle']);

        $this->assertSame(OutlierBreakdown::STATUS_COMPLETED, $breakdown->fresh()->status);
    }

    public function test_instagram_job_fails_cleanly_when_captapi_has_no_media_url(): void
    {
        Exceptions::fake();
        $video = $this->makeInstagramVideo();
        $breakdown = OutlierBreakdown::create(['platform' => 'instagram', 'video_id' => 'DVzhP3Cj8ZL', 'status' => OutlierBreakdown::STATUS_PENDING]);

        $this->mock(CaptApiOutlierService::class, fn ($m) => $m->shouldReceive('fetchAndStore')->once()->andReturn($video));
        $this->mock(GeminiVideoService::class, fn ($m) => $m->shouldNotReceive('breakdown'));

        app()->call([new GenerateOutlierBreakdownJob('instagram', 'DVzhP3Cj8ZL'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_FAILED, $breakdown->status);
        $this->assertStringContainsString("couldn't download this video", $breakdown->error);
        Exceptions::assertReported(fn (GeminiVideoException $e) => $e->reason === GeminiVideoException::MEDIA_UNAVAILABLE);
    }
}
