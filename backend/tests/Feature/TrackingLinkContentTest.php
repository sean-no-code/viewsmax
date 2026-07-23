<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Offer;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\TrackingLink;
use App\Models\User;
use App\Models\Video;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Link content pinning: creating a link against a piece of content must
 * snapshot its TITLE (content_title) and its VIEW COUNT (current_view_count /
 * total_views) — both existed as columns but were never written for new links,
 * so the links table showed neither. Also covers multi-content links (a
 * placements multi-select can pick YouTube + Beehiiv together) and X pinning.
 */
class TrackingLinkContentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $this->offer = Offer::create([
            'user_id' => $this->user->id,
            'name' => 'Offer',
            'offer_url' => 'https://ex.com/offer',
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function makeVideo(): Video
    {
        $channel = Channel::create(['user_id' => $this->user->id, 'youtube_channel_id' => 'ch1', 'channel_name' => 'C']);

        return Video::create([
            'youtube_video_id' => 'vid1',
            'channel_id' => $channel->id,
            'title' => 'My launch video',
            'view_count' => 4832,
        ]);
    }

    public function test_youtube_link_snapshots_title_and_views_with_multi_content_sources(): void
    {
        $this->makeVideo();
        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldReceive('getVideoDetails')->andReturn([['statistics' => ['viewCount' => 5000]]]);
        });

        $response = $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['video', 'beehiiv'],
            'youtube_video_id' => 'vid1',
            'beehiiv_post_id' => 'bh-post-1',
            'name' => 'Launch link',
        ]);

        $response->assertStatus(201);
        $link = TrackingLink::findOrFail($response->json('id'));
        $this->assertSame('My launch video', $link->content_title);
        $this->assertSame('vid1', $link->youtube_video_id);
        $this->assertSame('bh-post-1', $link->beehiiv_post_id);
        // View count captured at creation, so the UI can show the video's views
        // immediately (the reach DELTA starts at 0 by design).
        $this->assertSame(5000, $link->current_view_count);
        $this->assertSame(5000, $link->initial_view_count);
    }

    public function test_x_pinned_link_stores_the_post_id_and_tweet_excerpt(): void
    {
        $post = Post::create(['user_id' => $this->user->id, 'caption' => "Big launch thread\n---\nsecond tweet", 'media' => [], 'status' => Post::STATUS_POSTED]);
        $post->targets()->create([
            'platform' => 'x',
            'status' => PostTarget::STATUS_PUBLISHED,
            'platform_post_id' => 'tweet-9',
            'published_at' => now(),
        ]);

        $response = $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['x'],
            'x_post_id' => 'tweet-9',
            'name' => 'X link',
        ]);

        $response->assertStatus(201);
        $link = TrackingLink::findOrFail($response->json('id'));
        $this->assertSame('tweet-9', $link->x_post_id);
        $this->assertSame('Big launch thread', $link->content_title);
    }

    public function test_foreign_x_post_is_rejected(): void
    {
        $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['x'],
            'x_post_id' => 'not-yours',
        ])->assertStatus(422);
    }

    public function test_index_exposes_total_content_views(): void
    {
        TrackingLink::create([
            'tracking_event_id' => $this->offer->id,
            'placement' => 'video',
            'parameter_id' => Str::random(6),
            'name' => 'L',
            'initial_view_count' => 1000,
            'current_view_count' => 1500,
        ]);

        $data = $this->withHeaders($this->auth())->getJson('/api/tracking-events')->json('data');
        $link = $data[0]['links'][0];

        $this->assertSame(1500, $link['total_views']);
        $this->assertSame(500, $link['views']); // delta since creation, for CR
    }
}
