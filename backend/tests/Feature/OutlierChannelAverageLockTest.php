<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A channel's outlier baseline (the median views of its recent uploads: one
 * playlistItems.list + one videos.list) is refreshed under a lock, so the
 * search jobs that share a channel fetch it once and the rest read the row.
 */
class OutlierChannelAverageLockTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL = 'UClockedchannel';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.youtube.key', 'yt_test_key');
    }

    private function channelItem(): array
    {
        return [
            'id' => self::CHANNEL,
            'snippet' => ['title' => 'Locked Channel', 'thumbnails' => ['high' => ['url' => 'https://example.com/av.jpg']]],
            'statistics' => ['subscriberCount' => '9000', 'videoCount' => '3'],
        ];
    }

    private function fakeUploads(array $views = [1000, 2000, 3000]): void
    {
        $ids = array_map(fn ($i) => "vid{$i}", array_keys($views));
        Http::fake([
            '*/youtube/v3/channels*' => Http::response(['items' => [$this->channelItem()]], 200),
            '*/youtube/v3/playlistItems*' => Http::response(['items' => array_map(fn ($id) => ['contentDetails' => ['videoId' => $id]], $ids)], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => array_map(fn ($id, $v) => [
                'id' => $id, 'snippet' => ['title' => $id], 'statistics' => ['viewCount' => (string) $v], 'contentDetails' => ['duration' => 'PT5M'],
            ], $ids, $views)], 200),
        ]);
    }

    private function calls(string $path): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), $path))->count();
    }

    public function test_a_second_call_for_the_same_channel_reads_the_row_the_first_one_wrote(): void
    {
        $this->fakeUploads();
        $service = app(YouTubeChannelService::class);

        $first = $service->getOutlierChannelAverage(self::CHANNEL);
        $second = $service->getOutlierChannelAverage(self::CHANNEL);

        $this->assertSame('api_fresh', $first['source']);
        $this->assertSame('db_cache', $second['source']);
        $this->assertEquals(2000, $second['average']);
        $this->assertSame(1, $this->calls('/playlistItems'));
        $this->assertSame(1, $this->calls('/videos'));
        $this->assertSame(1, OutlierChannel::count());
    }

    public function test_the_channel_is_locked_while_its_uploads_are_fetched(): void
    {
        $key = YouTubeChannelService::outlierAverageLockKey(self::CHANNEL);
        $heldDuringFetch = null;
        Http::fake([
            '*/youtube/v3/channels*' => Http::response(['items' => [$this->channelItem()]], 200),
            '*/youtube/v3/playlistItems*' => function () use ($key, &$heldDuringFetch) {
                // Anyone else asking right now cannot take the lock…
                $heldDuringFetch = ! Cache::lock($key, 1)->get();

                return Http::response(['items' => []], 200);
            },
        ]);

        app(YouTubeChannelService::class)->getOutlierChannelAverage(self::CHANNEL);

        $this->assertTrue($heldDuringFetch);
        // …and it is free again once the row is written.
        $lock = Cache::lock($key, 1);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_a_channel_without_an_uploads_playlist_is_cached_as_empty(): void
    {
        Http::fake([
            '*/youtube/v3/channels*' => Http::response(['items' => [$this->channelItem()]], 200),
            '*/youtube/v3/playlistItems*' => Http::response(['error' => ['code' => 404]], 404),
        ]);
        $service = app(YouTubeChannelService::class);

        $first = $service->getOutlierChannelAverage(self::CHANNEL);
        $second = $service->getOutlierChannelAverage(self::CHANNEL);

        $this->assertEquals(0, $first['average']);
        $this->assertSame('db_cache', $second['source']);
        $this->assertSame(1, $this->calls('/playlistItems'));
        $this->assertSame(0, $this->calls('/videos'));
    }
}
