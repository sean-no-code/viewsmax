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
 * Website actions that match an AI (MCP) tool cost the same credits as that
 * tool, from the same price list (config/credits.php `mcp`). Same rules as the
 * AI: refused up front when the balance is too low, charged only when the
 * action succeeds, never below zero. Reading pages stays free, and saving a
 * draft is free: a post is charged when it is published or scheduled.
 */
class WebActionCreditsTest extends TestCase
{
    use RefreshDatabase;

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

    private function sendPost(string $status): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/posts', array_filter([
            'caption' => 'Hello', 'platforms' => ['x'], 'status' => $status,
            'scheduled_at' => $status === 'scheduled' ? now()->addDay()->toIso8601String() : null,
        ]), $this->headers());
    }

    public function test_the_website_uses_the_same_prices_as_the_ai_tools(): void
    {
        $routes = [
            'POST api/posts' => 'create_post',
            'PUT api/posts/{post}' => 'create_post',
            'POST api/outliers/search' => 'search_outliers',
            'POST api/outliers/fetch' => 'fetch_outlier',
            'POST api/outliers/{platform}/{videoId}/breakdown' => 'generate_outlier_breakdown',
            'POST api/outliers/channels/add' => 'add_outlier_channel',
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
            $charging = array_filter($found->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'credits.web'));
            $this->assertSame([], array_values($charging), "{$route} must not charge credits");
        }
    }

    public function test_publishing_a_post_costs_the_same_as_the_ai_create_post(): void
    {
        $this->fundCredits($this->user, 100);

        $this->sendPost('scheduled')->assertCreated();

        $this->assertSame(100 - (int) config('credits.mcp.tools.create_post'), $this->balance());
    }

    public function test_saving_a_draft_is_free_and_publishing_it_later_is_charged_once(): void
    {
        $this->fundCredits($this->user, 100);

        $id = $this->sendPost('draft')->assertCreated()->json('id');
        $this->assertSame(100, $this->balance());

        $publish = ['status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(), 'platforms' => ['x'], 'caption' => 'Hello'];
        $this->putJson("/api/posts/{$id}", $publish, $this->headers())->assertOk();
        $this->assertSame(90, $this->balance());

        // Editing a post that is already scheduled doesn't charge again.
        $this->putJson("/api/posts/{$id}", ['caption' => 'Edited'] + $publish, $this->headers())->assertOk();
        $this->assertSame(90, $this->balance());
    }

    public function test_not_enough_credits_refuses_before_anything_happens(): void
    {
        $this->fundCredits($this->user, 3);

        $this->sendPost('scheduled')
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_credits')
            ->assertJsonPath('message', 'Not enough credits to publish a post (10 needed, 3 available). Choose a plan to get more credits.');

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

    public function test_reading_pages_stays_free(): void
    {
        $this->fundCredits($this->user, 100);

        $this->getJson('/api/posts', $this->headers())->assertOk();
        $this->getJson('/api/outliers', $this->headers());

        $this->assertSame(100, $this->balance());
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
