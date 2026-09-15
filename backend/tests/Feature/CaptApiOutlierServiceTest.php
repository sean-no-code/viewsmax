<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\User;
use App\Services\CaptApiOutlierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaptApiOutlierServiceTest extends TestCase
{
    use RefreshDatabase;

    // outlier_db is kept alive suite-wide via TestCase::connectionsToTransact().

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.captapi.api_key', 'capt_test_key');
        config()->set('services.captapi.base_url', 'https://api.captapi.com/v1');
        // These tests count provider calls; thumbnail re-hosting is covered in CaptApiChannelIngestTest.
        config()->set('services.outliers.rehost_thumbnails', false);
    }

    private function fakeTiktok(): void
    {
        Http::fake(['*/tiktok/video-details*' => Http::response([
            'success' => true,
            'data' => [
                'platform' => 'tiktok',
                'id' => '7646812028874673439',
                'caption' => 'Thank you, please come again!!!🙋🏿‍♂️💸#learnfromkhaby #comedy',
                'publishedAt' => '2026-06-02T14:56:35.000Z',
                'durationSeconds' => 29.534,
                'thumbnailUrl' => 'https://example.com/tt.jpg',
                'author' => [
                    'id' => '127905465618821121',
                    'username' => 'khaby.lame',
                    'displayName' => 'Khabane lame',
                    'followers' => 162476412,
                    'verified' => true,
                    'profileImage' => 'https://example.com/avatar.jpg',
                ],
                'engagement' => [
                    'views' => 17042375,
                    'likes' => 1550817,
                    'comments' => 16306,
                    'shares' => 16050,
                    'saves' => 62013,
                ],
            ],
        ], 200)]);
    }

    private function fakeInstagram(): void
    {
        Http::fake(['*/instagram/details*' => Http::response([
            'success' => true,
            'data' => [
                'platform' => 'instagram',
                'id' => '3964781969342572967', // Instagram media pk — NOT what URLs use
                'shortcode' => 'DbYaLffE2DD',
                'postType' => 'carousel',
                'caption' => "Suit up!\n\nThese photos show NASA astronaut candidates preparing for training flights.",
                'publishedAt' => '2026-07-29T15:12:56.000Z',
                'durationSeconds' => 37.44,
                'thumbnailUrl' => 'https://example.com/ig.jpg',
                'videoUrl' => 'https://cdn.example.com/ig-fresh.mp4',
                'mediaUrlsExpireAt' => '2099-01-01T00:00:00.000Z',
                'author' => [
                    'id' => '38480006832',
                    'username' => 'nasa',
                    'displayName' => 'NASA',
                    'verified' => true,
                    'avatar' => 'https://example.com/nasa.jpg', // CaptAPI's current key (was profileImage)
                    'followers' => 642,
                ],
                'engagement' => [
                    'likes' => 34510,
                    'comments' => 178,
                    'views' => 516,
                ],
            ],
        ], 200)]);
    }

    public function test_tiktok_video_is_ingested_with_views_and_engagement(): void
    {
        $this->fakeTiktok();

        $video = app(CaptApiOutlierService::class)->fetchAndStore('tiktok', 'https://www.tiktok.com/@khaby.lame/video/7646812028874673439');

        $this->assertSame('tiktok', $video->platform);
        $this->assertSame('7646812028874673439', $video->youtube_video_id);
        $this->assertSame(17042375, $video->views);
        $this->assertSame(1550817, $video->like_count);
        $this->assertSame(16306, $video->comment_count);
        $this->assertSame('PT29S', $video->duration);
        $this->assertSame(29, $video->duration_in_seconds);
        // First video for the channel → score baselines at 1.0.
        $this->assertSame(1.0, $video->outlier_score);
        $this->assertNotNull($video->engagement_rate); // views known → rate computes.

        $channel = $video->channel;
        $this->assertSame('tiktok', $channel->platform);
        $this->assertSame('127905465618821121', $channel->youtube_channel_id);
        $this->assertSame(162476412, (int) $channel->subscriber_count);

        // TikTok is short-form by definition — flagged at ingest, no YouTube probe.
        $this->assertTrue($video->is_short);
        $this->assertNotNull($video->format_checked_at);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'youtube.com'));
    }

    public function test_instagram_video_is_ingested_with_views_score_and_followers(): void
    {
        $this->fakeInstagram();

        $video = app(CaptApiOutlierService::class)->fetchAndStore('instagram', 'https://www.instagram.com/p/DbYaLffE2DD/');

        $this->assertSame('instagram', $video->platform);
        // Stored under the URL shortcode, not the media pk — the breakdown page
        // and jobs look videos up by the id parsed from the pasted URL.
        $this->assertSame('DbYaLffE2DD', $video->youtube_video_id);
        // Parity with TikTok: views, engagement rate, and a views-vs-channel-average score.
        $this->assertSame(516, $video->views);
        $this->assertSame(1.0, $video->outlier_score);  // first video for the channel → baseline
        $this->assertNotNull($video->engagement_rate);
        $this->assertSame(34510, $video->like_count);
        $this->assertSame(178, $video->comment_count);
        $this->assertSame('Suit up!', $video->title);
        // Native-playback media + real duration now come through.
        $this->assertSame('https://cdn.example.com/ig-fresh.mp4', $video->video_url);
        $this->assertTrue($video->video_url_expires_at->isFuture());
        $this->assertSame('PT37S', $video->duration);

        $channel = $video->channel;
        $this->assertSame('instagram', $channel->platform);
        $this->assertSame('nasa', $channel->youtube_channel_id);
        $this->assertSame(642, (int) $channel->subscriber_count);
        $this->assertSame('https://example.com/nasa.jpg', $channel->profile_image_url);

        // Instagram is short-form by definition — flagged at ingest, no YouTube probe.
        $this->assertTrue($video->is_short);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'youtube.com'));
    }

    public function test_sparse_instagram_response_is_retried_for_a_full_one(): void
    {
        // CaptAPI's graphql path times out sometimes and falls back to an OG scrape
        // that has no views/followers/caption — retry once for the real thing.
        $sparse = ['success' => true, 'data' => [
            'platform' => 'instagram', 'id' => '1', 'shortcode' => 'DbYaLffE2DD', 'caption' => '',
            'author' => ['username' => 'nasa'], 'engagement' => [],
            'stages' => ['graphqlStatus' => 'timeout', 'decodoStatus' => 'og'],
        ]];
        $full = ['success' => true, 'data' => [
            'platform' => 'instagram', 'id' => '1', 'shortcode' => 'DbYaLffE2DD', 'caption' => 'Suit up!',
            'author' => ['username' => 'nasa', 'followers' => 642], 'engagement' => ['views' => 516, 'likes' => 3],
            'stages' => ['graphqlStatus' => 'ok'],
        ]];
        Http::fake(['*/instagram/details*' => Http::sequence()->push($sparse, 200)->push($full, 200)]);

        $video = app(CaptApiOutlierService::class)->fetchAndStore('instagram', 'https://www.instagram.com/p/DbYaLffE2DD/');

        Http::assertSentCount(2);
        $this->assertSame(516, $video->views);
        $this->assertSame('Suit up!', $video->title);
    }

    public function test_refresh_media_also_refetches_rows_missing_views(): void
    {
        // Fresh URL but no views (ingested from a sparse response) → still re-fetch.
        $this->seedInstagramRow('https://cdn.example.com/ig-ok.mp4', now()->addDay());
        $this->fakeInstagram();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/instagram/DbYaLffE2DD/refresh-media')
            ->assertOk()
            ->assertJsonPath('data.views', 516);

        Http::assertSentCount(1);
    }

    public function test_instagram_without_caption_gets_a_descriptive_title_not_untitled(): void
    {
        Http::fake(['*/instagram/details*' => Http::response([
            'success' => true,
            'data' => [
                'platform' => 'instagram', 'id' => '1', 'shortcode' => 'NoCap123456', 'caption' => '',
                'author' => ['username' => 'nasa', 'displayName' => 'NASA'], 'engagement' => ['views' => 10],
            ],
        ], 200)]);

        $video = app(CaptApiOutlierService::class)->fetchAndStore('instagram', 'https://www.instagram.com/p/NoCap123456/');

        $this->assertSame('Reel by @nasa', $video->title);
    }

    private function fakeTiktokNotFound(): void
    {
        // CaptAPI errors are structured objects, not strings.
        Http::fake(['*' => Http::response([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Video not found'],
        ], 404)]);
    }

    public function test_captapi_error_object_message_is_surfaced(): void
    {
        $this->fakeTiktokNotFound();

        try {
            app(CaptApiOutlierService::class)->fetchAndStore('tiktok', 'https://www.tiktok.com/@x/video/1');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Video not found', $e->getMessage());
        }
    }

    public function test_fetch_endpoint_surfaces_provider_error_message(): void
    {
        $this->fakeTiktokNotFound();

        // Short link → no parseable id → synchronous resolve path.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', [
                'platform' => 'tiktok',
                'url' => 'https://vm.tiktok.com/ZMabc123/',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Video not found');
    }

    private function seedInstagramRow(?string $videoUrl, ?string $expiresAt, ?int $views = null): OutlierVideo
    {
        $ch = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'nasa', 'channel_name' => 'NASA']);

        return OutlierVideo::create([
            'platform' => 'instagram', 'channel_id' => $ch->id, 'youtube_video_id' => 'DbYaLffE2DD',
            'title' => 'Suit up!', 'thumbnail_url' => 'x', 'published_at' => now(), 'views' => $views,
            'video_url' => $videoUrl, 'video_url_expires_at' => $expiresAt,
        ]);
    }

    public function test_refresh_media_refetches_when_url_is_expired(): void
    {
        $this->seedInstagramRow('https://cdn.example.com/ig-stale.mp4', now()->subHour());
        $this->fakeInstagram();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/instagram/DbYaLffE2DD/refresh-media')
            ->assertOk()
            ->assertJsonPath('data.video_url', 'https://cdn.example.com/ig-fresh.mp4');

        Http::assertSentCount(1);
    }

    public function test_refresh_media_skips_provider_when_url_is_fresh(): void
    {
        $this->seedInstagramRow('https://cdn.example.com/ig-ok.mp4', now()->addDay(), 999);
        $this->fakeInstagram();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/instagram/DbYaLffE2DD/refresh-media')
            ->assertOk()
            ->assertJsonPath('data.video_url', 'https://cdn.example.com/ig-ok.mp4');

        Http::assertNothingSent();
    }

    public function test_refetch_without_caption_keeps_existing_title(): void
    {
        $this->seedInstagramRow('https://cdn.example.com/ig-stale.mp4', now()->subHour());
        Http::fake(['*/instagram/details*' => Http::response([
            'success' => true,
            'data' => [
                'platform' => 'instagram', 'id' => '3964781969342572967', 'shortcode' => 'DbYaLffE2DD',
                'caption' => '', // provider sometimes omits it on re-fetch
                'videoUrl' => 'https://cdn.example.com/ig-fresh.mp4', 'mediaUrlsExpireAt' => '2099-01-01T00:00:00.000Z',
                'author' => ['username' => 'nasa'], 'engagement' => [],
            ],
        ], 200)]);

        $video = app(CaptApiOutlierService::class)->fetchAndStore('instagram', 'https://www.instagram.com/p/DbYaLffE2DD/');

        $this->assertSame('Suit up!', $video->title);                                  // kept
        $this->assertSame('https://cdn.example.com/ig-fresh.mp4', $video->video_url); // refreshed
    }

    public function test_unsupported_platform_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CaptApiOutlierService::class)->fetchAndStore('youtube', 'https://youtube.com/watch?v=x');
    }

    private function authHeaders(): array
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_fetch_endpoint_queues_ingest_for_parseable_url(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', [
                'platform' => 'tiktok',
                'url' => 'https://www.tiktok.com/@khaby.lame/video/7646812028874673439',
            ])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('video_id', '7646812028874673439');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\IngestOutlierByUrlJob::class);
    }

    public function test_fetch_endpoint_returns_existing_video_without_queueing(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->fakeTiktok();
        app(CaptApiOutlierService::class)->fetchAndStore('tiktok', 'https://www.tiktok.com/@khaby.lame/video/7646812028874673439');

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', [
                'platform' => 'tiktok',
                'url' => 'https://www.tiktok.com/@khaby.lame/video/7646812028874673439',
            ])
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('data.youtube_video_id', '7646812028874673439');

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_ingest_job_stores_video_and_chains_breakdown(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->fakeTiktok();

        app()->call([new \App\Jobs\IngestOutlierByUrlJob('tiktok', 'https://www.tiktok.com/@khaby.lame/video/7646812028874673439', '7646812028874673439'), 'handle']);

        $this->assertNotNull(OutlierVideo::where('platform', 'tiktok')->where('youtube_video_id', '7646812028874673439')->first());
        $breakdown = \App\Models\OutlierBreakdown::where('platform', 'tiktok')->where('video_id', '7646812028874673439')->first();
        $this->assertSame(\App\Models\OutlierBreakdown::STATUS_PENDING, $breakdown->status);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateOutlierBreakdownJob::class);
    }

    public function test_ingest_job_records_failure_on_breakdown_row(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->fakeTiktokNotFound();

        app()->call([new \App\Jobs\IngestOutlierByUrlJob('tiktok', 'https://www.tiktok.com/@x/video/1', '1'), 'handle']);

        $breakdown = \App\Models\OutlierBreakdown::where('platform', 'tiktok')->where('video_id', '1')->first();
        $this->assertSame(\App\Models\OutlierBreakdown::STATUS_FAILED, $breakdown->status);
        $this->assertSame('Download failed: Video not found', $breakdown->error);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\GenerateOutlierBreakdownJob::class);
    }

    public function test_short_tiktok_link_resolves_to_canonical_url_and_queues(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        // CaptAPI rejects vm.tiktok.com links outright — we must resolve the
        // share redirect to the canonical /@user/video/{id} URL ourselves.
        Http::fake([
            'vm.tiktok.com/*' => Http::response('', 301, ['Location' => 'https://www.tiktok.com/@khaby.lame/video/7646812028874673439?_r=1']),
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', ['platform' => 'tiktok', 'url' => 'https://vm.tiktok.com/ZN8N6uqhn/'])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('video_id', '7646812028874673439');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\IngestOutlierByUrlJob::class);
    }

    public function test_short_link_redirecting_off_tiktok_is_not_followed(): void
    {
        Http::fake([
            'vm.tiktok.com/*' => Http::response('', 301, ['Location' => 'https://evil.example.com/whatever']),
            '*' => Http::response(['success' => false, 'error' => ['code' => 'BAD_REQUEST', 'message' => 'Invalid TikTok URL.']], 400),
        ]);

        // Resolution refuses the off-platform redirect → sync fallback → provider 422.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', ['platform' => 'tiktok', 'url' => 'https://vm.tiktok.com/ZNevil/'])
            ->assertStatus(422);
    }

    public function test_fetch_endpoint_timeout_returns_friendly_message(): void
    {
        // Short links have no parseable id → resolved synchronously → timeouts must
        // not leak raw cURL errors (ConnectionException is a RuntimeException).
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 60003 milliseconds'));

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', ['platform' => 'tiktok', 'url' => 'https://vm.tiktok.com/ZMabc123/'])
            ->assertStatus(504);

        $this->assertStringNotContainsString('cURL', $response->json('error'));
    }

    public function test_channels_endpoint_lists_only_the_requested_platform(): void
    {
        OutlierChannel::create(['platform' => 'tiktok', 'youtube_channel_id' => 'tt1', 'channel_name' => 'Creator One', 'subscriber_count' => 5000]);
        OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'ig1', 'channel_name' => 'Grammer', 'subscriber_count' => null]);

        $data = $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/channels?platform=tiktok')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('tt1', $data[0]['id']);
        $this->assertSame('Creator One', $data[0]['name']);
    }

    public function test_index_returns_instagram_videos_despite_null_score(): void
    {
        $ig = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'nasa', 'channel_name' => 'NASA', 'subscriber_count' => null]);
        OutlierVideo::create([
            'platform' => 'instagram', 'channel_id' => $ig->id, 'youtube_video_id' => 'IG1',
            'title' => 'Reel', 'views' => null, 'like_count' => 10, 'comment_count' => 5,
            'outlier_score' => null, 'published_at' => now(),
        ]);

        $data = $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?platform=instagram')
            ->assertOk()
            ->json('data');

        // The null-score IG video is not filtered out by the score gate.
        $this->assertCount(1, $data);
        $this->assertSame('IG1', $data[0]['youtube_video_id']);
        $this->assertNull($data[0]['views']);
        $this->assertSame(5, $data[0]['comment_count']);
    }
}
