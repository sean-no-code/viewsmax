<?php

namespace Tests\Feature;

use App\Jobs\IngestOutlierChannelJob;
use App\Models\OutlierChannel;
use App\Models\OutlierChannelIngest;
use App\Models\OutlierCompetitorChannel;
use App\Models\OutlierVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/outliers/channels/add + GET /api/outliers/channels/ingests/{id}:
 * add a creator by profile URL / @handle, pull their recent videos in the
 * background, follow the channel as a competitor.
 */
class OutlierChannelIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.captapi.api_key', 'capt_test_key');
        config()->set('services.captapi.base_url', 'https://api.captapi.com/v1');
        // Thumbnail re-hosting is covered in CaptApiChannelIngestTest; keep these fakes minimal.
        config()->set('services.outliers.rehost_thumbnails', false);
    }

    private function authHeaders(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function tiktokPost(string $id, int $views): array
    {
        return [
            'id' => $id, 'caption' => "Post {$id}", 'publishedAt' => '2026-06-02T14:56:35.000Z', 'durationSeconds' => 20,
            'thumbnailUrl' => "https://example.com/{$id}.jpg",
            'author' => ['id' => '127905465618821121', 'username' => 'khaby.lame', 'displayName' => 'Khabane lame', 'followers' => 100],
            'engagement' => ['views' => $views, 'likes' => 1, 'comments' => 1],
        ];
    }

    private function fakeTiktokChannel(array $posts): void
    {
        Http::fake([
            '*/tiktok/channel-details*' => Http::response(['success' => true, 'data' => ['id' => '127905465618821121', 'handle' => 'khaby.lame', 'displayName' => 'Khabane lame', 'followers' => 100, 'postCount' => 3]], 200),
            '*/tiktok/channel-posts*' => Http::response(['success' => true, 'data' => ['items' => $posts]], 200),
        ]);
    }

    public function test_add_queues_an_ingest_and_returns_202(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@Khaby.Lame'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queued', true)
            ->assertJsonPath('platform', 'tiktok')
            ->assertJsonPath('handle', 'khaby.lame');

        $ingest = OutlierChannelIngest::find($response->json('ingest_id'));
        $this->assertSame($user->id, $ingest->user_id);
        $this->assertSame(OutlierChannelIngest::STATUS_QUEUED, $ingest->status);
        $this->assertSame(10, $ingest->max_videos);
        Queue::assertPushed(IngestOutlierChannelJob::class, fn ($job) => $job->ingestId === $ingest->id);
    }

    public function test_bare_handle_needs_a_platform_and_video_links_are_rejected(): void
    {
        Queue::fake();
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => ''])->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => '@khaby.lame'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'platform'));
        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@x/video/123'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'video link'));
        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['platform' => 'tiktok', 'input' => '@khaby.lame', 'max_videos' => 500])
            ->assertStatus(422);

        Queue::assertNothingPushed();

        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['platform' => 'tiktok', 'input' => '@khaby.lame', 'max_videos' => 10])
            ->assertStatus(202)->assertJsonPath('handle', 'khaby.lame');
        $this->assertSame(10, OutlierChannelIngest::first()->max_videos);
    }

    public function test_recently_ingested_channel_returns_immediately_and_follows_it(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $channel = OutlierChannel::create([
            'platform' => 'tiktok', 'youtube_channel_id' => '127905465618821121', 'handle' => 'khaby.lame',
            'channel_name' => 'Khabane lame', 'subscriber_count' => 100, 'average_views' => 300, 'last_ingested_at' => now()->subHours(2),
        ]);

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@khaby.lame'])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('queued', false)
            ->assertJsonPath('channel.id', '127905465618821121')
            ->assertJsonPath('channel.channel_id', $channel->id)
            ->assertJsonPath('channel.name', 'Khabane lame')
            ->assertJsonPath('channel.platform', 'tiktok');

        Queue::assertNothingPushed();
        $this->assertTrue(OutlierCompetitorChannel::where('user_id', $user->id)->where('channel_id', $channel->id)->exists());

        // Stale (> 24h) → re-pulled.
        $channel->forceFill(['last_ingested_at' => now()->subDays(2)])->save();
        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@khaby.lame'])
            ->assertStatus(202);
        Queue::assertPushed(IngestOutlierChannelJob::class, 1);
    }

    public function test_in_flight_ingest_for_the_same_channel_is_reused(): void
    {
        Queue::fake();
        $headers = $this->authHeaders();

        $first = $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => 'https://www.instagram.com/nasa/'])->json('ingest_id');
        $second = $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => 'https://instagram.com/NASA'])->assertStatus(202)->json('ingest_id');

        $this->assertSame($first, $second);
        Queue::assertPushed(IngestOutlierChannelJob::class, 1);
    }

    public function test_show_is_scoped_to_the_requesting_user(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $id = $this->withHeaders($this->authHeaders($owner))
            ->postJson('/api/outliers/channels/add', ['input' => 'https://www.youtube.com/@MrBeast'])->json('ingest_id');

        $this->withHeaders($this->authHeaders($owner))
            ->getJson("/api/outliers/channels/ingests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.ingest_id', $id)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.platform', 'youtube')
            ->assertJsonPath('data.handle', 'mrbeast')
            ->assertJsonPath('data.channel', null);

        $this->withHeaders($this->authHeaders())->getJson("/api/outliers/channels/ingests/{$id}")->assertNotFound();
    }

    public function test_add_is_rate_limited_per_user(): void
    {
        Queue::fake();
        config()->set('mcp.rate_limits.add_outlier_channel_per_hour', 2);
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@one'])->assertStatus(202);
        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@two'])->assertStatus(202);
        $this->withHeaders($headers)->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@three'])->assertStatus(429);
    }

    public function test_feature_flag_disables_the_endpoint(): void
    {
        Queue::fake();
        config()->set('services.outliers.channel_ingest_enabled', false);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/channels/add', ['input' => 'https://www.tiktok.com/@one'])
            ->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_job_pulls_the_channel_marks_done_and_follows_it(): void
    {
        $user = User::factory()->create();
        $this->fakeTiktokChannel([$this->tiktokPost('1', 100), $this->tiktokPost('2', 300), $this->tiktokPost('3', 500)]);
        $ingest = OutlierChannelIngest::create([
            'user_id' => $user->id, 'platform' => 'tiktok', 'input' => 'https://www.tiktok.com/@khaby.lame', 'handle' => 'khaby.lame',
        ]);

        app()->call([new IngestOutlierChannelJob($ingest->id), 'handle']);

        $ingest->refresh();
        $this->assertSame(OutlierChannelIngest::STATUS_DONE, $ingest->status);
        $this->assertSame(3, $ingest->videos_added);
        $this->assertNotNull($ingest->started_at);
        $this->assertNotNull($ingest->finished_at);

        $channel = $ingest->channel;
        $this->assertSame('127905465618821121', $channel->youtube_channel_id);
        $this->assertNotNull($channel->last_ingested_at);
        $this->assertSame(3, OutlierVideo::where('channel_id', $channel->id)->count());
        $this->assertTrue(OutlierCompetitorChannel::where('user_id', $user->id)->where('channel_id', $channel->id)->exists());

        $this->withHeaders($this->authHeaders($user))
            ->getJson("/api/outliers/channels/ingests/{$ingest->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.videos_added', 3)
            ->assertJsonPath('data.channel.id', '127905465618821121')
            ->assertJsonPath('data.channel.handle', 'khaby.lame')
            ->assertJsonPath('data.channel.average_views', 300);
    }

    public function test_job_records_a_user_safe_failure(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Profile not found']], 404)]);
        $ingest = OutlierChannelIngest::create([
            'user_id' => User::factory()->create()->id, 'platform' => 'tiktok', 'input' => '@nobody', 'handle' => 'nobody',
        ]);

        app()->call([new IngestOutlierChannelJob($ingest->id), 'handle']);

        $ingest->refresh();
        $this->assertSame(OutlierChannelIngest::STATUS_FAILED, $ingest->status);
        $this->assertSame('Profile not found', $ingest->error);
        $this->assertNull($ingest->channel_id);
    }

    public function test_job_failed_hook_marks_the_row_so_it_never_sticks_in_processing(): void
    {
        $ingest = OutlierChannelIngest::create([
            'user_id' => User::factory()->create()->id, 'platform' => 'instagram', 'input' => '@nasa', 'handle' => 'nasa',
            'status' => OutlierChannelIngest::STATUS_PROCESSING, 'started_at' => now(),
        ]);

        (new IngestOutlierChannelJob($ingest->id))->failed(new \Illuminate\Queue\MaxAttemptsExceededException('timed out'));

        $ingest->refresh();
        $this->assertSame(OutlierChannelIngest::STATUS_FAILED, $ingest->status);
        $this->assertStringNotContainsString('timed out', $ingest->error);
        $this->assertNotEmpty($ingest->error);
    }

    public function test_youtube_job_resolves_the_handle_first(): void
    {
        config()->set('services.youtube.key', 'yt-test-key');
        Queue::fake(); // classification follow-up job
        $item = fn (string $id, int $views) => [
            'id' => $id, 'snippet' => ['title' => "V {$id}", 'publishedAt' => '2026-06-01T10:00:00Z', 'thumbnails' => []],
            'statistics' => ['viewCount' => (string) $views], 'contentDetails' => ['duration' => 'PT5M'],
        ];
        Http::fake([
            '*/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCbeast', 'snippet' => ['title' => 'MrBeast', 'customUrl' => '@MrBeast', 'thumbnails' => []],
                'statistics' => ['subscriberCount' => '1', 'videoCount' => '2'],
            ]]], 200),
            '*/youtube/v3/playlistItems*' => Http::response(['items' => [['contentDetails' => ['videoId' => 'y1']], ['contentDetails' => ['videoId' => 'y2']]]], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => [$item('y1', 10), $item('y2', 1000)]], 200),
        ]);
        $ingest = OutlierChannelIngest::create([
            'user_id' => User::factory()->create()->id, 'platform' => 'youtube', 'input' => 'https://www.youtube.com/@MrBeast', 'handle' => 'mrbeast',
        ]);

        app()->call([new IngestOutlierChannelJob($ingest->id), 'handle']);

        $ingest->refresh();
        $this->assertSame(OutlierChannelIngest::STATUS_DONE, $ingest->status, (string) $ingest->error);
        $this->assertSame('UCbeast', $ingest->channel->youtube_channel_id);
        $this->assertSame('mrbeast', $ingest->channel->handle);
        $this->assertSame(2, $ingest->videos_added);
    }
}
