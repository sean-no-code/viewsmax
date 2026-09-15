<?php

namespace Tests\Feature;

use App\Jobs\ClassifyOutlierVideoFormatsJob;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Add a creator channel" for YouTube: resolve an @handle with the Data API,
 * pull the uploads playlist, store the recent videos scored against their
 * median, and hand Shorts classification to the probe job.
 */
class YouTubeChannelIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.youtube.key', 'yt-test-key');
        Queue::fake();
    }

    private function videoItem(string $id, int $views, string $duration = 'PT12M4S'): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'title' => "Video {$id}", 'description' => 'desc', 'publishedAt' => '2026-06-01T10:00:00Z',
                'thumbnails' => ['default' => ['url' => "https://i.ytimg.com/{$id}/default.jpg"], 'medium' => ['url' => "https://i.ytimg.com/{$id}/mq.jpg"]],
            ],
            'statistics' => ['viewCount' => (string) $views, 'likeCount' => '10', 'commentCount' => '2'],
            'contentDetails' => ['duration' => $duration],
        ];
    }

    private function fakeChannel(array $videoItems, string $channelId = 'UCabc123'): void
    {
        Http::fake([
            '*/youtube/v3/channels*' => function ($request) use ($channelId) {
                // forHandle lookup and the by-id details call share the endpoint.
                $item = [
                    'id' => $channelId,
                    'snippet' => ['title' => 'Creator', 'customUrl' => '@Creator', 'country' => 'gb',
                        'thumbnails' => ['high' => ['url' => 'https://yt.example/avatar.jpg']]],
                    'statistics' => ['subscriberCount' => '250000', 'videoCount' => '410'],
                ];
                $known = str_contains($request->url(), 'forHandle=%40creator') || str_contains($request->url(), 'forHandle=creator')
                    || str_contains($request->url(), "id={$channelId}");

                return Http::response(['items' => $known ? [$item] : []], 200);
            },
            '*/youtube/v3/playlistItems*' => Http::response(['items' => array_map(
                fn ($v) => ['contentDetails' => ['videoId' => $v['id']]], $videoItems
            )], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => $videoItems], 200),
        ]);
    }

    public function test_resolves_a_handle_to_a_channel_id(): void
    {
        $this->fakeChannel([]);
        $service = app(YouTubeChannelService::class);

        $this->assertSame('UCabc123', $service->resolveChannelId('creator', 'handle'));
        $this->assertNull($service->resolveChannelId('nobody', 'handle'));
        // Channel ids need no lookup at all.
        $this->assertSame('UCdirect', $service->resolveChannelId('UCdirect', 'channel_id'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'id=UCdirect'));
    }

    public function test_ingests_recent_videos_scored_against_the_median_and_queues_classification(): void
    {
        // Median of [100, 200, 300, 4000, 500] = 300.
        $this->fakeChannel([
            $this->videoItem('a1', 100), $this->videoItem('a2', 200), $this->videoItem('a3', 300),
            $this->videoItem('a4', 4000), $this->videoItem('a5', 500, 'PT0M45S'),
        ]);

        $result = app(YouTubeChannelService::class)->ingestChannelRecentVideos('UCabc123', 30);

        $channel = $result['channel'];
        $this->assertSame('UCabc123', $channel->youtube_channel_id);
        $this->assertSame('creator', $channel->handle);       // from snippet.customUrl, normalised
        $this->assertSame('Creator', $channel->channel_name);
        $this->assertSame(250000, (int) $channel->subscriber_count);
        $this->assertSame('GB', $channel->country);
        $this->assertSame(300, (int) $channel->average_views);
        $this->assertEqualsCanonicalizing(['a1', 'a2', 'a3', 'a4', 'a5'], $result['video_ids']);

        // Every video is kept even though most are below the 20x outlier gate.
        $this->assertSame(5, OutlierVideo::where('channel_id', $channel->id)->count());
        $hit = OutlierVideo::where('youtube_video_id', 'a4')->first();
        $this->assertEqualsWithDelta(13.33, $hit->outlier_score, 0.01);
        $this->assertSame('Video a4', $hit->title);
        $this->assertSame('https://i.ytimg.com/a4/mq.jpg', $hit->thumbnail_medium_url);
        $this->assertFalse($hit->manually_added);
        // The Shorts probe is authoritative — nothing is guessed from duration here.
        $this->assertNull($hit->is_short);
        $this->assertNull(OutlierVideo::where('youtube_video_id', 'a5')->value('is_short'));

        Queue::assertPushed(ClassifyOutlierVideoFormatsJob::class, fn ($job) => count($job->videoIds) === 5);
    }

    public function test_reuses_an_existing_channel_row_and_rescores_its_videos(): void
    {
        $existing = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCabc123', 'channel_name' => 'Old name']);
        $old = OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $existing->id, 'youtube_video_id' => 'old1',
            'title' => 'Old', 'views' => 3000, 'outlier_score' => 1.0, 'published_at' => now(),
        ]);
        $this->fakeChannel([$this->videoItem('a1', 100), $this->videoItem('a2', 300), $this->videoItem('a3', 500)]);

        $result = app(YouTubeChannelService::class)->ingestChannelRecentVideos('UCabc123');

        $this->assertSame($existing->id, $result['channel']->id);
        $this->assertSame('Creator', $result['channel']->channel_name);
        $this->assertEqualsWithDelta(10.0, $old->fresh()->outlier_score, 0.01);
    }

    public function test_unknown_channel_id_is_a_user_safe_error(): void
    {
        $this->fakeChannel([], 'UCother');

        $this->expectException(\InvalidArgumentException::class);
        app(YouTubeChannelService::class)->ingestChannelRecentVideos('UCmissing');
    }
}
