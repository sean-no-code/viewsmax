<?php

namespace Tests\Feature;

use App\Models\BeehiivConnection;
use App\Models\Offer;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use App\Models\User;
use App\Services\BeehiivService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BeehiivReachTest extends TestCase
{
    use RefreshDatabase;

    private function connectBeehiiv(User $user): void
    {
        BeehiivConnection::create([
            'user_id' => $user->id,
            'api_key' => 'bh-key',
            'publication_id' => 'pub_1',
            'publication_name' => 'Newsletter',
            'status' => BeehiivConnection::STATUS_CONNECTED,
        ]);
    }

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_creating_a_beehiiv_link_snapshots_initial_views(): void
    {
        $user = User::factory()->create();
        $this->connectBeehiiv($user);
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 0]);

        $this->mock(BeehiivService::class, function ($m) {
            $m->shouldReceive('fetchPostViews')->once()->andReturn(5000);
        });

        $this->withHeaders($this->authHeaders($user))->postJson('/api/tracking-links', [
            'tracking_event_id' => $offer->id,
            'beehiiv_post_id' => 'post_abc',
            'name' => 'Newsletter feature',
        ])->assertCreated();

        $link = TrackingLink::where('beehiiv_post_id', 'post_abc')->first();
        $this->assertNotNull($link);
        $this->assertSame('beehiiv', $link->placement);
        // Newsletter reach = total opens → initial stays 0, current = fetched views.
        $this->assertSame(0, $link->initial_view_count);
        $this->assertSame(5000, $link->current_view_count);
        $this->assertNotNull(TrackingReachSnapshot::where('tracking_link_id', $link->id)->first());
    }

    public function test_fetch_post_views_sums_web_views_and_unique_email_opens(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/posts/*' => \Illuminate\Support\Facades\Http::response(['data' => ['stats' => [
                'web' => ['views' => 10, 'clicks' => 2],
                'email' => ['unique_opens' => 61, 'opens' => 85, 'delivered' => 354],
            ]]], 200),
        ]);

        $views = app(BeehiivService::class)->fetchPostViews('key', 'pub_1', 'post_1');

        $this->assertSame(71, $views); // 10 web views + 61 unique email opens
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'expand=stats'));
    }

    public function test_refresh_reach_updates_beehiiv_links(): void
    {
        $user = User::factory()->create();
        $this->connectBeehiiv($user);
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 0]);
        $link = TrackingLink::create(['tracking_event_id' => $offer->id, 'placement' => 'beehiiv', 'parameter_id' => Str::random(6), 'name' => 'L', 'beehiiv_post_id' => 'post_abc', 'initial_view_count' => 1000, 'current_view_count' => 1000]);

        $this->mock(BeehiivService::class, function ($m) {
            $m->shouldReceive('fetchPostViews')->andReturn(1500);
        });

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $link->refresh();
        $this->assertSame(1500, $link->current_view_count);
        $this->assertSame(1500, TrackingReachSnapshot::where('tracking_link_id', $link->id)->where('snapshot_date', now()->toDateString())->first()->view_count);
    }

    public function test_event_reach_dedupes_links_to_the_same_beehiiv_post(): void
    {
        $user = User::factory()->create();
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'O', 'offer_url' => 'https://ex.com/o', 'conversion_value' => 0]);
        // Two links to the SAME beehiiv post — reach counted once (MIN initial).
        TrackingLink::create(['tracking_event_id' => $offer->id, 'placement' => 'beehiiv', 'parameter_id' => Str::random(6), 'name' => 'A', 'beehiiv_post_id' => 'post_x', 'initial_view_count' => 1000, 'current_view_count' => 2000]);
        TrackingLink::create(['tracking_event_id' => $offer->id, 'placement' => 'beehiiv', 'parameter_id' => Str::random(6), 'name' => 'B', 'beehiiv_post_id' => 'post_x', 'initial_view_count' => 1200, 'current_view_count' => 2000]);

        $rows = $this->withHeaders($this->authHeaders($user))->getJson('/api/tracking-events')->assertOk()->json('data');
        $event = collect($rows)->firstWhere('id', $offer->id);

        $this->assertSame(1000, $event['total_video_views']); // 2000 - min(1000,1200), not 2000
    }

    public function test_posts_endpoint_lists_beehiiv_posts(): void
    {
        $user = User::factory()->create();
        $this->connectBeehiiv($user);
        $this->mock(BeehiivService::class, function ($m) {
            $m->shouldReceive('fetchPosts')->once()->andReturn([['id' => 'post_1', 'title' => 'Issue #1', 'web_url' => null]]);
        });

        $data = $this->withHeaders($this->authHeaders($user))->getJson('/api/beehiiv/posts')->assertOk()->json('data');
        $this->assertSame('post_1', $data[0]['id']);
        $this->assertSame('Issue #1', $data[0]['title']);
    }
}
