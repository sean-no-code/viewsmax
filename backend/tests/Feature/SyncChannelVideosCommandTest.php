<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * channels:sync-videos re-imports each connected channel's latest uploads on a
 * schedule so the "add a video" pickers (offers, tracking links) surface videos
 * published after connect-time — the DB is otherwise only filled at connect or
 * via a manual refresh. Uses each channel's own OAuth token, refreshing it when
 * expired, and stays loudly diagnosable when every channel fails.
 */
class SyncChannelVideosCommandTest extends TestCase
{
    use RefreshDatabase;

    private function channel(User $user, array $attrs = []): Channel
    {
        return Channel::create(array_merge([
            'user_id' => $user->id,
            'youtube_channel_id' => 'chan-'.Str::random(6),
            'channel_name' => 'My Channel',
            'youtube_access_token' => 'yt-oauth-token',
        ], $attrs));
    }

    public function test_imports_latest_videos_for_each_connected_channel(): void
    {
        $user = User::factory()->create();
        $a = $this->channel($user);
        $b = $this->channel($user);

        $this->mock(YouTubeChannelService::class, function ($m) use ($a, $b) {
            $m->shouldReceive('fetchAndImportChannelVideos')
                ->once()
                ->with(\Mockery::on(fn ($c) => $c->id === $a->id), 'yt-oauth-token', \Mockery::any())
                ->andReturn(['imported' => 2, 'updated' => 1, 'total' => 3]);
            $m->shouldReceive('fetchAndImportChannelVideos')
                ->once()
                ->with(\Mockery::on(fn ($c) => $c->id === $b->id), 'yt-oauth-token', \Mockery::any())
                ->andReturn(['imported' => 0, 'updated' => 5, 'total' => 5]);
            $m->shouldNotReceive('refreshAccessToken');
        });

        $this->artisan('channels:sync-videos')->assertExitCode(0);
    }

    public function test_refreshes_an_expired_token_before_importing(): void
    {
        $user = User::factory()->create();
        $channel = $this->channel($user, [
            'youtube_access_token' => 'stale-token',
            'youtube_refresh_token' => 'refresh-token',
            'youtube_token_expires_at' => now()->subHour(),
        ]);

        $this->mock(YouTubeChannelService::class, function ($m) use ($channel) {
            $m->shouldReceive('refreshAccessToken')
                ->once()
                ->with('refresh-token')
                ->andReturn(['access_token' => 'fresh-token', 'expires_in' => 3600]);
            $m->shouldReceive('fetchAndImportChannelVideos')
                ->once()
                ->with(\Mockery::on(fn ($c) => $c->id === $channel->id), 'fresh-token', \Mockery::any())
                ->andReturn(['imported' => 1, 'updated' => 0, 'total' => 1]);
        });

        $this->artisan('channels:sync-videos')->assertExitCode(0);

        $this->assertSame('fresh-token', $channel->refresh()->youtube_access_token);
    }

    public function test_skips_expired_channel_with_no_refresh_token_but_still_succeeds(): void
    {
        $user = User::factory()->create();
        $this->channel($user, [
            'youtube_refresh_token' => null,
            'youtube_token_expires_at' => now()->subHour(),
        ]);

        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldNotReceive('refreshAccessToken');
            $m->shouldNotReceive('fetchAndImportChannelVideos');
        });

        $this->artisan('channels:sync-videos')->assertExitCode(0);
    }

    public function test_succeeds_when_no_channels_exist(): void
    {
        $this->artisan('channels:sync-videos')->assertExitCode(0);
    }

    public function test_one_channel_failing_does_not_stop_the_others(): void
    {
        $user = User::factory()->create();
        $a = $this->channel($user);
        $b = $this->channel($user);

        $this->mock(YouTubeChannelService::class, function ($m) use ($a, $b) {
            $m->shouldReceive('fetchAndImportChannelVideos')
                ->with(\Mockery::on(fn ($c) => $c->id === $a->id), \Mockery::any(), \Mockery::any())
                ->andThrow(new \Exception('YouTube quota exceeded'));
            $m->shouldReceive('fetchAndImportChannelVideos')
                ->once()
                ->with(\Mockery::on(fn ($c) => $c->id === $b->id), \Mockery::any(), \Mockery::any())
                ->andReturn(['imported' => 1, 'updated' => 0, 'total' => 1]);
        });

        $this->artisan('channels:sync-videos')->assertExitCode(0);
    }

    public function test_returns_failure_when_every_channel_fails(): void
    {
        $user = User::factory()->create();
        $this->channel($user);

        $this->mock(YouTubeChannelService::class, fn ($m) => $m->shouldReceive('fetchAndImportChannelVideos')
            ->atLeast()->once()
            ->andThrow(new \Exception('YouTube down')));

        $this->artisan('channels:sync-videos')->assertExitCode(1);
    }
}
