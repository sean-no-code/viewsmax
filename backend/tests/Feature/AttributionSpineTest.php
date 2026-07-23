<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingGoal;
use App\Models\TrackingLink;
use App\Models\TrackingVisitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttributionSpineTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    private function makeOffer(User $user, string $conversionUrl = '/thank-you'): Offer
    {
        $offer = Offer::create([
            'user_id' => $user->id,
            'name' => 'Summer',
            'offer_url' => 'https://shop.example/summer',
            'conversion_value' => 50,
        ]);
        TrackingGoal::create([
            'tracking_event_id' => $offer->id,
            'event_type' => 'conversion',
            'conversion_url' => $conversionUrl,
            'conversion_value' => 50,
        ]);

        return $offer;
    }

    /** Links are ignored for 60s after creation, so backdate them for tests. */
    private function makeLink(Offer $offer, string $trk, string $placement = 'instagram'): TrackingLink
    {
        $link = TrackingLink::create([
            'tracking_event_id' => $offer->id,
            'placement' => $placement,
            'parameter_id' => $trk,
            'name' => $placement.' link',
        ]);
        $link->forceFill(['created_at' => now()->subMinutes(10)])->save();

        return $link;
    }

    public function test_click_captures_referrer_utm_platform_and_sets_first_touch(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, 'abc123', 'instagram');

        $res = $this->withHeaders(['User-Agent' => self::UA])->postJson('/api/track/click', [
            'trk' => 'abc123',
            'public_id' => $user->public_id,
            'referrer' => 'https://l.instagram.com/',
            'landing_url' => 'https://shop.example/summer?trk=abc123&utm_source=ig',
            'utm_source' => 'ig',
            'utm_campaign' => 'summer-drop',
        ])->assertOk();

        $click = TrackingClick::first();
        $this->assertNotNull($click, 'a click should be logged');
        $this->assertSame('https://l.instagram.com/', $click->referrer);
        $this->assertSame('instagram', $click->inferred_platform);
        $this->assertSame('summer-drop', $click->utm_campaign);
        $this->assertSame('https://shop.example/summer?trk=abc123&utm_source=ig', $click->landing_url);

        // Visitor's first touch points at this first click.
        $visitor = TrackingVisitor::where('visitor_id', $res->json('visitor_id'))->first();
        $this->assertSame($click->id, $visitor->first_touch_click_id);
    }

    public function test_conversion_persists_click_link_and_first_touch_fks(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, 'abc123');

        $click = $this->withHeaders(['User-Agent' => self::UA])->postJson('/api/track/click', [
            'trk' => 'abc123',
            'public_id' => $user->public_id,
            'referrer' => 'https://l.instagram.com/',
        ])->assertOk();
        $visitorUuid = $click->json('visitor_id');
        $clickRow = TrackingClick::first();

        $this->withHeaders(['User-Agent' => self::UA])->postJson('/api/track/conversion', [
            'visitor_id' => $visitorUuid,
            'current_url' => 'https://shop.example/thank-you',
            'public_id' => $user->public_id,
        ])->assertOk()->assertJsonPath('status', 'converted');

        $conv = TrackingConversion::first();
        $this->assertSame($clickRow->id, $conv->tracking_click_id);
        $this->assertSame($link->id, $conv->tracking_link_id);
        $this->assertSame($clickRow->id, $conv->first_touch_click_id);
        $this->assertSame($offer->id, $conv->tracking_event_id);
    }

    public function test_conversion_outside_lookback_window_is_not_attributed(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, 'abc123');

        // A click 40 days ago — older than the 30-day lookback cap.
        $visitor = TrackingVisitor::create([
            'visitor_id' => (string) Str::uuid(),
            'ip_address' => '203.0.113.5',
            'user_agent' => self::UA,
        ]);
        TrackingClick::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_link_id' => $link->id,
            'created_at' => now()->subDays(40),
        ]);

        $this->withHeaders(['User-Agent' => self::UA])->postJson('/api/track/conversion', [
            'visitor_id' => $visitor->visitor_id,
            'current_url' => 'https://shop.example/thank-you',
            'public_id' => $user->public_id,
        ])->assertStatus(400)->assertJsonPath('error', 'No click history');

        $this->assertSame(0, TrackingConversion::count());
    }

    public function test_legacy_null_link_conversion_credited_to_single_last_touch_link(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link1 = $this->makeLink($offer, 'aaa111', 'instagram');
        $link2 = $this->makeLink($offer, 'bbb222', 'tiktok');

        // Visitor clicks BOTH links; the LEGACY conversion row has a NULL link
        // (pre-attribution-spine). It must be reconstructed to the single
        // last-touch link (link2, clicked most recently), never credited to both.
        $visitor = TrackingVisitor::create([
            'visitor_id' => (string) Str::uuid(),
            'ip_address' => '203.0.113.11',
            'user_agent' => self::UA,
        ]);
        TrackingClick::create(['tracking_visitor_id' => $visitor->id, 'tracking_link_id' => $link1->id, 'created_at' => now()->subMinutes(10)]);
        TrackingClick::create(['tracking_visitor_id' => $visitor->id, 'tracking_link_id' => $link2->id, 'created_at' => now()->subMinutes(5)]);
        TrackingConversion::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_event_id' => $offer->id,
            'tracking_link_id' => null, // legacy row
            'event_type' => 'conversion',
            'value' => 50,
        ]);

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $rows = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/tracking-events')->assertOk()->json('data');

        $event = collect($rows)->firstWhere('id', $offer->id);
        $links = collect($event['links']);
        $this->assertEquals(0, $links->firstWhere('id', $link1->id)['conversions_count']);
        $this->assertEquals(0, $links->firstWhere('id', $link1->id)['sales_amount']);
        $this->assertEquals(1, $links->firstWhere('id', $link2->id)['conversions_count']);
        $this->assertEquals(50, $links->firstWhere('id', $link2->id)['sales_amount']);
        // The headline number must NOT be inflated to 100.
        $this->assertEquals(1, $event['conversions_count']);
        $this->assertEquals(50, $event['sales_amount']);
    }

    public function test_oversized_attribution_fields_do_not_reject_the_click(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link = $this->makeLink($offer, 'abc123');

        // A real ad/social entry URL with long tracking params — must not 422.
        $longUrl = 'https://shop.example/summer?fbclid='.str_repeat('a', 5000);

        $this->withHeaders(['User-Agent' => self::UA])->postJson('/api/track/click', [
            'trk' => 'abc123',
            'public_id' => $user->public_id,
            'referrer' => $longUrl,
            'landing_url' => $longUrl,
            'utm_source' => str_repeat('z', 500),
        ])->assertOk();

        $click = TrackingClick::first();
        $this->assertNotNull($click, 'oversized attribution must not drop the click');
        $this->assertLessThanOrEqual(2048, mb_strlen($click->referrer));
        $this->assertLessThanOrEqual(2048, mb_strlen($click->landing_url));
        $this->assertLessThanOrEqual(255, mb_strlen($click->utm_source));
    }

    public function test_rollup_does_not_double_count_across_two_links_of_same_offer(): void
    {
        $user = User::factory()->create();
        $offer = $this->makeOffer($user);
        $link1 = $this->makeLink($offer, 'aaa111', 'instagram');
        $link2 = $this->makeLink($offer, 'bbb222', 'tiktok');

        // One visitor clicks BOTH links, converts once — last touch = link2.
        $visitor = TrackingVisitor::create([
            'visitor_id' => (string) Str::uuid(),
            'ip_address' => '203.0.113.9',
            'user_agent' => self::UA,
        ]);
        $c1 = TrackingClick::create(['tracking_visitor_id' => $visitor->id, 'tracking_link_id' => $link1->id, 'created_at' => now()->subMinutes(10)]);
        $c2 = TrackingClick::create(['tracking_visitor_id' => $visitor->id, 'tracking_link_id' => $link2->id, 'created_at' => now()->subMinutes(5)]);
        $visitor->forceFill(['first_touch_click_id' => $c1->id])->save();

        TrackingConversion::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_event_id' => $offer->id,
            'tracking_click_id' => $c2->id,
            'tracking_link_id' => $link2->id,
            'first_touch_click_id' => $c1->id,
            'event_type' => 'conversion',
            'value' => 50,
        ]);

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $rows = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/tracking-events')->assertOk()->json('data');

        $event = collect($rows)->firstWhere('id', $offer->id);
        $links = collect($event['links']);
        $l1 = $links->firstWhere('id', $link1->id);
        $l2 = $links->firstWhere('id', $link2->id);

        // The winning link gets the conversion; the other gets ZERO (old
        // set-membership logic would have credited BOTH → double count).
        $this->assertEquals(0, $l1['conversions_count']);
        $this->assertEquals(0, $l1['sales_amount']);
        $this->assertEquals(1, $l2['conversions_count']);
        $this->assertEquals(50, $l2['sales_amount']);

        // Event totals are not inflated.
        $this->assertEquals(1, $event['conversions_count']);
        $this->assertEquals(50, $event['sales_amount']);
    }
}
