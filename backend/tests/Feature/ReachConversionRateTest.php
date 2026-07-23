<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingGoal;
use App\Models\TrackingLink;
use App\Models\TrackingVisitor;
use App\Models\User;
use App\Services\YouTubeChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReachConversionRateTest extends TestCase
{
    use RefreshDatabase;

    private function makeOffer(User $user): Offer
    {
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 50]);
        TrackingGoal::create(['tracking_event_id' => $offer->id, 'event_type' => 'conversion', 'conversion_url' => '/ty', 'conversion_value' => 50]);

        return $offer;
    }

    private function makeLink(Offer $offer, array $attrs): TrackingLink
    {
        return TrackingLink::create(array_merge([
            'tracking_event_id' => $offer->id, 'placement' => 'video', 'parameter_id' => Str::random(6), 'name' => 'L',
        ], $attrs));
    }

    private function convert(Offer $offer, TrackingLink $link, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $v = TrackingVisitor::create(['visitor_id' => (string) Str::uuid(), 'ip_address' => '1.1.1.1', 'user_agent' => 't']);
            $c = TrackingClick::create(['tracking_visitor_id' => $v->id, 'tracking_link_id' => $link->id, 'created_at' => now()]);
            TrackingConversion::create([
                'tracking_visitor_id' => $v->id, 'tracking_event_id' => $offer->id,
                'tracking_link_id' => $link->id, 'tracking_click_id' => $c->id,
                'event_type' => 'conversion', 'value' => 50,
            ]);
        }
    }

    private function fetchEvents(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/tracking-events')->assertOk()->json('data');
    }

    public function test_link_reach_conversion_rate_is_conversions_over_views_since(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, ['youtube_video_id' => 'vid1', 'initial_view_count' => 1000, 'current_view_count' => 2000]);
        $this->convert($offer, $link, 1); // 1 conversion / 1000 views = 0.1%

        $rows = $this->fetchEvents($user);
        $row = collect($rows)->firstWhere('id', $offer->id);
        $l = collect($row['links'])->firstWhere('id', $link->id);

        $this->assertEquals(1000, $l['views']);
        $this->assertEquals(0.1, $l['view_conversion_rate']);
    }

    public function test_link_without_view_data_has_null_reach_rate(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        // No youtube_video_id / current_view_count → no reach denominator.
        $link = $this->makeLink($offer, ['placement' => 'website']);
        $this->convert($offer, $link, 3);

        $rows = $this->fetchEvents($user);
        $l = collect(collect($rows)->firstWhere('id', $offer->id)['links'])->firstWhere('id', $link->id);

        $this->assertEquals(0, $l['views']);
        $this->assertNull($l['view_conversion_rate']);
    }

    public function test_event_reach_rate_dedupes_views_across_links_to_same_video(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        // Two links to the SAME video: MIN initial (1000) is used, current 2000 →
        // views_since_total = 1000 (not 2000). 2 conversions → 0.2%.
        $l1 = $this->makeLink($offer, ['youtube_video_id' => 'vid', 'initial_view_count' => 1000, 'current_view_count' => 2000]);
        $l2 = $this->makeLink($offer, ['youtube_video_id' => 'vid', 'initial_view_count' => 1200, 'current_view_count' => 2000]);
        $this->convert($offer, $l1, 1);
        $this->convert($offer, $l2, 1);

        $row = collect($this->fetchEvents($user))->firstWhere('id', $offer->id);

        $this->assertEquals(1000, $row['total_video_views']);
        $this->assertEquals(0.2, $row['view_conversion_rate']);
        // Click-based inputs are untouched (FE still derives click CR from these).
        $this->assertEquals(2, $row['conversions_count']);
        $this->assertEquals(2, $row['total_clicks']);
    }

    public function test_refresh_reach_updates_current_view_count_from_youtube(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, ['youtube_video_id' => 'vid1', 'initial_view_count' => 1000]);

        $this->mock(YouTubeChannelService::class, function ($m) {
            $m->shouldReceive('getVideoDetailsWithApiKey')
                ->once()
                ->andReturn([['id' => 'vid1', 'statistics' => ['viewCount' => '5000']]]);
        });

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $link->refresh();
        $this->assertEquals(5000, $link->current_view_count);
        $this->assertNotNull($link->reach_synced_at);
    }
}
