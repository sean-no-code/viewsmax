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

    public function test_threads_limit_counts_utf8_bytes(): void
    {
        // 497 ASCII + one emoji: 498 code points, but 501 bytes — Meta counts
        // bytes and would reject it with "text must be at most 500 characters".
        $response = $this->createPost([
            'caption' => str_repeat('a', 497).'😀',
            'platforms' => ['threads'],
            'status' => 'posted',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Threads', $response->json('message'));
        $this->assertStringContainsString('1 character over', $response->json('message'));
    }

    public function test_threads_accepts_exactly_500_bytes(): void
    {
        $this->createPost([
            'caption' => str_repeat('a', 496).'😀',
            'platforms' => ['threads'],
            'status' => 'posted',
        ])->assertStatus(201);
    }

    public function test_other_platforms_still_count_code_points(): void
    {
        // The same 501-byte caption is 498 code points: fine on Bluesky-style
        // code-point platforms with room to spare (Instagram's 2200).
        $this->createPost([
            'caption' => str_repeat('a', 497).'😀',
            'platforms' => ['instagram'],
            'status' => 'draft',
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

    private const YT_VIDEO = [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4', 'path' => 'posts/1/clip.mp4']];

    public function test_posting_to_youtube_without_a_caption_is_rejected(): void
    {
        $response = $this->createPost([
            'caption' => '   ',
            'platforms' => ['youtube'],
            'media' => self::YT_VIDEO,
            'status' => 'posted',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('YouTube requires a title', $response->json('message'));
    }

    public function test_scheduling_youtube_when_only_another_platform_has_text_is_rejected(): void
    {
        $this->createPost([
            'caption' => '',
            'platforms' => ['youtube', 'x'],
            'overrides' => ['x' => 'Only X has text'],
            'media' => self::YT_VIDEO,
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonFragment(['message' => \App\Services\Social\CaptionRules::YOUTUBE_TITLE_REQUIRED]);
    }

    public function test_youtube_override_counts_as_the_title(): void
    {
        $this->createPost([
            'caption' => '',
            'platforms' => ['youtube'],
            'overrides' => ['youtube' => 'My video title'],
            'media' => self::YT_VIDEO,
            'status' => 'posted',
        ])->assertStatus(201);
    }

    public function test_youtube_draft_without_a_caption_is_allowed(): void
    {
        $this->createPost([
            'platforms' => ['youtube'],
            'media' => self::YT_VIDEO,
            'status' => 'draft',
        ])->assertStatus(201);
    }

    public function test_publishing_an_untitled_youtube_draft_is_rejected(): void
    {
        $id = $this->createPost([
            'platforms' => ['youtube'],
            'media' => self::YT_VIDEO,
            'status' => 'draft',
        ])->json('id');

        $this->withHeaders($this->auth())
            ->putJson("/api/posts/{$id}", ['status' => 'posted', 'media' => self::YT_VIDEO])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => \App\Services\Social\CaptionRules::YOUTUBE_TITLE_REQUIRED]);
    }

    /* ---------- Bluesky counts graphemes, not code points ---------- */

    public function test_bluesky_counts_graphemes_so_emoji_sequences_and_combining_marks_fit(): void
    {
        // 👨‍👩‍👧 is 5 code points / 1 grapheme; "é" as e + combining acute is 2 / 1.
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $caption = str_repeat($family, 150).str_repeat("e\u{0301}", 150); // exactly 300 graphemes

        $this->assertSame(300, \App\Services\Social\CaptionRules::lengthFor('bluesky', $caption));
        $this->assertSame(1050, \App\Services\Social\CaptionRules::plainLength($caption));
        $this->assertNull(\App\Services\Social\CaptionRules::captionError('bluesky', $caption));
        $this->assertNotNull(\App\Services\Social\CaptionRules::captionError('bluesky', $caption.'x'));

        $this->createPost([
            'caption' => $caption,
            'platforms' => ['bluesky'],
            'status' => 'posted',
        ])->assertStatus(201);

        $response = $this->createPost([
            'caption' => $caption.'x',
            'platforms' => ['bluesky'],
            'status' => 'posted',
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString("1 character over Bluesky's 300 limit", $response->json('message'));
    }

    public function test_the_same_caption_is_graphemes_on_bluesky_and_bytes_on_threads(): void
    {
        $caption = str_repeat("e\u{0301}", 251); // 502 code points, 251 graphemes, 753 UTF-8 bytes

        $this->assertSame(251, \App\Services\Social\CaptionRules::lengthFor('bluesky', $caption));
        $this->assertSame(753, \App\Services\Social\CaptionRules::lengthFor('threads', $caption));
        $this->assertSame(502, \App\Services\Social\CaptionRules::lengthFor('instagram', $caption));
        $this->assertNull(\App\Services\Social\CaptionRules::captionError('bluesky', $caption));
        $this->assertNotNull(\App\Services\Social\CaptionRules::captionError('threads', $caption));
    }
}
