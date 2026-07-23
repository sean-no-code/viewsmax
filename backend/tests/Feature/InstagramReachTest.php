<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Views since link creation" for Instagram tracking links (Phase 1).
 *
 * An IG link pins to a media the user published THROUGH the app (post_targets
 * carries the returned media id). Reach = the media's insights `views` counted
 * from a baseline captured at link creation. The whole path is gated behind the
 * `social.platforms.instagram.reach_enabled` flag so it can merge before Meta
 * App Review grants the insights scope.
 */
class InstagramReachTest extends TestCase
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

    /** A connected IG account with a long-lived (non-expiring-soon) token. */
    private function igAccount(): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $this->user->id,
            'platform' => 'instagram',
            'platform_account_id' => 'ig-user-1',
            'name' => 'My IG',
            'access_token' => 'ig-token',
            'token_expires_at' => now()->addDays(60),
            'scopes' => ['instagram_business_basic'],
            'metadata' => ['ig_user_id' => 'ig-user-1'],
        ]);
    }

    /** An IG post published through the app, exposing $mediaId on its target. */
    private function publishedMedia(string $mediaId, ?SocialAccount $account = null, string $caption = 'My reel caption'): void
    {
        $account ??= $this->igAccount();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => $caption, 'media' => [], 'status' => Post::STATUS_POSTED]);
        $post->targets()->create([
            'platform' => 'instagram',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHED,
            'platform_post_id' => $mediaId,
            'published_at' => now(),
        ]);
    }

    private function fakeInsights(int $views): void
    {
        Http::fake([
            'graph.instagram.com/*/insights*' => Http::response([
                'data' => [['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => $views]]]],
            ], 200),
        ]);
    }

    public function test_reachkey_namespaces_instagram_media(): void
    {
        $link = new TrackingLink(['instagram_media_id' => 'ig-media-9']);
        $this->assertSame('ig:ig-media-9', $link->reachKey());
    }

    public function test_instagram_pinned_link_captures_baseline_views_when_reach_enabled(): void
    {
        config(['social.platforms.instagram.reach_enabled' => true]);
        $this->publishedMedia('ig-media-9', caption: 'Big launch reel');
        $this->fakeInsights(800);

        $response = $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['instagram'],
            'instagram_media_id' => 'ig-media-9',
            'name' => 'IG link',
        ]);

        $response->assertStatus(201);
        $link = TrackingLink::findOrFail($response->json('id'));
        $this->assertSame('ig-media-9', $link->instagram_media_id);
        // Baseline captured so the reach DELTA starts at 0 (mirrors YouTube).
        $this->assertSame(800, $link->initial_view_count);
        $this->assertSame(800, $link->current_view_count);
        $this->assertSame('Big launch reel', $link->content_title);
    }

    public function test_foreign_instagram_media_is_rejected(): void
    {
        config(['social.platforms.instagram.reach_enabled' => true]);

        $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['instagram'],
            'instagram_media_id' => 'not-yours',
        ])->assertStatus(422);
    }

    public function test_reach_disabled_stores_the_media_id_but_captures_no_baseline(): void
    {
        // Flag OFF: association still allowed, but no insights call and no baseline.
        $this->publishedMedia('ig-media-9');
        Http::fake();

        $response = $this->withHeaders($this->auth())->postJson('/api/tracking-links', [
            'tracking_event_id' => $this->offer->id,
            'placements' => ['instagram'],
            'instagram_media_id' => 'ig-media-9',
        ]);

        $response->assertStatus(201);
        $link = TrackingLink::findOrFail($response->json('id'));
        $this->assertSame('ig-media-9', $link->instagram_media_id);
        $this->assertNull($link->current_view_count);
        $this->assertSame(0, (int) $link->initial_view_count);
        Http::assertNothingSent();
    }

    public function test_refresh_command_updates_instagram_link_views_and_snapshots(): void
    {
        config(['social.platforms.instagram.reach_enabled' => true]);
        $account = $this->igAccount();
        $this->publishedMedia('ig-media-9', $account);

        $link = TrackingLink::create([
            'tracking_event_id' => $this->offer->id,
            'placement' => 'instagram',
            'parameter_id' => Str::random(6),
            'instagram_media_id' => 'ig-media-9',
            'initial_view_count' => 800,
            'current_view_count' => 800,
        ]);

        $this->fakeInsights(1200);

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $link->refresh();
        $this->assertSame(1200, $link->current_view_count);
        $this->assertNotNull($link->reach_synced_at);
        $this->assertDatabaseHas('tracking_reach_snapshots', [
            'tracking_link_id' => $link->id,
            'view_count' => 1200,
        ]);
    }

    public function test_refresh_command_leaves_instagram_links_untouched_when_flag_off(): void
    {
        $account = $this->igAccount();
        $this->publishedMedia('ig-media-9', $account);
        $link = TrackingLink::create([
            'tracking_event_id' => $this->offer->id,
            'placement' => 'instagram',
            'parameter_id' => Str::random(6),
            'instagram_media_id' => 'ig-media-9',
            'initial_view_count' => 800,
            'current_view_count' => 800,
        ]);
        Http::fake();

        $this->artisan('tracking:refresh-reach')->assertExitCode(0);

        $this->assertSame(800, $link->refresh()->current_view_count);
        Http::assertNothingSent();
    }
}
