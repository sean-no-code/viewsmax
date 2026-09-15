<?php

namespace Tests\Feature;

use App\Jobs\GenerateOutlierBreakdownJob;
use App\Jobs\IngestOutlierByUrlJob;
use App\Models\OutlierBreakdown;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\SavedOutlier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MCP tools for the Outliers area. They reuse the REST controllers, so plan
 * gates, validation and ownership behave exactly like the web app.
 */
class McpOutlierToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function mcpKey(User $user, string $access = 'full'): string
    {
        $login = $user->createToken('mobile-app')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $login])
            ->postJson('/api/user/api-key/rotate', ['access' => $access])
            ->json('data.key');
    }

    private function callTool(string $key, string $tool, array $arguments = []): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Accept' => 'application/json',
        ])->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    private function toolJson(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse(
            $response->json('result.isError') ?? false,
            'Tool returned error: ' . json_encode($response->json('result'))
        );

        return json_decode($response->json('result.content.0.text'), true);
    }

    private function assertToolError(TestResponse $response, ?string $contains = null): void
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'Expected a tool error');
        if ($contains !== null) {
            $this->assertStringContainsString($contains, $response->json('result.content.0.text'));
        }
    }

    private function seedOutlier(string $platform = 'youtube', string $videoId = 'vid1', array $extra = []): OutlierVideo
    {
        $channel = OutlierChannel::create([
            'platform' => $platform, 'youtube_channel_id' => 'chan-' . $videoId, 'channel_name' => 'Creator ' . $videoId,
            'subscriber_count' => 12000, 'average_views' => 2000, 'country' => 'US',
        ]);

        return OutlierVideo::create($extra + [
            'platform' => $platform, 'channel_id' => $channel->id, 'youtube_video_id' => $videoId,
            'title' => 'Outlier ' . $videoId, 'thumbnail_url' => 'https://example.com/t.jpg',
            'views' => 100000, 'like_count' => 5000, 'comment_count' => 300,
            'outlier_score' => 50, 'published_at' => now()->subDays(3),
        ]);
    }

    public function test_outlier_tools_are_listed_and_scoped(): void
    {
        $names = fn (string $key) => collect($this->withHeaders(['Authorization' => 'Bearer ' . $key])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50]])
            ->json('result.tools'))->pluck('name');

        $full = $names($this->mcpKey(User::factory()->create()));
        foreach ([
            'list_outliers', 'search_outliers', 'get_outlier', 'fetch_outlier',
            'get_outlier_breakdown', 'generate_outlier_breakdown',
            'list_saved_outliers', 'save_outlier', 'remove_saved_outlier',
            'add_outlier_channel', 'get_outlier_channel_ingest',
        ] as $tool) {
            $this->assertContains($tool, $full, "Missing tool {$tool}");
        }

        // Read-only keys see the read tools and none of the writes.
        $read = $names($this->mcpKey(User::factory()->create(), 'read'));
        foreach (['list_outliers', 'get_outlier', 'get_outlier_breakdown', 'list_saved_outliers', 'get_outlier_channel_ingest'] as $tool) {
            $this->assertContains($tool, $read);
        }
        foreach (['search_outliers', 'fetch_outlier', 'generate_outlier_breakdown', 'save_outlier', 'remove_saved_outlier', 'add_outlier_channel'] as $tool) {
            $this->assertNotContains($tool, $read, "Read-only key should not see {$tool}");
        }

        // Feature flag off → neither channel tool is advertised.
        config()->set('services.outliers.channel_ingest_enabled', false);
        $flagged = $names($this->mcpKey(User::factory()->create()));
        $this->assertNotContains('add_outlier_channel', $flagged);
        $this->assertNotContains('get_outlier_channel_ingest', $flagged);
    }

    public function test_add_outlier_channel_queues_an_ingest_and_can_be_polled(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $queued = $this->toolJson($this->callTool($key, 'add_outlier_channel', ['input' => 'https://www.tiktok.com/@khaby.lame']));
        $this->assertTrue($queued['queued']);
        $this->assertSame('queued', $queued['status']);
        $this->assertSame('tiktok', $queued['platform']);
        $this->assertSame('khaby.lame', $queued['handle']);
        $this->assertIsInt($queued['ingest_id']);
        Queue::assertPushed(\App\Jobs\IngestOutlierChannelJob::class, 1);

        $polled = $this->toolJson($this->callTool($key, 'get_outlier_channel_ingest', ['ingest_id' => $queued['ingest_id']]));
        $this->assertSame('queued', $polled['status']);
        $this->assertNull($polled['channel']);

        // Video links and bare handles without a platform are rejected with guidance.
        $this->assertToolError($this->callTool($key, 'add_outlier_channel', ['input' => 'https://www.tiktok.com/@x/video/1']), 'video link');
        $this->assertToolError($this->callTool($key, 'add_outlier_channel', ['input' => '@someone']), 'platform');

        // Someone else's ingest is not visible.
        $otherKey = $this->mcpKey(User::factory()->create());
        $this->assertToolError($this->callTool($otherKey, 'get_outlier_channel_ingest', ['ingest_id' => $queued['ingest_id']]));
    }

    public function test_list_outliers_returns_serialized_videos_with_channel(): void
    {
        $this->seedOutlier('youtube', 'yt1');
        $this->seedOutlier('tiktok', 'tt1', ['outlier_score' => 80]);
        $key = $this->mcpKey(User::factory()->create());

        $data = $this->toolJson($this->callTool($key, 'list_outliers', ['min_score' => 20, 'per_page' => 10]));

        $this->assertSame('done', $data['status']);
        $this->assertCount(2, $data['outliers']);
        $this->assertSame(10, $data['per_page']);

        $yt = collect($data['outliers'])->firstWhere('video_id', 'yt1');
        $this->assertSame('youtube', $yt['platform']);
        $this->assertSame('https://www.youtube.com/watch?v=yt1', $yt['url']);
        $this->assertSame(100000, $yt['views']);
        $this->assertEquals(50, $yt['outlier_score']);
        $this->assertSame('Creator yt1', $yt['channel']['name']);
        $this->assertSame(12000, $yt['channel']['subscriber_count']);
        $this->assertSame('US', $yt['channel']['country']);

        // Platform filter narrows the feed.
        $only = $this->toolJson($this->callTool($key, 'list_outliers', ['platform' => 'tiktok']));
        $this->assertSame(['tt1'], array_column($only['outliers'], 'video_id'));
    }

    public function test_list_outliers_rejects_bad_arguments(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $this->assertToolError($this->callTool($key, 'list_outliers', ['platform' => 'vimeo']));
        $this->assertToolError($this->callTool($key, 'list_outliers', ['per_page' => 500]));
    }

    public function test_get_outlier_and_not_found(): void
    {
        $this->seedOutlier('instagram', 'ig1');
        $key = $this->mcpKey(User::factory()->create());

        $data = $this->toolJson($this->callTool($key, 'get_outlier', ['platform' => 'instagram', 'video_id' => 'ig1']));
        $this->assertSame('ig1', $data['video_id']);
        $this->assertSame('https://www.instagram.com/p/ig1/', $data['url']);
        $this->assertSame('Outlier ig1', $data['title']);

        $this->assertToolError($this->callTool($key, 'get_outlier', ['platform' => 'youtube', 'video_id' => 'missing']), 'not found');
    }

    public function test_fetch_outlier_returns_known_video_or_queues_ingest(): void
    {
        $this->seedOutlier('youtube', 'known123');
        $key = $this->mcpKey(User::factory()->create());

        $known = $this->toolJson($this->callTool($key, 'fetch_outlier', [
            'platform' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=known123',
        ]));
        $this->assertFalse($known['queued']);
        $this->assertSame('known123', $known['outlier']['video_id']);
        Queue::assertNotPushed(IngestOutlierByUrlJob::class);

        $queued = $this->toolJson($this->callTool($key, 'fetch_outlier', [
            'platform' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=newvid456',
        ]));
        $this->assertTrue($queued['queued']);
        $this->assertSame('newvid456', $queued['video_id']);
        $this->assertNull($queued['outlier']);
        Queue::assertPushed(IngestOutlierByUrlJob::class, 1);
    }

    public function test_fetch_outlier_is_write_only_and_validates_url(): void
    {
        $readKey = $this->mcpKey(User::factory()->create(), 'read');
        $this->assertToolError($this->callTool($readKey, 'fetch_outlier', ['platform' => 'youtube', 'url' => 'https://youtube.com/watch?v=x']));

        $key = $this->mcpKey(User::factory()->create());
        $this->assertToolError($this->callTool($key, 'fetch_outlier', ['platform' => 'youtube', 'url' => 'not a url']));
    }

    public function test_breakdown_generate_then_read(): void
    {
        $this->seedOutlier('youtube', 'brk1');
        $key = $this->mcpKey(User::factory()->create());

        $none = $this->toolJson($this->callTool($key, 'get_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'brk1']));
        $this->assertSame('none', $none['status']);

        $pending = $this->toolJson($this->callTool($key, 'generate_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'brk1']));
        $this->assertSame(OutlierBreakdown::STATUS_PENDING, $pending['status']);
        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 1);

        OutlierBreakdown::where('video_id', 'brk1')->update([
            'status' => OutlierBreakdown::STATUS_COMPLETED,
            'payload' => ['hook' => 'Opens with a bold claim'],
        ]);

        $done = $this->toolJson($this->callTool($key, 'get_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'brk1']));
        $this->assertSame(OutlierBreakdown::STATUS_COMPLETED, $done['status']);
        $this->assertSame('Opens with a bold claim', $done['payload']['hook']);

        // Unknown video cannot be generated.
        $this->assertToolError($this->callTool($key, 'generate_outlier_breakdown', ['platform' => 'youtube', 'video_id' => 'nope']), 'not found');
    }

    public function test_save_list_and_remove_saved_outliers(): void
    {
        $this->seedOutlier('youtube', 'sv1');
        $user = User::factory()->create();
        $key = $this->mcpKey($user);

        // Must exist in the outlier database first.
        $this->assertToolError($this->callTool($key, 'save_outlier', ['platform' => 'youtube', 'video_id' => 'ghost']), 'fetch_outlier');

        $saved = $this->toolJson($this->callTool($key, 'save_outlier', [
            'platform' => 'youtube', 'video_id' => 'sv1', 'tags' => ['hooks', 'Q3 ideas'],
        ]));
        $this->assertSame('sv1', $saved['video_id']);
        $this->assertEqualsCanonicalizing(['hooks', 'Q3 ideas'], $saved['tags']);
        $this->assertSame('Outlier sv1', $saved['snapshot']['title']);
        $this->assertSame('Creator sv1', $saved['snapshot']['channel_name']);
        $this->assertSame(100000, $saved['snapshot']['views']);

        $list = $this->toolJson($this->callTool($key, 'list_saved_outliers', ['tags' => ['hooks']]));
        $this->assertCount(1, $list['saved']);
        $this->assertSame($saved['id'], $list['saved'][0]['id']);

        $this->assertEmpty($this->toolJson($this->callTool($key, 'list_saved_outliers', ['q' => 'unrelated']))['saved']);

        // Another user's library is invisible and untouchable.
        $otherKey = $this->mcpKey(User::factory()->create());
        $this->assertEmpty($this->toolJson($this->callTool($otherKey, 'list_saved_outliers'))['saved']);
        $this->assertToolError($this->callTool($otherKey, 'remove_saved_outlier', ['id' => $saved['id']]));
        $this->assertSame(1, SavedOutlier::count());

        $this->toolJson($this->callTool($key, 'remove_saved_outlier', ['id' => $saved['id']]));
        $this->assertSame(0, SavedOutlier::count());
    }

    public function test_search_outliers_queues_a_scrape(): void
    {
        $key = $this->mcpKey(User::factory()->create());

        $data = $this->toolJson($this->callTool($key, 'search_outliers', ['term' => 'faceless automation']));

        $this->assertSame('queued', $data['status']);
        $this->assertSame('faceless automation', $data['term']);
    }
}
