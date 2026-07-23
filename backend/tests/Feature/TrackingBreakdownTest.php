<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\TrackingClick;
use App\Models\TrackingLink;
use App\Models\TrackingVisitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Analytics read-path additions: distinct-visitor counts, per-link referrer
 * sources, and clicks-by-platform breakdowns — all derived from data every
 * click already stores (referrer, inferred_platform, visitor id).
 */
class TrackingBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Offer $offer;

    private TrackingLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $this->offer = Offer::create([
            'user_id' => $this->user->id,
            'name' => 'My Course',
            'offer_url' => 'https://course.example/join',
        ]);
        $this->link = TrackingLink::create([
            'tracking_event_id' => $this->offer->id,
            'placement' => 'x',
            'name' => 'Launch link',
            'parameter_id' => 'abc123',
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function click(string $platform, ?string $referrer, ?TrackingVisitor $visitor = null): TrackingVisitor
    {
        $visitor ??= TrackingVisitor::create(['visitor_id' => (string) Str::uuid()]);
        TrackingClick::create([
            'tracking_visitor_id' => $visitor->id,
            'tracking_link_id' => $this->link->id,
            'referrer' => $referrer,
            'inferred_platform' => $platform,
            'created_at' => now(),
        ]);

        return $visitor;
    }

    public function test_index_returns_visitor_counts_referrers_and_platform_breakdown(): void
    {
        $repeat = $this->click('x', 'https://x.com/someone/status/1');
        $this->click('x', 'https://x.com/someone/status/1', $repeat); // same visitor again
        $this->click('linkedin', 'https://www.linkedin.com/feed/');
        $this->click('direct', null);

        $data = $this->withHeaders($this->auth())->getJson('/api/tracking-events')->json('data');
        $link = collect($data[0]['links'])->firstWhere('id', $this->link->id);

        $this->assertSame(4, $link['clicks_count']);
        $this->assertSame(3, $link['visitors_count']);
        $this->assertSame(['x' => 2, 'linkedin' => 1, 'direct' => 1], $link['platform_breakdown']);

        $hosts = array_column($link['top_referrers'], 'host');
        $this->assertSame('x.com', $hosts[0]); // most clicks first
        $this->assertContains('linkedin.com', $hosts);

        // Event-level rollups too.
        $this->assertSame(3, $data[0]['visitors_count']);
        $this->assertSame(['x' => 2, 'linkedin' => 1, 'direct' => 1], $data[0]['platform_breakdown']);
    }

    public function test_stats_include_distinct_visitors(): void
    {
        $repeat = $this->click('x', 'https://x.com/a');
        $this->click('x', 'https://x.com/a', $repeat);
        $this->click('linkedin', 'https://linkedin.com/feed');

        $stats = $this->withHeaders($this->auth())->getJson('/api/tracking-events/stats')->json('data');

        $this->assertSame(3, $stats['clicks']);
        $this->assertSame(2, $stats['visitors']);
    }

    public function test_link_can_declare_multiple_placements(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['x', 'linkedin', 'threads'],
            'name' => 'Cross-post link',
        ]);

        $response->assertStatus(201);
        $this->assertSame(['x', 'linkedin', 'threads'], $response->json('placements'));
        $this->assertSame('x', $response->json('placement'));

        // Update replaces the list; placement mirrors the first entry.
        $this->withHeaders($this->auth())
            ->putJson('/api/tracking-links/'.$response->json('id'), ['placements' => ['instagram']])
            ->assertOk();
        $link = TrackingLink::findOrFail($response->json('id'));
        $this->assertSame(['instagram'], $link->placements);
        $this->assertSame('instagram', $link->placement);
    }

    public function test_invalid_placement_is_rejected(): void
    {
        $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['myspace'],
        ])->assertStatus(422);
    }

    public function test_timeseries_includes_daily_visitors(): void
    {
        $repeat = $this->click('x', 'https://x.com/a');
        $this->click('x', 'https://x.com/a', $repeat);
        $this->click('linkedin', 'https://linkedin.com/feed');

        $series = $this->withHeaders($this->auth())
            ->getJson('/api/tracking-events/timeseries?from='.now()->toDateString().'&to='.now()->toDateString())
            ->json('data');

        $today = collect($series)->firstWhere('date', now()->format('Y-m-d'));
        $this->assertSame(3, $today['clicks']);
        $this->assertSame(2, $today['visitors']);
    }
}
