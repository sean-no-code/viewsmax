<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Publish-time caption limit enforcement in PostController. Drafts stay
 * unrestricted; scheduling or posting an over-limit caption is a 422 so a
 * doomed post can never reach a platform API.
 */
class PostCaptionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function createPost(array $payload)
    {
        return $this->withHeaders($this->auth())->postJson('/api/posts', $payload);
    }

    public function test_draft_with_over_limit_caption_is_allowed(): void
    {
        $this->createPost([
            'caption' => str_repeat('a', 400),
            'platforms' => ['x'],
            'status' => 'draft',
        ])->assertStatus(201);
    }

    public function test_scheduling_over_limit_linkedin_caption_is_rejected(): void
    {
        $response = $this->createPost([
            'caption' => str_repeat('a', 3001),
            'platforms' => ['linkedin'],
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour()->toDateTimeString(),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('linkedin', $response->json('message'));
        $this->assertStringContainsString('3000', $response->json('message'));
    }

    public function test_posting_over_limit_x_caption_is_rejected(): void
    {
        $this->createPost([
            'caption' => str_repeat('a', 281),
            'platforms' => ['x'],
            'status' => 'posted',
        ])->assertStatus(422);
    }

    public function test_x_urls_count_as_23_characters(): void
    {
        // 256 plain chars + space + a URL far longer than 23 chars = exactly 280 weighted.
        $caption = str_repeat('a', 256).' https://example.com/a/very/long/path/way/past/23/chars';

        $this->createPost([
            'caption' => $caption,
            'platforms' => ['x'],
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour()->toDateTimeString(),
        ])->assertStatus(201);
    }

    public function test_over_limit_thread_segment_is_rejected_naming_the_tweet(): void
    {
        $response = $this->createPost([
            'caption' => "ok\n---\n".str_repeat('b', 300),
            'platforms' => ['x'],
            'status' => 'posted',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Tweet 2', $response->json('message'));
    }

    public function test_thread_with_too_many_segments_is_rejected(): void
    {
        $this->createPost([
            'caption' => implode("\n---\n", array_fill(0, 26, 'tweet')),
            'platforms' => ['x'],
            'status' => 'posted',
        ])->assertStatus(422);
    }

    public function test_thread_with_empty_segment_is_rejected(): void
    {
        $response = $this->createPost([
            'caption' => "a\n---\n---\nb",
            'platforms' => ['x'],
            'status' => 'posted',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('empty', $response->json('message'));
    }

    public function test_short_x_override_beats_long_main_caption(): void
    {
        $this->createPost([
            'caption' => str_repeat('a', 400),
            'platforms' => ['x'],
            'overrides' => ['x' => 'short tweet'],
            'status' => 'posted',
        ])->assertStatus(201);
    }

    public function test_over_limit_x_override_is_rejected(): void
    {
        $this->createPost([
            'caption' => 'short',
            'platforms' => ['x'],
            'overrides' => ['x' => str_repeat('a', 300)],
            'status' => 'posted',
        ])->assertStatus(422);
    }

    public function test_thread_delimiter_is_literal_text_on_other_platforms(): void
    {
        // 450 plain chars including --- lines: over X's 280 if it were one
        // tweet, but under Threads' 500 — and --- must not split on Threads.
        $segment = str_repeat('a', 220);
        $caption = "{$segment}\n---\n{$segment}";

        $this->createPost([
            'caption' => $caption,
            'platforms' => ['threads'],
            'status' => 'posted',
        ])->assertStatus(201);
    }

    public function test_updating_a_draft_to_scheduled_enforces_limits(): void
    {
        $id = $this->createPost([
            'caption' => str_repeat('a', 400),
            'platforms' => ['x'],
            'status' => 'draft',
        ])->json('id');

        $this->withHeaders($this->auth())
            ->putJson("/api/posts/{$id}", [
                'status' => 'scheduled',
                'scheduled_at' => now()->addHour()->toDateTimeString(),
                'platforms' => ['x'],
            ])
            ->assertStatus(422);
    }

    public function test_update_upserts_targets_preserving_meta(): void
    {
        $id = $this->createPost([
            'caption' => 'hello',
            'platforms' => ['x', 'linkedin'],
            'status' => 'draft',
        ])->json('id');

        $post = $this->user->posts()->findOrFail($id);
        $xTarget = $post->targets()->where('platform', 'x')->firstOrFail();
        $xTarget->forceFill(['meta' => ['x_thread' => ['tweet_ids' => ['t1']]]])->save();

        $this->withHeaders($this->auth())
            ->putJson("/api/posts/{$id}", [
                'caption' => 'hello again',
                'platforms' => ['x', 'threads'],
                'overrides' => ['x' => 'custom'],
                'status' => 'draft',
            ])
            ->assertStatus(200);

        $post->refresh();
        $fresh = $post->targets()->where('platform', 'x')->firstOrFail();
        $this->assertSame($xTarget->id, $fresh->id, 'X target row must be updated, not recreated');
        $this->assertSame(['t1'], $fresh->meta['x_thread']['tweet_ids'] ?? null);
        $this->assertSame('custom', $fresh->caption_override);
        $this->assertNull($post->targets()->where('platform', 'linkedin')->first());
        $this->assertNotNull($post->targets()->where('platform', 'threads')->first());
    }
}
