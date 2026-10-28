<?php

namespace Tests\Feature;

use App\Jobs\ImportChannelVideosJob;
use App\Jobs\ImportRecentPostsJob;
use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The connected channel's own uploads, imported by a queued job after the
 * YouTube OAuth exchange and by channels:sync-videos. Sentry flagged the import
 * as an N+1 (a lookup by youtube_video_id per video), so it must stay a fixed
 * number of queries, and it runs off the request so connecting stays fast.
 *
 * It reads the channel's uploads playlist (1 quota unit a page), never
 * search.list (100 units, and capped at 100 calls a day project-wide — the
 * outlier search's budget). Running daily per connected channel, the latter
 * blew that cap on its own.
 */
class YouTubeChannelVideoImportTest extends TestCase
{
    use RefreshDatabase;

    private function fakeVideos(array $ids, int $views = 100): void
    {
        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response(['items' => array_map(
                fn ($id) => ['contentDetails' => ['videoId' => $id]], $ids
            )], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => array_map(fn ($id) => [
                'id' => $id,
                'snippet' => ['title' => "Video {$id}", 'publishedAt' => '2026-06-01T10:00:00Z'],
                'statistics' => ['viewCount' => (string) $views],
                'contentDetails' => ['duration' => 'PT5M', 'caption' => 'true'],
            ], $ids)], 200),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
        ]);
    }

    private function channel(array $attrs = []): Channel
    {
        return Channel::create($attrs + ['user_id' => $this->analyticsUser()->id, 'youtube_channel_id' => 'UCmine']);
    }

    private function assertImportedWith(string $token): void
    {
        Http::assertSent(fn ($request) => str_contains($request->url(), '/youtube/v3/playlistItems')
            && $request['playlistId'] === 'UUmine'
            && $request->hasHeader('Authorization', 'Bearer '.$token));
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

    public function test_a_channel_that_never_uploaded_has_no_playlist_yet_and_imports_nothing(): void
    {
        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response(['error' => ['errors' => [['reason' => 'playlistNotFound']]]], 404),
        ]);

        $result = app(YouTubeChannelService::class)->fetchAndImportChannelVideos($this->channel(), 'token');

        $this->assertSame(['imported' => 0, 'updated' => 0, 'total' => 0], $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/youtube/v3/videos'));
    }

    public function test_the_import_never_spends_search_list_quota(): void
    {
        $this->fakeVideos(['v1', 'v2']);

        app(YouTubeChannelService::class)->fetchAndImportChannelVideos($this->channel(), 'token');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/youtube/v3/search'));
        Http::assertSentCount(2); // one playlist page + one videos.list batch
    }

    public function test_private_and_unlisted_uploads_are_not_imported(): void
    {
        // The owner's token lists every upload; search.list only ever returned public ones.
        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response(['items' => [
                ['contentDetails' => ['videoId' => 'pub']],
                ['contentDetails' => ['videoId' => 'priv']],
                ['contentDetails' => ['videoId' => 'unl']],
            ]], 200),
            '*/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'pub', 'snippet' => ['title' => 'Public'], 'status' => ['privacyStatus' => 'public']],
                ['id' => 'priv', 'snippet' => ['title' => 'Private'], 'status' => ['privacyStatus' => 'private']],
                ['id' => 'unl', 'snippet' => ['title' => 'Unlisted'], 'status' => ['privacyStatus' => 'unlisted']],
            ]], 200),
        ]);
        $channel = $this->channel();

        app(YouTubeChannelService::class)->fetchAndImportChannelVideos($channel, 'token');

        $this->assertSame(['pub'], Video::where('channel_id', $channel->id)->pluck('youtube_video_id')->all());
    }

    public function test_the_oauth_exchange_queues_the_import_instead_of_running_it(): void
    {
        Queue::fake();
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-at', 'refresh_token' => 'new-rt', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'id' => 'UCmine',
                'snippet' => ['title' => 'My Channel', 'thumbnails' => ['default' => ['url' => 'https://img/d.png']]],
                'statistics' => ['subscriberCount' => '10', 'videoCount' => '1', 'viewCount' => '100'],
            ]]]),
            '*' => Http::response([], 200),
        ]);
        $user = $this->analyticsUser();

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->postJson('/api/auth/youtube/exchange', ['code' => 'c', 'redirect_uri' => 'https://app.test/oauth/callback'])
            ->assertOk()
            ->assertJsonPath('data.channel_connected', true);

        $channel = Channel::where('youtube_channel_id', 'UCmine')->sole();
        Queue::assertPushed(ImportChannelVideosJob::class, fn ($job) => $job->channelId === $channel->id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/youtube/v3/playlistItems'));

        // The mirrored social account gets its recent uploads pulled into analytics too.
        $account = SocialAccount::where('platform', 'youtube')->where('platform_account_id', 'UCmine')->sole();
        Queue::assertPushed(ImportRecentPostsJob::class, fn ($job) => $job->socialAccountId === $account->id);
    }

    public function test_the_job_imports_with_the_channels_stored_token(): void
    {
        $channel = $this->channel(['youtube_access_token' => 'stored-token', 'youtube_token_expires_at' => now()->addHour()]);
        $this->fakeVideos(['v1', 'v2']);

        ImportChannelVideosJob::dispatch($channel->id);

        $this->assertImportedWith('stored-token');
        $this->assertSame(2, Video::where('channel_id', $channel->id)->count());
    }

    public function test_the_job_refreshes_an_expired_token_first(): void
    {
        $channel = $this->channel([
            'youtube_access_token' => 'stale-token',
            'youtube_refresh_token' => 'refresh-token',
            'youtube_token_expires_at' => now()->subMinute(),
        ]);
        $this->fakeVideos(['v1']);

        ImportChannelVideosJob::dispatch($channel->id);

        $this->assertImportedWith('fresh-token');
        $this->assertSame('fresh-token', $channel->refresh()->youtube_access_token);
    }

    public function test_the_job_skips_a_channel_it_cannot_authenticate_or_that_is_gone(): void
    {
        $expired = $this->channel(['youtube_access_token' => 'stale-token', 'youtube_token_expires_at' => now()->subMinute()]);
        $this->fakeVideos(['v1']);

        ImportChannelVideosJob::dispatch($expired->id);
        ImportChannelVideosJob::dispatch($expired->id + 1000);

        Http::assertNothingSent();
    }
}
