<?php

namespace Tests\Feature;

use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingVisitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage of the full flow against the real API:
 * login -> create offer -> create link -> post content -> record a conversion
 * -> read analytics back.
 */
class OfferFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_offer_flow_from_login_to_analytics(): void
    {
        // 1. Login (UserFactory seeds the password "password").
        $user = User::factory()->create();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $login->assertOk()->assertJsonPath('success', true);
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];

        // 2. Create an offer.
        $offerResponse = $this->withHeaders($headers)->postJson('/api/tracking-events', [
            'name' => 'Summer promo',
            'offer_url' => 'https://example.com/summer',
            'goals' => [
                ['event_type' => 'conversion', 'conversion_url' => '/thank-you', 'conversion_value' => 50],
            ],
        ]);
        $offerResponse->assertCreated();
        $offerId = $offerResponse->json('id');
        $this->assertNotNull($offerId);

        // 3. Create a tracking link for the offer.
        $linkResponse = $this->withHeaders($headers)->postJson('/api/tracking-links', [
            'tracking_event_id' => $offerId,
            'placement' => 'website',
            'name' => 'Instagram bio',
        ]);
        $linkResponse->assertCreated();

        // 4. Post content attached to the offer.
        $contentResponse = $this->withHeaders($headers)->postJson('/api/contents', [
            'title' => 'Why our summer offer rocks',
            'body' => str_repeat('Value-packed copy. ', 50),
            'offer_id' => $offerId,
            'status' => 'published',
        ]);
        $contentResponse->assertCreated()->assertJsonPath('data.offer_id', $offerId);

        // 5. Record a conversion event (value 50) attributed to the offer.
        $visitor = TrackingVisitor::create([
            'visitor_id' => (string) Str::uuid(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
        TrackingConversion::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_event_id' => $offerId,
            'event_type' => 'conversion',
            'value' => 50,
        ]);

        // 6. Read analytics back — sales reflect the conversion, one offer counted.
        $stats = $this->withHeaders($headers)->getJson('/api/tracking-events/stats');
        $stats->assertOk()
            ->assertJsonPath('data.eventCount', 1)
            ->assertJsonPath('data.sales', 50);

        // Content is listed under the offer.
        $this->withHeaders($headers)->getJson("/api/contents?offer_id={$offerId}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_offer_accepts_newsletter_trial_and_custom_goal_types(): void
    {
        $user = User::factory()->create();
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
        $token = $login->json('data.token');
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        // Built-in newsletter/trial types plus a user-defined custom event all persist.
        $response = $this->withHeaders($headers)->postJson('/api/tracking-events', [
            'name' => 'Multi-goal offer',
            'offer_url' => 'https://example.com/launch',
            'goals' => [
                ['event_type' => 'newsletter', 'conversion_url' => '/subscribed', 'conversion_value' => 0],
                ['event_type' => 'trial', 'conversion_url' => '/trial-started', 'conversion_value' => 0],
                ['event_type' => 'demo-requested', 'conversion_url' => '/demo', 'conversion_value' => 25],
            ],
        ]);

        $response->assertCreated();
        $offerId = $response->json('id');

        $goals = $this->withHeaders($headers)->getJson("/api/tracking-events/{$offerId}")->json('goals');
        $types = collect($goals)->pluck('event_type')->all();

        $this->assertContains('newsletter', $types);
        $this->assertContains('trial', $types);
        $this->assertContains('demo-requested', $types); // custom event stored verbatim
    }

    public function test_last_click_at_is_serialized_as_iso8601_utc(): void
    {
        $user = User::factory()->create();
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
        $headers = ['Authorization' => 'Bearer ' . $login->json('data.token'), 'Accept' => 'application/json'];

        // Offer + link.
        $offerId = $this->withHeaders($headers)->postJson('/api/tracking-events', [
            'name' => 'TZ offer',
            'offer_url' => 'https://example.com/tz',
            'goals' => [['event_type' => 'conversion', 'conversion_url' => '/thank-you', 'conversion_value' => 10]],
        ])->json('id');

        $linkId = $this->withHeaders($headers)->postJson('/api/tracking-links', [
            'tracking_event_id' => $offerId,
            'placement' => 'website',
            'name' => 'Bio link',
        ])->json('id');

        // A click at a known UTC instant (stored as the DB does: no tz marker).
        $visitor = TrackingVisitor::create([
            'visitor_id' => (string) Str::uuid(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);
        TrackingClick::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_link_id' => $linkId,
            'created_at' => '2026-07-01 15:30:00',
        ]);

        // The links table (GET /api/tracking-events) must expose last_click_at as
        // ISO-8601 with an explicit UTC marker, so the SPA never parses it as local.
        $events = $this->withHeaders($headers)->getJson('/api/tracking-events')->assertOk()->json('data');
        $link = collect($events)->firstWhere('id', $offerId)['links'][0] ?? null;
        $this->assertNotNull($link, 'the offer should expose its link');
        $lastClick = $link['last_click_at'] ?? null;
        $this->assertNotNull($lastClick, 'last_click_at should be present');

        // Must carry a timezone designator (Z or ±hh:mm), not a bare "Y-m-d H:i:s".
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/',
            $lastClick,
            "last_click_at must be ISO-8601 UTC, got: {$lastClick}"
        );

        // And it must still represent the exact instant we stored (15:30 UTC).
        $this->assertSame(
            Carbon::parse('2026-07-01 15:30:00', 'UTC')->timestamp,
            Carbon::parse($lastClick)->timestamp,
            'last_click_at must preserve the stored UTC instant'
        );
    }
}
