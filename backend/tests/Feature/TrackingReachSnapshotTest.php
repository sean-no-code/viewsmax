<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use App\Models\User;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrackingReachSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeseries_includes_daily_views_from_snapshots(): void
    {
        $user = User::factory()->create();
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 0]);
        $link = TrackingLink::create(['tracking_event_id' => $offer->id, 'placement' => 'video', 'parameter_id' => Str::random(6), 'name' => 'L', 'youtube_video_id' => 'vid']);
        // Yesterday 1000 cumulative, today 1200 → today gains 200 views.
        TrackingReachSnapshot::create(['tracking_link_id' => $link->id, 'snapshot_date' => now()->subDay()->toDateString(), 'view_count' => 1000]);
        TrackingReachSnapshot::create(['tracking_link_id' => $link->id, 'snapshot_date' => now()->toDateString(), 'view_count' => 1200]);

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $series = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/tracking-events/timeseries?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->json('data');

        $today = collect($series)->firstWhere('date', now()->toDateString());
        $this->assertArrayHasKey('views', $today);
        $this->assertSame(200, $today['views']);
    }

    public function test_timeseries_views_dedupes_multiple_links_to_same_video(): void
    {
        $user = User::factory()->create();
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 0]);
        // TWO links to the SAME video — the video really gained 200 views, so the
        // day must report 200, not 400.
        foreach (['aaa', 'bbb'] as $trk) {
            $link = TrackingLink::create(['tracking_event_id' => $offer->id, 'placement' => 'video', 'parameter_id' => $trk, 'name' => $trk, 'youtube_video_id' => 'sharedvid']);
            TrackingReachSnapshot::create(['tracking_link_id' => $link->id, 'snapshot_date' => now()->subDay()->toDateString(), 'view_count' => 1000]);
            TrackingReachSnapshot::create(['tracking_link_id' => $link->id, 'snapshot_date' => now()->toDateString(), 'view_count' => 1200]);
        }

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $series = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/tracking-events/timeseries?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->json('data');

        $this->assertSame(200, collect($series)->firstWhere('date', now()->toDateString())['views']);
    }

    public function test_refresh_reach_writes_a_daily_snapshot(): void
    {
        $user = User::factory()->create();
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 0]);
        $link = TrackingLink::create(['tracking_event_id' => $offer->id, 'placement' => 'video', 'parameter_id' => Str::random(6), 'name' => 'L', 'youtube_video_id' => 'vid1']);

        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldReceive('getVideoDetailsWithApiKey')->once()->andReturn([['id' => 'vid1', 'statistics' => ['viewCount' => '7500']]]);
        });

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $snap = TrackingReachSnapshot::where('tracking_link_id', $link->id)->where('snapshot_date', now()->toDateString())->first();
        $this->assertNotNull($snap);
        $this->assertSame(7500, $snap->view_count);
    }
}
