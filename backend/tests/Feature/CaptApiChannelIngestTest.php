<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Services\CaptApiOutlierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Add a creator channel" via CaptAPI's channel-details + channel-posts /
 * channel-reels endpoints: one call pulls the creator's recent videos and
 * scores them against each other, so the channel gets a real baseline on day one.
 */
class CaptApiChannelIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.captapi.api_key', 'capt_test_key');
        config()->set('services.captapi.base_url', 'https://api.captapi.com/v1');
        // Thumbnails are copied onto the media disk at ingest.
        Storage::fake('public');
        config()->set('filesystems.media_disk', 'public');
        Http::fake(['example.com/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
    }

    private function tiktokPost(string $id, int $views, string $caption = 'A post'): array
    {
        return [
            'id' => $id,
            'url' => "https://www.tiktok.com/@khaby.lame/video/{$id}",
            'caption' => $caption,
            'publishedAt' => '2026-06-02T14:56:35.000Z',
            'durationSeconds' => 29.5,
            'thumbnailUrl' => "https://example.com/{$id}.jpg",
            'author' => [
                'id' => '127905465618821121', 'username' => 'khaby.lame', 'displayName' => 'Khabane lame',
                'followers' => 162476412, 'profileImage' => 'https://example.com/avatar.jpg',
            ],
            'engagement' => ['views' => $views, 'likes' => 10, 'comments' => 2, 'shares' => 1, 'saves' => 1],
        ];
    }

    private function fakeTiktokChannel(array $posts): void
    {
        Http::fake([
            '*/tiktok/channel-details*' => Http::response(['success' => true, 'data' => [
                'platform' => 'tiktok', 'id' => '127905465618821121', 'handle' => 'khaby.lame', 'displayName' => 'Khabane lame',
                'followers' => 162476412, 'postCount' => 1200, 'avatar' => 'https://example.com/avatar.jpg', 'verified' => true,
            ]], 200),
            '*/tiktok/channel-posts*' => Http::response(['success' => true, 'data' => [
                'items' => $posts, 'nextCursor' => null, 'hasMore' => false,
            ]], 200),
        ]);
    }

    public function test_tiktok_channel_ingest_stores_channel_and_videos_scored_against_the_batch_median(): void
    {
        // Median of [100, 200, 300, 4000, 500] = 300.
        $this->fakeTiktokChannel([
            $this->tiktokPost('1', 100), $this->tiktokPost('2', 200), $this->tiktokPost('3', 300),
            $this->tiktokPost('4', 4000, "Huge hit\nsecond line"), $this->tiktokPost('5', 500),
        ]);

        $result = app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame', 30);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/tiktok/channel-posts')
            && $r['url'] === '@khaby.lame' && (int) $r['limit'] === 30);

        $channel = $result['channel'];
        $this->assertSame('tiktok', $channel->platform);
        $this->assertSame('127905465618821121', $channel->youtube_channel_id); // keyed like single-video ingest
        $this->assertSame('khaby.lame', $channel->handle);
        $this->assertSame('Khabane lame', $channel->channel_name);
        $this->assertSame(162476412, (int) $channel->subscriber_count);
        $this->assertSame(1200, (int) $channel->video_count);
        $this->assertSame(300, (int) $channel->average_views);
        $this->assertNotNull($channel->average_calculated_at);
        $this->assertEqualsCanonicalizing(['1', '2', '3', '4', '5'], $channel->average_video_ids);
        $this->assertEqualsCanonicalizing(['1', '2', '3', '4', '5'], $result['video_ids']);

        $hit = OutlierVideo::where('platform', 'tiktok')->where('youtube_video_id', '4')->first();
        $this->assertSame($channel->id, $hit->channel_id);
        $this->assertEqualsWithDelta(13.3, $hit->outlier_score, 0.01);
        $this->assertSame('Huge hit', $hit->title);
        $this->assertSame(4000, $hit->views);
        $this->assertTrue($hit->is_short);
        // The CDN thumbnail was copied onto our media disk and the row points there.
        Storage::disk('public')->assertExists('outliers/thumbs/tiktok/4.jpg');
        $this->assertStringContainsString('/outliers/thumbs/tiktok/4.jpg', $hit->thumbnail_url);
        $this->assertSame($hit->thumbnail_url, $hit->thumbnail_medium_url);
        // Bulk rows are NOT deliberate single adds — they must not bypass the
        // browse min-score gate for every other user.
        $this->assertFalse($hit->manually_added);

        $this->assertEqualsWithDelta(0.3, OutlierVideo::where('youtube_video_id', '1')->value('outlier_score'), 0.01);
        $this->assertSame(5, OutlierVideo::where('channel_id', $channel->id)->count());
    }

    public function test_instagram_channel_ingest_keys_by_username_and_shortcode(): void
    {
        $reel = fn (string $code, int $views) => [
            'id' => '9'.$code, 'shortcode' => $code, 'mediaId' => '9'.$code,
            'url' => "https://www.instagram.com/reel/{$code}/",
            'caption' => "Reel {$code}", 'publishedAt' => '2026-07-29T15:12:56.000Z', 'durationSeconds' => 37.4,
            'thumbnailUrl' => "https://example.com/{$code}.jpg", 'videoUrl' => "https://cdn.example.com/{$code}.mp4",
            'videoUrlExpiresAt' => '2099-01-01T00:00:00.000Z',
            'author' => ['id' => '38480006832', 'username' => 'nasa', 'displayName' => 'NASA', 'followers' => 642, 'avatar' => 'https://example.com/nasa.jpg'],
            'engagement' => ['views' => $views, 'likes' => 5, 'comments' => 1],
        ];
        Http::fake([
            '*/instagram/channel-details*' => Http::response(['success' => true, 'data' => [
                'platform' => 'instagram', 'username' => 'nasa', 'displayName' => 'NASA', 'followers' => 642,
                'postCount' => 4000, 'profileImage' => 'https://example.com/nasa.jpg', 'isBusinessAccount' => true,
            ]], 200),
            '*/instagram/channel-reels*' => Http::response(['success' => true, 'data' => [
                'items' => [$reel('AAA', 100), $reel('BBB', 1000), $reel('CCC', 200)],
            ]], 200),
        ]);

        $result = app(CaptApiOutlierService::class)->ingestChannel('instagram', 'nasa');

        $channel = $result['channel'];
        $this->assertSame('instagram', $channel->platform);
        $this->assertSame('nasa', $channel->youtube_channel_id);
        $this->assertSame('nasa', $channel->handle);
        $this->assertSame(200, (int) $channel->average_views);

        $big = OutlierVideo::where('platform', 'instagram')->where('youtube_video_id', 'BBB')->first();
        $this->assertNotNull($big, 'Instagram rows are keyed by shortcode');
        $this->assertSame(5.0, $big->outlier_score);
        $this->assertSame('https://cdn.example.com/BBB.mp4', $big->video_url);
        $this->assertTrue($big->video_url_expires_at->isFuture());
        $this->assertTrue($big->is_short);
        $this->assertFalse($big->manually_added);
    }

    public function test_pre_existing_videos_of_the_channel_are_rescored_against_the_new_median(): void
    {
        // A single earlier paste created the channel with a score of 1.0 (no baseline).
        $existingChannel = OutlierChannel::create([
            'platform' => 'tiktok', 'youtube_channel_id' => '127905465618821121', 'channel_name' => 'Khabane lame',
        ]);
        $earlier = OutlierVideo::create([
            'platform' => 'tiktok', 'channel_id' => $existingChannel->id, 'youtube_video_id' => '999',
            'title' => 'Earlier paste', 'views' => 3000, 'outlier_score' => 1.0, 'published_at' => now(), 'manually_added' => true,
        ]);

        $this->fakeTiktokChannel([$this->tiktokPost('1', 100), $this->tiktokPost('2', 300), $this->tiktokPost('3', 500)]);

        $result = app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame');

        $this->assertSame($existingChannel->id, $result['channel']->id, 'existing channel row is reused');
        $this->assertSame(10.0, $earlier->fresh()->outlier_score);
        $this->assertTrue($earlier->fresh()->manually_added, 'deliberate single adds keep their flag');
    }

    public function test_single_video_ingest_uses_the_channel_average_once_one_exists(): void
    {
        $this->fakeTiktokChannel([$this->tiktokPost('1', 100), $this->tiktokPost('2', 300), $this->tiktokPost('3', 500)]);
        app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame');

        // Now a single pasted video from the same creator: scored against the
        // channel median (300), not the mean of stored rows (300 here too, but
        // the source of truth is the channel row — make it differ to prove it).
        OutlierChannel::where('youtube_channel_id', '127905465618821121')->update(['average_views' => 1000]);
        Http::fake(['*/tiktok/video-details*' => Http::response(['success' => true, 'data' => $this->tiktokPost('7', 5000)], 200)]);

        $video = app(CaptApiOutlierService::class)->fetchAndStore('tiktok', 'https://www.tiktok.com/@khaby.lame/video/7');

        $this->assertSame(5.0, $video->outlier_score);
    }

    public function test_provider_error_message_is_surfaced_as_a_user_safe_exception(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Profile not found']], 404)]);

        try {
            app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'nobody');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertSame(\RuntimeException::class, get_class($e));
            $this->assertSame('Profile not found', $e->getMessage());
        }
    }

    public function test_thumbnail_rehost_falls_back_to_the_cdn_url_when_the_download_is_not_an_image(): void
    {
        Http::fake(['cdn-html.example.org/*' => Http::response('<html>blocked</html>', 200, ['Content-Type' => 'text/html'])]);
        $post = $this->tiktokPost('55', 100);
        $post['thumbnailUrl'] = 'https://cdn-html.example.org/55.jpg';
        $this->fakeTiktokChannel([$post]);

        app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame');

        $this->assertSame('https://cdn-html.example.org/55.jpg', OutlierVideo::where('youtube_video_id', '55')->value('thumbnail_url'));
        Storage::disk('public')->assertMissing('outliers/thumbs/tiktok/55.jpg');
    }

    public function test_thumbnail_rehost_can_be_switched_off(): void
    {
        config()->set('services.outliers.rehost_thumbnails', false);
        $this->fakeTiktokChannel([$this->tiktokPost('56', 100)]);

        app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame');

        $this->assertSame('https://example.com/56.jpg', OutlierVideo::where('youtube_video_id', '56')->value('thumbnail_url'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'example.com/56.jpg'));
    }

    public function test_backfill_command_rehosts_existing_cdn_thumbnails(): void
    {
        $ch = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'nasa', 'channel_name' => 'NASA']);
        $base = ['platform' => 'instagram', 'channel_id' => $ch->id, 'published_at' => now()];
        $cdn = OutlierVideo::create($base + ['youtube_video_id' => 'AAA', 'title' => 'a', 'thumbnail_url' => 'https://example.com/AAA.jpg', 'thumbnail_medium_url' => 'https://example.com/AAA.jpg']);
        $hosted = OutlierVideo::create($base + ['youtube_video_id' => 'BBB', 'title' => 'b', 'thumbnail_url' => 'http://localhost/storage/outliers/thumbs/instagram/BBB.jpg']);
        $none = OutlierVideo::create($base + ['youtube_video_id' => 'CCC', 'title' => 'c', 'thumbnail_url' => null]);
        Http::fake(['expired.example.org/*' => Http::response('', 403)]);
        $expired = OutlierVideo::create($base + ['youtube_video_id' => 'DDD', 'title' => 'd', 'thumbnail_url' => 'https://expired.example.org/DDD.jpg']);

        $this->artisan('outliers:rehost-thumbnails')
            ->expectsOutputToContain('Re-hosted 1 thumbnail(s), skipped 1')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('outliers/thumbs/instagram/AAA.jpg');
        $this->assertStringContainsString('/outliers/thumbs/instagram/AAA.jpg', $cdn->fresh()->thumbnail_url);
        $this->assertSame('http://localhost/storage/outliers/thumbs/instagram/BBB.jpg', $hosted->fresh()->thumbnail_url);
        $this->assertNull($none->fresh()->thumbnail_url);
        $this->assertSame('https://expired.example.org/DDD.jpg', $expired->fresh()->thumbnail_url);
    }

    public function test_channel_avatar_is_rehosted_at_ingest(): void
    {
        $this->fakeTiktokChannel([$this->tiktokPost('57', 100)]);

        app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame');

        $channel = OutlierChannel::where('platform', 'tiktok')->firstOrFail();
        Storage::disk('public')->assertExists('outliers/avatars/tiktok/127905465618821121.jpg');
        $this->assertStringContainsString('/outliers/avatars/tiktok/127905465618821121.jpg', $channel->profile_image_url);
    }

    public function test_pasted_instagram_url_rehosts_the_channel_avatar(): void
    {
        Http::fake(['*/instagram/details*' => Http::response(['success' => true, 'data' => [
            'platform' => 'instagram', 'id' => '1', 'shortcode' => 'DbYaLffE2DD', 'caption' => 'Suit up!',
            'publishedAt' => '2026-07-29T15:12:56.000Z', 'thumbnailUrl' => 'https://example.com/ig.jpg',
            'author' => ['id' => '38480006832', 'username' => 'nasa', 'displayName' => 'NASA', 'avatar' => 'https://example.com/nasa.jpg', 'followers' => 642],
            'engagement' => ['likes' => 1, 'comments' => 1, 'views' => 516],
        ]], 200)]);

        app(CaptApiOutlierService::class)->fetchAndStore('instagram', 'https://www.instagram.com/p/DbYaLffE2DD/');

        $channel = OutlierChannel::where('platform', 'instagram')->where('youtube_channel_id', 'nasa')->firstOrFail();
        Storage::disk('public')->assertExists('outliers/avatars/instagram/nasa.jpg');
        $this->assertStringContainsString('/outliers/avatars/instagram/nasa.jpg', $channel->profile_image_url);
    }

    public function test_avatar_rehost_falls_back_to_the_cdn_url_when_the_download_fails(): void
    {
        Http::fake(['blocked.example.org/*' => Http::response('', 403)]);
        $post = $this->tiktokPost('58', 100);
        Http::fake([
            '*/tiktok/channel-details*' => Http::response(['success' => true, 'data' => [
                'platform' => 'tiktok', 'id' => '127905465618821121', 'handle' => 'khaby.lame', 'displayName' => 'Khabane lame',
                'followers' => 1, 'postCount' => 1, 'avatar' => 'https://blocked.example.org/avatar.jpg',
            ]], 200),
            '*/tiktok/channel-posts*' => Http::response(['success' => true, 'data' => ['items' => [$post], 'nextCursor' => null, 'hasMore' => false]], 200),
        ]);

        app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame');

        $this->assertSame('https://blocked.example.org/avatar.jpg', OutlierChannel::where('platform', 'tiktok')->value('profile_image_url'));
        Storage::disk('public')->assertMissing('outliers/avatars/tiktok/127905465618821121.jpg');
    }

    public function test_backfill_command_rehosts_channel_avatars(): void
    {
        $cdn = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'raycfu', 'channel_name' => 'Ray', 'profile_image_url' => 'https://example.com/raycfu.jpg']);
        $hosted = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'nasa', 'channel_name' => 'NASA', 'profile_image_url' => 'http://localhost/storage/outliers/avatars/instagram/nasa.jpg']);
        $youtube = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UC123', 'channel_name' => 'YT', 'profile_image_url' => 'https://example.com/yt.jpg']);

        $this->artisan('outliers:rehost-thumbnails')
            ->expectsOutputToContain('Re-hosted 1 avatar(s)')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('outliers/avatars/instagram/raycfu.jpg');
        $this->assertStringContainsString('/outliers/avatars/instagram/raycfu.jpg', $cdn->fresh()->profile_image_url);
        $this->assertSame('http://localhost/storage/outliers/avatars/instagram/nasa.jpg', $hosted->fresh()->profile_image_url);
        $this->assertSame('https://example.com/yt.jpg', $youtube->fresh()->profile_image_url);
    }

    public function test_empty_channel_is_rejected(): void
    {
        $this->fakeTiktokChannel([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no public videos/i');

        app(CaptApiOutlierService::class)->ingestChannel('tiktok', 'khaby.lame');
    }
}
