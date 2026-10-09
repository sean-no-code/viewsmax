<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\YouTubeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Website actions cost credits from config/credits.php `web`, set to what the
 * matching AI tool charges today. Same rules as the AI: refused up front when
 * the balance is too low, charged only when the action succeeds, never below
 * zero. Reads and website-only actions are free (0) unless priced there.
 */
class WebActionCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $chargeWebActions = true;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->user = User::factory()->create();
        $this->user->socialAccounts()->create([
            'platform' => 'x', 'platform_account_id' => 'x-1', 'name' => 'My X', 'username' => 'me',
            'access_token' => 't', 'token_expires_at' => now()->addDay(), 'scopes' => ['tweet.write'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function balance(): int
    {
        return $this->user->fresh()->balanceInt;
    }

    private function sendPost(string $status, int $images = 0): \Illuminate\Testing\TestResponse
    {
        $media = array_map(fn ($i) => ['type' => 'image', 'url' => "https://example.com/{$i}.jpg"], range(1, max($images, 1)));

        return $this->postJson('/api/posts', array_filter([
            'caption' => 'Hello', 'platforms' => ['x'], 'status' => $status,
            'scheduled_at' => $status === 'scheduled' ? now()->addDay()->toIso8601String() : null,
            'media' => $images > 0 ? $media : null,
        ]), $this->headers());
    }

    public function test_the_website_uses_the_same_prices_as_the_ai_tools(): void
    {
        $routes = [
            'POST api/posts' => 'create_post',
            'PUT api/posts/{post}' => 'update_post',
            'DELETE api/posts/{post}' => 'delete_post',
            'POST api/posts/{post}/targets/{target}/retry' => 'retry_post',
            'POST api/outliers/search' => 'search_outliers',
            'POST api/outliers/fetch' => 'fetch_outlier',
            'POST api/outliers/{platform}/{videoId}/breakdown' => 'generate_outlier_breakdown',
            'POST api/outliers/channels/add' => 'add_outlier_channel',
            'POST api/outliers/library' => 'save_outlier',
            'PATCH api/outliers/library/{id}' => 'update_saved_outlier',
            'DELETE api/outliers/library/{id}' => 'remove_saved_outlier',
            'POST api/outliers/saved-filters' => 'saved_filter',
            'DELETE api/outliers/saved-filters/{id}' => 'saved_filter',
            'POST api/outliers/competitors' => 'competitor',
            'DELETE api/outliers/competitors/{channelId}' => 'competitor',
            'POST api/outliers/{platform}/{videoId}/refresh-media' => 'refresh_outlier_media',
            'POST api/tracking-events' => 'create_offer',
            'PUT api/tracking-events/{tracking_event}' => 'update_offer',
            'DELETE api/tracking-events/{tracking_event}' => 'delete_offer',
            'POST api/tracking-links' => 'create_tracking_link',
            'PUT api/tracking-links/{tracking_link}' => 'update_tracking_link',
            'DELETE api/tracking-links/{tracking_link}' => 'delete_tracking_link',
            'POST api/auth/youtube/exchange' => 'get_connect_url',
            'POST api/auth/{provider}/exchange' => 'get_connect_url',
            'POST api/social/{platform}/exchange' => 'get_connect_url',
            'POST api/social/{platform}/connect' => 'get_connect_url',
            'POST api/beehiiv/connection' => 'get_connect_url',
            'DELETE api/connections/{id}' => 'disconnect_account',
            'DELETE api/social/accounts/{id}' => 'disconnect_account',
            'DELETE api/channels/{id}' => 'disconnect_account',
            'DELETE api/beehiiv/connection' => 'disconnect_account',
            'POST api/feature-requests' => 'create_feature_request',
            'POST api/feature-requests/{id}/upvote' => 'upvote_feature_request',
            'GET api/posts' => 'read',
        ];

        foreach ($routes as $route => $tool) {
            [$method, $uri] = explode(' ', $route);
            $found = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));
            $this->assertNotNull($found, "Route {$route} not found");
            $this->assertContains("credits.web:{$tool}", $found->gatherMiddleware(), "{$route} must charge {$tool}");
        }
    }

    public function test_uploading_media_on_the_website_is_free(): void
    {
        // In the composer an upload is a draft step (people swap images); the
        // post is charged when it is published or scheduled.
        foreach (['POST api/posts/media', 'POST api/posts/media/direct', 'POST api/posts/media/direct/complete'] as $route) {
            [$method, $uri] = explode(' ', $route);
            $found = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));
            $this->assertNotNull($found, "Route {$route} not found");
            // credits.web:read is on the whole group and only prices GETs.
            $charging = array_filter($found->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'credits.web') && $m !== 'credits.web:read');
            $this->assertSame([], array_values($charging), "{$route} must not charge credits");
        }
    }

    public function test_the_default_prices_match_what_the_ai_charges_today(): void
    {
        $credits = app(\App\Services\CreditService::class);
        $ai = fn (string $tool) => $credits->mcpToolCost($tool, true);

        foreach (['create_post', 'update_post', 'delete_post', 'upload_media', 'search_outliers', 'fetch_outlier',
            'generate_outlier_breakdown', 'add_outlier_channel', 'save_outlier', 'remove_saved_outlier', 'create_offer',
            'update_offer', 'delete_offer', 'create_tracking_link', 'get_connect_url', 'disconnect_account',
            'create_feature_request', 'x_link'] as $tool) {
            $this->assertSame($ai($tool), $credits->webActionCost($tool), "{$tool} must cost the same as the AI");
        }

        // Reads and website-only actions are free unless priced in config.
        foreach (['read', 'retry_post', 'saved_filter', 'competitor', 'refresh_outlier_media', 'update_tracking_link',
            'delete_tracking_link', 'upvote_feature_request', 'update_saved_outlier'] as $action) {
            $this->assertSame(0, $credits->webActionCost($action), "{$action} must be free by default");
        }
    }

    public function test_creating_a_post_costs_the_same_as_the_ai_create_post(): void
    {
        $this->fundCredits($this->user, 100);

        $this->sendPost('scheduled')->assertCreated();

        $this->assertSame(100 - (int) config('credits.mcp.tools.create_post'), $this->balance());
    }

    public function test_each_image_in_a_published_post_costs_the_same_as_an_ai_upload(): void
    {
        // Same total as the AI path (upload_media per image + create_post), but
        // charged at publish for the media actually in the post, so uploading
        // or swapping images in the composer costs nothing.
        $this->fundCredits($this->user, 100);

        $this->sendPost('scheduled', images: 2)->assertCreated();

        $expected = (int) config('credits.mcp.tools.create_post') + 2 * (int) config('credits.mcp.tools.upload_media');
        $this->assertSame(100 - $expected, $this->balance()); // 10 + 2 × 5 = 20
    }

    public function test_publishing_a_draft_with_an_image_charges_for_the_image_once(): void
    {
        $this->fundCredits($this->user, 100);
        $id = $this->sendPost('draft', images: 1)->assertCreated()->json('id');
        $this->assertSame(90, $this->balance()); // a draft costs create_post; its image is charged when it goes out

        $this->putJson("/api/posts/{$id}", [
            'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(), 'platforms' => ['x'],
            'caption' => 'Hello', 'media' => [['type' => 'image', 'url' => 'https://example.com/swapped.jpg']],
        ], $this->headers())->assertOk();
        $this->assertSame(80, $this->balance()); // edit 5 + the image 5

        // Editing it again doesn't charge for the same image twice.
        $this->putJson("/api/posts/{$id}", ['caption' => 'Edited'], $this->headers())->assertOk();
        $this->assertSame(75, $this->balance()); // edit 5 only
    }

    public function test_not_enough_credits_for_the_images_refuses_the_publish(): void
    {
        $this->fundCredits($this->user, 12);

        $this->sendPost('scheduled', images: 1)
            ->assertStatus(402)
            ->assertJsonPath('message', 'Not enough credits to create a post with 1 image or video (15 needed, 12 available). Choose a plan to get more credits.');

        $this->assertSame(0, Post::count());
    }

    public function test_a_draft_costs_the_same_as_the_ai_and_every_edit_costs_update_post(): void
    {
        $this->fundCredits($this->user, 100);

        $id = $this->sendPost('draft')->assertCreated()->json('id');
        $this->assertSame(90, $this->balance()); // create_post, draft or not

        $publish = ['status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(), 'platforms' => ['x'], 'caption' => 'Hello'];
        $this->putJson("/api/posts/{$id}", $publish, $this->headers())->assertOk();
        $this->assertSame(85, $this->balance()); // update_post

        $this->putJson("/api/posts/{$id}", ['caption' => 'Edited'] + $publish, $this->headers())->assertOk();
        $this->assertSame(80, $this->balance()); // update_post again

        $this->deleteJson("/api/posts/{$id}", [], $this->headers())
            ->assertSuccessful()
            ->assertHeader('X-User-Credits', '75'); // no JSON body: the badge reads the header
        $this->assertSame(75, $this->balance()); // delete_post
    }

    public function test_an_x_post_with_a_link_costs_the_x_link_price(): void
    {
        $this->fundCredits($this->user, 100);
        $scheduled = ['status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(), 'platforms' => ['x']];

        $this->postJson('/api/posts', ['caption' => 'Read https://example.com/post'] + $scheduled, $this->headers())
            ->assertCreated();
        $this->assertSame(65, $this->balance()); // create_post 10 + x_link 25

        // A comment with a link is its own X post.
        $this->postJson('/api/posts', ['caption' => 'No link here', 'comments' => [['body' => 'More at www.example.com']]] + $scheduled, $this->headers())
            ->assertCreated();
        $this->assertSame(30, $this->balance());
    }

    public function test_a_draft_with_a_link_is_charged_for_it_when_it_goes_out(): void
    {
        $this->fundCredits($this->user, 100);
        $id = $this->postJson('/api/posts', ['caption' => 'See example.com', 'platforms' => ['x'], 'status' => 'draft'], $this->headers())
            ->assertCreated()->json('id');
        $this->assertSame(90, $this->balance());

        $this->putJson("/api/posts/{$id}", ['status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String()], $this->headers())
            ->assertOk();
        $this->assertSame(60, $this->balance()); // update_post 5 + x_link 25

        $this->putJson("/api/posts/{$id}", ['caption' => 'See example.com today'], $this->headers())->assertOk();
        $this->assertSame(55, $this->balance()); // already out: the link isn't charged again
    }

    public function test_an_edit_price_of_zero_makes_editing_free(): void
    {
        config(['credits.web.tools.update_post' => 0]);
        $this->fundCredits($this->user, 100);
        $id = $this->sendPost('draft')->assertCreated()->json('id');

        $this->putJson("/api/posts/{$id}", ['caption' => 'Edited', 'platforms' => ['x']], $this->headers())->assertOk();

        $this->assertSame(90, $this->balance());
    }

    public function test_website_only_actions_are_free_until_priced(): void
    {
        $this->fundCredits($this->user, 100);
        $offer = $this->user->offers()->create(['offer_url' => 'https://example.com/a']);
        $link = $offer->links()->create(['name' => 'Bio', 'placement' => 'youtube', 'parameter_id' => 'bio1']);

        $this->putJson("/api/tracking-links/{$link->id}", ['name' => 'Bio 2'], $this->headers())->assertOk();
        $this->assertSame(100, $this->balance());

        config(['credits.web.tools.update_tracking_link' => 2]);
        $this->putJson("/api/tracking-links/{$link->id}", ['name' => 'Bio 3'], $this->headers())->assertOk();
        $this->assertSame(98, $this->balance());
    }

    public function test_creating_an_offer_costs_the_same_as_the_ai(): void
    {
        $this->fundCredits($this->user, 100);

        $this->postJson('/api/tracking-events', ['offer_url' => 'https://example.com/a', 'name' => 'A'], $this->headers())
            ->assertSuccessful();

        $this->assertSame(95, $this->balance());
    }

    public function test_not_enough_credits_refuses_before_anything_happens(): void
    {
        $this->fundCredits($this->user, 3);

        $this->sendPost('scheduled')
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_credits')
            ->assertJsonPath('message', 'Not enough credits to create a post (10 needed, 3 available). Choose a plan to get more credits.');

        $this->assertSame(0, Post::count());
        $this->assertSame(3, $this->balance());
    }

    public function test_a_failed_action_is_free(): void
    {
        $this->fundCredits($this->user, 100);

        $this->postJson('/api/outliers/search', [], $this->headers())->assertStatus(400); // no search term

        $this->assertSame(100, $this->balance());
    }

    public function test_an_outlier_search_costs_the_same_as_the_ai_search(): void
    {
        $this->fundCredits($this->user, 100);
        $this->mock(YouTubeSearchService::class)->shouldReceive('search')->once();

        $this->postJson('/api/outliers/search', ['term' => 'home cooking'], $this->headers())->assertOk();

        $this->assertSame(100 - (int) config('credits.mcp.tools.search_outliers'), $this->balance());
    }

    public function test_the_response_carries_the_new_balance_for_the_credits_badge(): void
    {
        $this->fundCredits($this->user, 100);

        $this->sendPost('scheduled')->assertCreated()->assertJsonPath('user_credits', 90);
    }

    public function test_reading_pages_is_free_by_default(): void
    {
        $this->fundCredits($this->user, 100);

        $this->getJson('/api/posts', $this->headers())->assertOk();
        $this->getJson('/api/outliers', $this->headers());

        $this->assertSame(100, $this->balance());
    }

    public function test_a_read_price_charges_every_read_but_never_the_account_and_billing_pages(): void
    {
        config(['credits.web.read_default' => 1]);
        $this->fundCredits($this->user, 100);

        $this->getJson('/api/posts', $this->headers())->assertOk();
        $this->assertSame(99, $this->balance());

        $this->getJson('/api/profile', $this->headers())->assertOk();
        $this->getJson('/api/plans', $this->headers())->assertOk();
        $this->assertSame(99, $this->balance());
    }

    public function test_an_ai_publish_is_charged_once_not_twice(): void
    {
        $this->fundCredits($this->user, 100);
        $key = $this->withHeaders(['Authorization' => 'Bearer ' . $this->user->createToken('m')->plainTextToken])
            ->postJson('/api/user/api-key/rotate')->json('data.key');

        $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                'name' => 'create_post',
                'arguments' => ['caption' => 'Hi', 'platforms' => ['x'], 'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String()],
            ]])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertSame(90, $this->balance());
    }
}
