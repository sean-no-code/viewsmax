<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * REST scheduling (POST/PUT /api/posts). A scheduled post must carry a time
 * posts:publish-due can act on, stored in UTC whatever offset the caller sent.
 */
class PostScheduleTimeTest extends TestCase
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

    public function test_scheduled_post_without_a_time_is_rejected(): void
    {
        // Otherwise it would sit in "scheduled" forever: the publish command
        // only picks up rows with a scheduled_at.
        $this->withHeaders($this->auth())
            ->postJson('/api/posts', ['caption' => 'hi', 'platforms' => ['x'], 'status' => 'scheduled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);

        $this->assertSame(0, Post::count());
    }

    public function test_offset_in_scheduled_at_is_converted_to_utc(): void
    {
        // Mirrors the MCP tool's behaviour: 11:55 at +03:00 is 08:55 UTC.
        $this->withHeaders($this->auth())
            ->postJson('/api/posts', [
                'caption' => 'hi',
                'platforms' => ['x'],
                'status' => 'scheduled',
                'scheduled_at' => '2030-01-15T11:55:00+03:00',
            ])
            ->assertStatus(201);

        $this->assertSame('2030-01-15 08:55:00', Post::sole()->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_utc_and_naive_times_are_stored_unchanged(): void
    {
        foreach (['2030-01-15T08:55:00Z', '2030-01-15 08:55:00'] as $when) {
            $this->withHeaders($this->auth())
                ->postJson('/api/posts', ['caption' => 'hi', 'platforms' => ['x'], 'status' => 'scheduled', 'scheduled_at' => $when])
                ->assertStatus(201);
        }

        $this->assertSame(
            ['2030-01-15 08:55:00', '2030-01-15 08:55:00'],
            Post::orderBy('id')->get()->map(fn ($p) => $p->scheduled_at->format('Y-m-d H:i:s'))->all()
        );
    }

    public function test_update_cannot_strip_the_time_from_a_scheduled_post(): void
    {
        $post = $this->user->posts()->create([
            'caption' => 'hi', 'media' => [], 'status' => Post::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour(),
        ]);
        $post->targets()->create(['platform' => 'x', 'status' => 'pending']);

        $this->withHeaders($this->auth())
            ->putJson("/api/posts/{$post->id}", ['scheduled_at' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);

        // Changing only the caption keeps the saved time and is still fine.
        $this->withHeaders($this->auth())
            ->putJson("/api/posts/{$post->id}", ['caption' => 'edited'])
            ->assertOk();
        $this->assertNotNull($post->fresh()->scheduled_at);
    }
}
