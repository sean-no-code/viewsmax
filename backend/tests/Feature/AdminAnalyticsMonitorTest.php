<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Role;
use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use App\Models\TrackingVisitor;
use App\Models\User;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAnalyticsMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Admin']));
        $token = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /** Offer + YouTube link (reach 1000) + $clicks clicks, $conv of which convert. */
    private function seedOffer(User $user, int $clicks, int $conv): Offer
    {
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'Demo offer', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 100]);
        $link = TrackingLink::create([
            'tracking_event_id' => $offer->id, 'placement' => 'video', 'parameter_id' => Str::random(6),
            'name' => 'YT', 'youtube_video_id' => 'vid', 'initial_view_count' => 1000, 'current_view_count' => 2000,
        ]);
        for ($i = 0; $i < $clicks; $i++) {
            $v = TrackingVisitor::create(['visitor_id' => (string) Str::uuid(), 'ip_address' => '1.1.1.1', 'user_agent' => 't']);
            $c = TrackingClick::create(['tracking_visitor_id' => $v->id, 'tracking_link_id' => $link->id, 'created_at' => now()]);
            if ($i < $conv) {
                TrackingConversion::create([
                    'tracking_visitor_id' => $v->id, 'tracking_event_id' => $offer->id,
                    'tracking_link_id' => $link->id, 'tracking_click_id' => $c->id, 'event_type' => 'sale', 'value' => 100,
                ]);
            }
        }

        return $offer;
    }

    public function test_admin_offers_returns_aggregates_with_both_conversion_rates(): void
    {
        $headers = $this->adminHeaders();
        $offer = $this->seedOffer(User::factory()->create(), 100, 5); // reach 1000, 100 clicks, 5 conv

        $rows = $this->withHeaders($headers)->getJson('/api/admin/offers')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $offer->id);

        $this->assertSame(1000, $row['views']);
        $this->assertSame(100, $row['clicks']);
        $this->assertSame(5, $row['conversions']);
        $this->assertEquals(500, $row['revenue']);
        $this->assertEquals(0.5, $row['view_conversion_rate']);  // 5 / 1000
        $this->assertEquals(5.0, $row['click_conversion_rate']); // 5 / 100
        $this->assertNotNull($row['user']['email']);
    }

    public function test_admin_offers_search_by_user_email(): void
    {
        $headers = $this->adminHeaders();
        $target = User::factory()->create(['email' => 'creator@studio.test']);
        $this->seedOffer($target, 3, 0);
        $this->seedOffer(User::factory()->create(), 3, 0);

        $rows = $this->withHeaders($headers)->getJson('/api/admin/offers?q=creator@studio.test')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('creator@studio.test', $rows[0]['user']['email']);
    }

    public function test_admin_links_returns_per_link_aggregates(): void
    {
        $headers = $this->adminHeaders();
        $this->seedOffer(User::factory()->create(), 20, 2);

        $rows = $this->withHeaders($headers)->getJson('/api/admin/links')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $link = $rows[0];
        $this->assertSame(1000, $link['views']);
        $this->assertSame(20, $link['clicks']);
        $this->assertSame(2, $link['conversions']);
        $this->assertEquals(0.2, $link['view_conversion_rate']);
        $this->assertNotNull($link['offer']['name']);
        $this->assertNotNull($link['user']['email']);
    }

    public function test_admin_offers_and_links_require_admin(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $this->withHeaders($headers)->getJson('/api/admin/offers')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/admin/links')->assertForbidden();
    }

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
