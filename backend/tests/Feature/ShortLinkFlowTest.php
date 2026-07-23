<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShortLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Shortlinks: URLs in a post's caption/overrides/comments are replaced with
 * tracked /l/{slug} links at save time (opt-in via shorten_links). The
 * redirect endpoint 302s to the destination and records the click.
 */
class ShortLinkFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    public function test_service_replaces_urls_and_is_idempotent(): void
    {
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'media' => [], 'status' => Post::STATUS_DRAFT]);
        $service = app(ShortLinkService::class);

        $text = 'Check https://example.com/offer?ref=yt today! Also http://other.io/page.';
        $first = $service->shortenUrlsInText($this->user, $post, $text);

        $this->assertStringNotContainsString('example.com/offer', $first);
        $this->assertStringNotContainsString('other.io/page', $first);
        $this->assertStringContainsString('/l/', $first);
        // Trailing punctuation stays outside the link.
        $this->assertStringContainsString('.', $first);
        $this->assertSame(2, ShortLink::count());

        // Same text again → same slugs, no new rows.
        $second = $service->shortenUrlsInText($this->user, $post, $text);
        $this->assertSame($first, $second);
        $this->assertSame(2, ShortLink::count());

        // Already-shortened text passes through untouched.
        $third = $service->shortenUrlsInText($this->user, $post, $first);
        $this->assertSame($first, $third);
        $this->assertSame(2, ShortLink::count());
    }

    public function test_redirect_counts_click_and_records_detail(): void
    {
        Queue::fake();
        $link = ShortLink::create([
            'user_id' => $this->user->id,
            'slug' => 'abc1234',
            'destination_url' => 'https://example.com/target',
        ]);

        $response = $this->get('/l/abc1234', ['Referer' => 'https://x.com/somebody']);

        $response->assertRedirect('https://example.com/target');
        $this->assertSame(1, $link->fresh()->clicks_count);
        $this->assertNotNull($link->fresh()->last_clicked_at);
        $this->assertDatabaseHas('short_link_clicks', [
            'short_link_id' => $link->id,
            'referer' => 'https://x.com/somebody',
        ]);
    }

    public function test_unknown_slug_redirects_to_frontend(): void
    {
        $this->get('/l/nope999')->assertRedirect();
    }

    public function test_post_created_with_shorten_links_stores_short_urls(): void
    {
        Queue::fake();
        $response = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'Grab it here: https://example.com/product',
            'platforms' => ['x'],
            'status' => 'draft',
            'shorten_links' => true,
            'overrides' => ['x' => 'X only: https://example.com/product?src=x'],
            'comments' => [['body' => 'More info https://example.com/faq', 'delay_seconds' => 0]],
        ]);

        $response->assertStatus(201);
        $post = Post::findOrFail($response->json('id'));

        $this->assertStringContainsString('/l/', $post->caption);
        $this->assertStringNotContainsString('example.com/product', $post->caption);
        $this->assertStringContainsString('/l/', $post->targets()->first()->caption_override);
        $this->assertStringContainsString('/l/', $post->comments()->first()->body);
        $this->assertSame(3, ShortLink::where('post_id', $post->id)->count());
    }

    public function test_editing_a_post_reuses_existing_slugs(): void
    {
        Queue::fake();
        $id = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'Grab it: https://example.com/product',
            'platforms' => ['x'],
            'status' => 'draft',
            'shorten_links' => true,
        ])->json('id');

        $slug = ShortLink::firstOrFail()->slug;

        // Re-save with the original URL again (user re-typed it).
        $this->withHeaders($this->auth())->putJson("/api/posts/{$id}", [
            'caption' => 'Updated copy — https://example.com/product',
            'platforms' => ['x'],
            'shorten_links' => true,
            'status' => 'draft',
        ])->assertOk();

        $this->assertSame(1, ShortLink::count());
        $this->assertStringContainsString($slug, Post::findOrFail($id)->caption);
    }

    public function test_without_the_flag_urls_are_left_alone(): void
    {
        Queue::fake();
        $response = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'Plain https://example.com/stay',
            'platforms' => ['x'],
            'status' => 'draft',
        ]);

        $this->assertStringContainsString('example.com/stay', Post::findOrFail($response->json('id'))->caption);
        $this->assertSame(0, ShortLink::count());
    }
}
