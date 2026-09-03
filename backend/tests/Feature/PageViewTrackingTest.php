<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\TrackingPageView;
use App\Models\TrackingVisitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pageview beacon: tracker.js reports every load of a page carrying the
 * user's meta tag. Visitors therefore means real site visitors (GA-style),
 * not just people who arrived through a tracking link.
 */
class PageViewTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['public_id' => (string) Str::uuid()]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function beacon(array $overrides = [])
    {
        return $this->postJson('/api/track/pageview', array_merge([
            'public_id' => $this->user->public_id,
            'url' => 'https://course.example/join?utm_source=x',
            'referrer' => 'https://x.com/somebody/status/1',
        ], $overrides));
    }

    public function test_pageview_creates_visitor_and_row_with_platform_and_offer_match(): void
    {
        $offer = Offer::create([
            'user_id' => $this->user->id,
            'name' => 'Course',
            'offer_url' => 'https://www.course.example/join/',
        ]);

        $response = $this->beacon();

        $response->assertOk();
        $this->assertNotNull($response->json('visitor_id'));
        $row = TrackingPageView::firstOrFail();
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame($offer->id, $row->tracking_event_id); // matched despite www + trailing slash
        $this->assertSame('/join', $row->path);
        $this->assertSame('x', $row->inferred_platform);
    }

    public function test_returning_visitor_is_reused_and_refreshes_are_deduped(): void
    {
        $first = $this->beacon()->json('visitor_id');
        $this->beacon(['visitor_id' => $first]); // refresh within 30s

        $this->assertSame(1, TrackingVisitor::count());
        $this->assertSame(1, TrackingPageView::count());
    }

    public function test_bot_user_agents_are_ignored(): void
    {
        $this->postJson('/api/track/pageview', [
            'public_id' => $this->user->public_id,
            'url' => 'https://course.example/join',
        ], ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])->assertOk();

        $this->assertSame(0, TrackingPageView::count());
    }

    public function test_unknown_public_id_is_rejected(): void
    {
        $this->postJson('/api/track/pageview', [
            'public_id' => (string) Str::uuid(),
            'url' => 'https://course.example/join',
        ])->assertNotFound();
    }

    public function test_sources_endpoint_lists_referrers_by_url(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];

        // Two visitors from X (one repeat view, plus a different X url), one
        // from Google, one direct.
        $a = $this->beacon()->json('visitor_id');
        $this->travel(1)->minutes();
        $this->beacon(['visitor_id' => $a, 'url' => 'https://course.example/other']);
        $this->beacon(['referrer' => 'https://x.com/other/status/2']);
        $this->beacon(['referrer' => 'https://www.google.com/search?q=course', 'url' => 'https://course.example/join?utm_source=']);
        $this->beacon(['referrer' => null, 'url' => 'https://course.example/join?direct=1']);

        $data = $this->withHeaders($auth)->getJson('/api/tracking-events/sources')->json('data');

        $this->assertSame('pageviews', $data['basis']);
        $sources = collect($data['sources'])->keyBy('source');
        $this->assertSame(2, $sources['x']['visitors']);
        $this->assertSame(3, $sources['x']['views']);
        $this->assertSame(1, $sources['google']['visitors']);
        $this->assertSame(1, $sources['direct']['visitors']);

        // Referrers are the FULL urls (not grouped by domain). The two distinct
        // x.com urls are separate rows; direct (no referrer) doesn't appear.
        $referrers = array_column($data['referrers'], 'referrer');
        $this->assertContains('https://x.com/somebody/status/1', $referrers);
        $this->assertContains('https://x.com/other/status/2', $referrers);
        $this->assertContains('https://www.google.com/search?q=course', $referrers);
    }

    public function test_referrers_are_listed_per_url_not_grouped(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];

        // Three different visitors from three different paths on one host —
        // listed as three separate rows (no domain grouping).
        $this->beacon(['referrer' => 'https://oxalic-carry.lovable.app/a']);
        $this->beacon(['referrer' => 'https://oxalic-carry.lovable.app/b?x=1']);
        $this->beacon(['referrer' => 'https://oxalic-carry.lovable.app/b?x=2']);

        $data = $this->withHeaders($auth)->getJson('/api/tracking-events/sources')->json('data');

        $this->assertCount(3, $data['referrers']);
    }

    public function test_localhost_referrers_are_ignored(): void
    {
        config(['app.frontend_url' => 'https://viewsmax.com', 'app.url' => 'https://api.viewsmax.com']);
        $auth = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];

        $this->beacon(['referrer' => 'https://x.com/a/status/1']);
        $this->beacon(['referrer' => 'http://localhost:8081/dashboard/analytics']);
        $this->beacon(['referrer' => 'http://127.0.0.1:3000/foo']);

        $data = $this->withHeaders($auth)->getJson('/api/tracking-events/sources')->json('data');

        $this->assertSame(['https://x.com/a/status/1'], array_column($data['referrers'], 'referrer'));
    }

    public function test_own_subdomain_referrers_are_treated_as_self_referrals(): void
    {
        config(['app.frontend_url' => 'https://app.viewsmax.com', 'app.url' => 'https://api.viewsmax.com']);
        $auth = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];

        // blog.viewsmax.com is a different host but the same owned domain — an
        // internal navigation, not real acquisition (GA4 drops self-referrals).
        $this->beacon(['referrer' => 'https://x.com/a']);
        $this->beacon(['referrer' => 'https://blog.viewsmax.com/how-to-grow']);

        $data = $this->withHeaders($auth)->getJson('/api/tracking-events/sources')->json('data');

        $this->assertSame(['https://x.com/a'], array_column($data['referrers'], 'referrer'));
    }

    public function test_internal_dashboard_referrers_are_excluded_from_sources(): void
    {
        config(['app.frontend_url' => 'https://viewsmax.com']);
        $auth = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];

        // One real visit from X, one self-referred visit made from inside the
        // ViewsMax dashboard (e.g. an admin testing a link) — the internal one
        // must not appear in either table or inflate any count.
        $this->beacon(['referrer' => 'https://x.com/somebody/status/1']);
        $this->beacon(['referrer' => 'https://viewsmax.com/dashboard/admin/users']);
        $this->beacon(['referrer' => 'https://www.viewsmax.com/dashboard/monetization/offers']);

        $data = $this->withHeaders($auth)->getJson('/api/tracking-events/sources')->json('data');

        $referrers = array_column($data['referrers'], 'referrer');
        $this->assertSame(['https://x.com/somebody/status/1'], $referrers);
        $this->assertSame(1, collect($data['sources'])->sum('visitors'));
    }

    public function test_sources_fall_back_to_click_data_when_no_pageviews(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
        $offer = Offer::create(['user_id' => $this->user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o']);
        $link = \App\Models\TrackingLink::create([
            'tracking_event_id' => $offer->id, 'placement' => 'x', 'parameter_id' => 'abc999',
        ]);
        $visitor = TrackingVisitor::create(['visitor_id' => (string) Str::uuid()]);
        \App\Models\TrackingClick::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_link_id' => $link->id,
            'referrer' => 'https://linkedin.com/feed/',
            'inferred_platform' => 'linkedin',
            'created_at' => now(),
        ]);

        $data = $this->withHeaders($auth)->getJson('/api/tracking-events/sources')->json('data');

        $this->assertSame('clicks', $data['basis']);
        $this->assertSame('linkedin', $data['sources'][0]['source']);
    }
}
