<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Offer;
use App\Models\TrackingLink;
use App\Models\User;
use App\Models\Video;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * tracking:refresh-reach reads a tracked video's views with the OWNER's YouTube
 * OAuth token (their own connected-account data), grouping ids by channel. It
 * falls back to the public API key only for videos with no connected channel
 * token, and stays loudly diagnosable when every fetch fails.
 */
class RefreshTrackingReachCommandTest extends TestCase
{
    use RefreshDatabase;

    private function connectedChannel(User $user, ?string $token = 'yt-oauth-token'): Channel
    {
        return Channel::create([
            'user_id' => $user->id,
            'youtube_channel_id' => 'chan-'.Str::random(6),
            'channel_name' => 'My Channel',
            'youtube_access_token' => $token,
        ]);
    }

    /**
     * Create a tracked link for $videoId. When $channel is given, a Video row
     * ties the video to that channel (so it's fetched via OAuth); otherwise the
     * video has no owner and drops to the API-key fallback.
     */
    private function trackedVideo(User $user, string $videoId, ?Channel $channel = null): TrackingLink
    {
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com', 'conversion_value' => 0]);

        if ($channel) {
            Video::create(['youtube_video_id' => $videoId, 'channel_id' => $channel->id, 'title' => 'Vid']);
        }

        return TrackingLink::create([
            'tracking_event_id' => $offer->id,
            'placement' => 'video',
            'parameter_id' => Str::random(6),
            'youtube_video_id' => $videoId,
        ]);
    }

    public function test_reads_views_with_the_owner_oauth_token_not_the_api_key(): void
    {
        // No API key configured — OAuth must carry the whole run.
        config(['services.youtube.key' => null]);
        $user = User::factory()->create();
        $channel = $this->connectedChannel($user);
        $link = $this->trackedVideo($user, 'vid1', $channel);

        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldReceive('getVideoDetails')
                ->once()
                ->andReturn([['id' => 'vid1', 'statistics' => ['viewCount' => 4321]]]);
            $m->shouldNotReceive('getVideoDetailsWithApiKey');
        });

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $link->refresh();
        $this->assertSame(4321, $link->current_view_count);
        $this->assertNotNull($link->reach_synced_at);
    }

    public function test_falls_back_to_api_key_when_video_has_no_connected_channel(): void
    {
        config(['services.youtube.key' => 'test-key']);
        $user = User::factory()->create();
        // No channel/Video row → no owner token → API-key fallback.
        $link = $this->trackedVideo($user, 'orphan1');

        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldReceive('getVideoDetailsWithApiKey')
                ->once()
                ->andReturn([['id' => 'orphan1', 'statistics' => ['viewCount' => 99]]]);
            $m->shouldNotReceive('getVideoDetails');
        });

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $this->assertSame(99, $link->refresh()->current_view_count);
    }

    public function test_skips_ownerless_videos_without_an_api_key_but_still_succeeds(): void
    {
        config(['services.youtube.key' => null]);
        $user = User::factory()->create();
        $this->trackedVideo($user, 'orphan1'); // no channel, no key → skipped

        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldNotReceive('getVideoDetails');
            $m->shouldNotReceive('getVideoDetailsWithApiKey');
        });

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);
    }

    public function test_succeeds_when_no_youtube_links_exist(): void
    {
        config(['services.youtube.key' => null]);

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);
    }

    public function test_returns_failure_when_every_youtube_fetch_fails(): void
    {
        config(['services.youtube.key' => null]);
        $user = User::factory()->create();
        $channel = $this->connectedChannel($user);
        $this->trackedVideo($user, 'vid1', $channel);

        $this->mock(YouTubeChannelService::class, fn ($m) => $m->shouldReceive('getVideoDetails')
            ->atLeast()->once()->andThrow(new \Exception('OAuth down')));

        $this->artisan('tracking:refresh-reach')->assertExitCode(1);
    }

    public function test_oauth_failure_falls_back_to_api_key(): void
    {
        config(['services.youtube.key' => 'test-key']);
        $user = User::factory()->create();
        $channel = $this->connectedChannel($user);
        $link = $this->trackedVideo($user, 'vid1', $channel);

        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldReceive('getVideoDetails')->once()->andThrow(new \Exception('token revoked'));
            // The batch is retried against the public API key, which succeeds.
            $m->shouldReceive('getVideoDetailsWithApiKey')
                ->once()
                ->andReturn([['id' => 'vid1', 'statistics' => ['viewCount' => 500]]]);
        });

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $this->assertSame(500, $link->refresh()->current_view_count);
    }
}
