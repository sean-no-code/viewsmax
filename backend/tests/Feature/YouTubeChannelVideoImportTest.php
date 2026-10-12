<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The connected channel's own uploads, imported on the YouTube OAuth exchange
 * and by videos:sync. Sentry flagged the import as an N+1 (a lookup by
 * youtube_video_id per video), so it must stay a fixed number of queries.
 */
class YouTubeChannelVideoImportTest extends TestCase
{
    use RefreshDatabase;

    private function fakeVideos(array $ids, int $views = 100): void
    {
        Http::fake([
            '*/youtube/v3/search*' => Http::response(['items' => array_map(
                fn ($id) => ['id' => ['videoId' => $id]], $ids
            )], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => array_map(fn ($id) => [
                'id' => $id,
                'snippet' => ['title' => "Video {$id}", 'publishedAt' => '2026-06-01T10:00:00Z'],
                'statistics' => ['viewCount' => (string) $views],
                'contentDetails' => ['duration' => 'PT5M', 'caption' => 'true'],
            ], $ids)], 200),
        ]);
    }

    private function channel(): Channel
    {
        return Channel::create(['user_id' => User::factory()->create()->id, 'youtube_channel_id' => 'UCmine']);
    }

    public function test_imports_new_and_updates_existing_videos_without_a_query_per_video(): void
    {
        $channel = $this->channel();
        Video::create(['youtube_video_id' => 'v1', 'channel_id' => $channel->id, 'title' => 'Old title', 'view_count' => 1]);

        $ids = array_map(fn ($i) => "v{$i}", range(1, 40));
        $this->fakeVideos($ids, 500);

        DB::enableQueryLog();
        $result = app(YouTubeChannelService::class)->fetchAndImportChannelVideos($channel, 'token');
        $videoQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"videos"'));

        $this->assertSame(['imported' => 39, 'updated' => 1, 'total' => 40], $result);
        $this->assertLessThanOrEqual(2, $videoQueries->count(), 'one existence count + one upsert');

        $this->assertSame(40, Video::where('channel_id', $channel->id)->count());
        $updated = Video::where('youtube_video_id', 'v1')->first();
        $this->assertSame('Video v1', $updated->title);
        $this->assertSame(500, $updated->view_count);
        $this->assertTrue($updated->has_captions);
        $this->assertSame('2026-06-01', $updated->published_at->toDateString());
    }

    public function test_an_empty_channel_imports_nothing(): void
    {
        $this->fakeVideos([]);

        $result = app(YouTubeChannelService::class)->fetchAndImportChannelVideos($this->channel(), 'token');

        $this->assertSame(['imported' => 0, 'updated' => 0, 'total' => 0], $result);
    }
}
