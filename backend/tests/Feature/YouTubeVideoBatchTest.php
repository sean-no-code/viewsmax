<?php

namespace Tests\Feature;

use App\Services\YouTubeChannelService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeVideoBatchTest extends TestCase
{
    public function test_video_details_requests_are_chunked_to_fifty_ids(): void
    {
        config()->set('services.youtube.key', 'yt_test_key');

        Http::fake(['*/youtube/v3/videos*' => function ($request) {
            $ids = array_filter(explode(',', $request->data()['id'] ?? ''));
            // videos.list rejects >50 ids with "invalid filter parameter".
            $this->assertLessThanOrEqual(50, count($ids), 'videos.list called with more than 50 ids');

            return Http::response(['items' => array_map(fn ($id) => ['id' => $id], $ids)], 200);
        }]);

        $ids = array_map(fn ($i) => "vid{$i}", range(1, 87));
        $items = app(YouTubeChannelService::class)->getVideoDetailsWithApiKey($ids);

        $this->assertCount(87, $items); // all ids fetched across chunks
        Http::assertSentCount(2);       // 50 + 37
    }
}
