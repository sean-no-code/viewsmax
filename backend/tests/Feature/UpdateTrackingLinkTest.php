<?php

namespace Tests\Feature;

use App\Models\BeehiivConnection;
use App\Models\Channel;
use App\Models\Offer;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use App\Models\User;
use App\Models\Video;
use App\Services\BeehiivService;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateTrackingLinkTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeOffer(User $user): Offer
    {
        return Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 50]);
    }

    private function makeLink(Offer $offer, array $attrs): TrackingLink
    {
        return TrackingLink::create(array_merge([
            'tracking_event_id' => $offer->id, 'placement' => 'video', 'parameter_id' => Str::random(6), 'name' => 'L',
        ], $attrs));
    }

    public function test_editing_name_preserves_the_youtube_reach_baseline(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $channel = Channel::create(['user_id' => $user->id, 'youtube_channel_id' => 'ch1', 'channel_name' => 'C']);
        Video::create(['youtube_video_id' => 'vid1', 'channel_id' => $channel->id, 'title' => 'V', 'view_count' => 1500]);
        $link = $this->makeLink($offer, [
            'youtube_video_id' => 'vid1', 'placement' => 'video',
            'initial_view_count' => 1000, 'current_view_count' => 1500,
        ]);

        // Source is unchanged, so reach must NOT be refetched.
        $this->mock(YouTubeChannelService::class, fn ($m) => $m->shouldReceive('getVideoDetails')->never());
        $this->mock(BeehiivService::class, fn ($m) => $m->shouldReceive('fetchPostViews')->never());

        $this->withHeaders($this->authHeaders($user))->putJson("/api/tracking-links/{$link->id}", [
            'name' => 'Renamed link',
            'placement' => 'video',
            'youtube_video_id' => 'vid1',
        ])->assertOk();

        $link->refresh();
        $this->assertSame('Renamed link', $link->name);
        $this->assertSame('vid1', $link->youtube_video_id);
        $this->assertSame(1000, $link->initial_view_count); // baseline untouched
        $this->assertSame(1500, $link->current_view_count);
    }

    public function test_switching_source_to_beehiiv_rebaselines_reach(): void
    {
        $user = User::factory()->create();
        BeehiivConnection::create([
            'user_id' => $user->id, 'api_key' => 'bh-key', 'publication_id' => 'pub_1',
            'publication_name' => 'N', 'status' => BeehiivConnection::STATUS_CONNECTED,
        ]);
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, ['placement' => 'instagram', 'initial_view_count' => 0]);

        $this->mock(BeehiivService::class, fn ($m) => $m->shouldReceive('fetchPostViews')->once()->andReturn(3200));

        $this->withHeaders($this->authHeaders($user))->putJson("/api/tracking-links/{$link->id}", [
            'name' => 'L', 'placement' => 'beehiiv',
            'youtube_video_id' => null, 'beehiiv_post_id' => 'post_x',
        ])->assertOk();

        $link->refresh();
        $this->assertSame('beehiiv', $link->placement);
        $this->assertSame('post_x', $link->beehiiv_post_id);
        $this->assertSame(0, $link->initial_view_count);   // newsletter reach = total opens
        $this->assertSame(3200, $link->current_view_count);
        $this->assertNotNull(TrackingReachSnapshot::where('tracking_link_id', $link->id)->first());
    }

    public function test_switching_to_a_non_reach_platform_clears_the_source(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, [
            'beehiiv_post_id' => 'post_x', 'placement' => 'beehiiv',
            'initial_view_count' => 0, 'current_view_count' => 3200,
        ]);

        // Clearing the source must not hit the Beehiiv API.
        $this->mock(BeehiivService::class, fn ($m) => $m->shouldReceive('fetchPostViews')->never());

        $this->withHeaders($this->authHeaders($user))->putJson("/api/tracking-links/{$link->id}", [
            'name' => 'L', 'placement' => 'instagram',
            'youtube_video_id' => null, 'beehiiv_post_id' => null,
        ])->assertOk();

        $link->refresh();
        $this->assertSame('instagram', $link->placement);
        $this->assertNull($link->beehiiv_post_id);
        $this->assertNull($link->current_view_count);
        $this->assertSame(0, $link->initial_view_count);
    }
}
