<?php

namespace Tests\Feature;

use App\Jobs\ImportChannelVideosJob;
use App\Models\Channel;
use App\Models\User;
use App\Models\YouTubeQuotaSpend;
use App\Services\YouTubeChannelService;
use App\Support\Quota\Actor;
use App\Support\Quota\Source;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Every ledger row names the unit of work that made the call (`source`) and
 * what it asked for (`target`), so a run of playlistItems.list / videos.list
 * rows reads as one search job's per-channel fan-out rather than as repeats.
 * Nothing reports itself: jobs and commands are named by hooks, MCP tools by
 * the tools/call handler, plain requests by their route.
 */
class YouTubeQuotaSourceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAll(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    private function youtubeCall(): void
    {
        Http::get('https://www.googleapis.com/youtube/v3/videos?id=v&key=k');
    }

    private function channel(User $owner): Channel
    {
        return Channel::create([
            'user_id' => $owner->id,
            'youtube_channel_id' => 'chan-'.Str::random(6),
            'channel_name' => 'My Channel',
            'youtube_access_token' => 'yt-oauth-token',
        ]);
    }

    /** The mocked import makes one YouTube call, as the real one would. */
    private function importerThatCallsYouTube(int $times): void
    {
        $this->mock(YouTubeChannelService::class, function ($m) use ($times) {
            $m->shouldReceive('fetchAndImportChannelVideos')->times($times)->andReturnUsing(function () {
                Http::get('https://www.googleapis.com/youtube/v3/playlistItems?playlistId=UU1');

                return ['imported' => 0, 'updated' => 0, 'total' => 0];
            });
        });
    }

    private function mcpCall(User $user, string $tool, array $arguments = [])
    {
        $login = $user->createToken('mobile-app')->plainTextToken;
        $key = $this->withHeaders(['Authorization' => 'Bearer '.$login])->postJson('/api/user/api-key/rotate')->json('data.key');

        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
    }

    public function test_an_explicit_source_is_written_to_the_ledger(): void
    {
        $this->fakeAll();
        Source::set('cmd:example');

        $this->youtubeCall();

        $this->assertSame('cmd:example', YouTubeQuotaSpend::sole()->source);
    }

    public function test_a_call_nobody_names_has_no_source(): void
    {
        $this->fakeAll();

        $this->youtubeCall();

        $this->assertNull(YouTubeQuotaSpend::sole()->source);
    }

    public function test_a_queued_job_is_its_own_source_while_still_spending_as_the_requester(): void
    {
        $this->fakeAll();
        $owner = $this->analyticsUser();
        $this->actingAs($owner);
        Source::set('mcp:search_outliers');
        $channel = $this->channel($owner);
        $this->importerThatCallsYouTube(1);

        // The requester rides along in the job payload; their source does not.
        $hidden = Context::dehydrate()['hidden'] ?? [];
        $this->assertArrayHasKey(Actor::KEY, $hidden);
        $this->assertArrayNotHasKey(Source::KEY, $hidden);

        ImportChannelVideosJob::dispatch($channel->id); // sync queue: runs right here

        $spend = YouTubeQuotaSpend::sole();
        $this->assertSame('job:ImportChannelVideosJob', $spend->source);
        $this->assertSame($owner->id, $spend->user_id);
    }

    public function test_an_artisan_command_is_the_source_of_its_calls(): void
    {
        $this->fakeAll();
        $this->channel($this->analyticsUser());
        $this->importerThatCallsYouTube(1);
        // `php artisan` raises CommandStarting through the Kernel's Symfony bridge, which the
        // framework switches off under the test runner — so raise it here the way the CLI does.
        event(new CommandStarting('channels:sync-videos', new ArrayInput([]), new NullOutput));

        $this->artisan('channels:sync-videos')->assertExitCode(0);

        $this->assertSame('cmd:channels:sync-videos', YouTubeQuotaSpend::sole()->source);
    }

    public function test_an_mcp_tool_call_names_its_tool(): void
    {
        $this->fakeAll();

        $this->mcpCall(User::factory()->create(), 'list_outliers')->assertOk();

        $this->assertSame('mcp:list_outliers', Source::id());
    }

    public function test_an_mcp_search_is_recorded_as_the_search_job_spending_for_the_mcp_user(): void
    {
        config()->set('services.youtube.key', 'yt_test_key');
        Http::fake([
            '*/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'vidA']]]], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => [[
                'id' => 'vidA',
                'snippet' => [
                    'title' => 'Video A', 'channelId' => 'UCsearch', 'publishedAt' => '2026-08-01T10:00:00Z',
                    'thumbnails' => ['medium' => ['url' => 'https://example.com/m.jpg'], 'default' => ['url' => 'https://example.com/d.jpg']],
                ],
                'statistics' => ['viewCount' => '50000', 'likeCount' => '1', 'commentCount' => '1'],
                'contentDetails' => ['duration' => 'PT2M5S'],
            ]]], 200),
            '*/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCsearch',
                'snippet' => ['title' => 'Search Channel', 'thumbnails' => ['high' => ['url' => 'https://example.com/av.jpg']]],
                'statistics' => ['subscriberCount' => '9000', 'videoCount' => '12'],
            ]]], 200),
            '*/youtube/v3/playlistItems*' => Http::response(['items' => []], 200),
        ]);
        $user = User::factory()->create();

        $this->mcpCall($user, 'search_outliers', ['term' => 'agents'])->assertOk();

        $rows = YouTubeQuotaSpend::all();
        $this->assertNotEmpty($rows);
        $this->assertSame(['job:ProcessYouTubeSearchTermJob'], $rows->pluck('source')->unique()->values()->all());
        $this->assertSame([$user->id], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertSame('playlist=UUsearch', $rows->firstWhere('resource', 'playlist_items_list')->target);
    }

    public function test_a_plain_api_request_is_named_by_its_route(): void
    {
        $this->fakeAll();
        config()->set('services.youtube.key', 'yt_test_key');
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->getJson('/api/multiplier/abc123xyz');

        $this->assertSame('api:GET /api/multiplier/{videoId}', YouTubeQuotaSpend::first()?->source);
    }

    /** @dataProvider targets */
    public function test_the_target_names_what_the_call_asked_for(string $method, string $url, ?string $target): void
    {
        $this->fakeAll();

        Http::send($method, $url);

        $this->assertSame($target, YouTubeQuotaSpend::sole()->target);
    }

    public static function targets(): array
    {
        $v3 = 'https://www.googleapis.com/youtube/v3/';

        return [
            'uploads playlist' => ['GET', $v3.'playlistItems?part=snippet,contentDetails&playlistId=UUabc&maxResults=50&key=SECRET', 'playlist=UUabc'],
            'a later page of it' => ['GET', $v3.'playlistItems?playlistId=UUabc&pageToken=CAoQAA&key=SECRET', 'playlist=UUabc +page'],
            'a batch of videos' => ['GET', $v3.'videos?part=statistics&id=vidA,vidB,vidC&key=SECRET', 'id=vidA +2'],
            'one video' => ['GET', $v3.'videos?id=vidA&key=SECRET', 'id=vidA'],
            'a search' => ['GET', $v3.'search?part=snippet&q=intitle%3A%22ai+agents%22&key=SECRET', 'q=intitle:"ai agents"'],
            'channels by id' => ['GET', $v3.'channels?id=UC1,UC2', 'id=UC1 +1'],
            'a channel by handle' => ['GET', $v3.'channels?forHandle=%40kai', 'handle=@kai'],
            'the connected channel' => ['GET', $v3.'channels?mine=true&part=snippet', 'mine'],
            'a thumbnail upload' => ['POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=vidA&uploadType=media', 'video=vidA'],
            'an upload session' => ['POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', null],
        ];
    }

    public function test_the_api_key_never_reaches_the_ledger(): void
    {
        $this->fakeAll();

        Http::get('https://www.googleapis.com/youtube/v3/search?q=ai&key=SECRETKEY123');

        foreach (YouTubeQuotaSpend::sole()->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString('SECRETKEY123', (string) $value, $column);
        }
    }
}
