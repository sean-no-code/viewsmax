<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * App\Support\PostAllowance: an account that hasn't paid yet (card-free window
 * or the $0 Stripe trial) may schedule or publish a fixed number of posts in
 * total. Drafts are never counted, and posts already scheduled keep publishing.
 */
class TrialPostLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['publishing.trial_posts' => 2]);
    }

    private function auth(User $user): array
    {
        // A second bearer user in one test would otherwise reuse the first's guard.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function schedule(User $user, array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->auth($user))->postJson('/api/posts', $overrides + [
            'caption' => 'hi',
            'platforms' => ['x'],
            'status' => 'scheduled',
            'scheduled_at' => '2030-01-15T08:55:00Z',
        ]);
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('code', 'post_limit')
            ->assertJsonPath('used', 2)
            ->assertJsonPath('cap', 2);
        $this->assertStringContainsString('all 2 posts', $response->json('message'));
    }

    public function test_window_user_is_refused_past_the_cap_but_can_still_draft(): void
    {
        $user = User::factory()->create();

        $this->schedule($user)->assertStatus(201);
        $this->schedule($user)->assertStatus(201);
        $this->assertRefused($this->schedule($user));
        $this->assertRefused($this->schedule($user, ['status' => 'posted', 'scheduled_at' => null]));

        $this->schedule($user, ['status' => 'draft'])->assertStatus(201);
        $this->assertSame(2, $user->posts()->where('status', Post::STATUS_SCHEDULED)->count());
    }

    public function test_trialing_user_is_capped(): void
    {
        $user = User::factory()->withPlan('trialing')->create();

        $this->schedule($user)->assertStatus(201);
        $this->schedule($user)->assertStatus(201);
        $this->assertRefused($this->schedule($user));
    }

    public function test_paying_promotional_and_admin_users_are_not_capped(): void
    {
        foreach ([
            User::factory()->withPlan('active')->create(),
            User::factory()->promotional()->create(),
            User::factory()->admin()->create(),
        ] as $user) {
            foreach (range(1, 3) as $i) {
                $this->schedule($user)->assertStatus(201);
            }
        }
    }

    public function test_blank_config_means_unlimited(): void
    {
        config(['publishing.trial_posts' => null]);
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->schedule($user)->assertStatus(201);
        }
    }

    public function test_scheduling_a_draft_at_the_cap_is_refused(): void
    {
        $user = User::factory()->create();
        $draft = $this->schedule($user, ['status' => 'draft'])->json('id');
        $this->schedule($user);
        $this->schedule($user);

        $this->assertRefused($this->withHeaders($this->auth($user))->putJson("/api/posts/{$draft}", [
            'status' => 'scheduled',
            'scheduled_at' => '2030-01-15T08:55:00Z',
        ]));
        $this->assertSame(Post::STATUS_DRAFT, Post::find($draft)->status);
    }

    public function test_a_post_already_scheduled_can_be_rescheduled_and_published_at_the_cap(): void
    {
        $user = User::factory()->create();
        $first = $this->schedule($user)->json('id');
        $second = $this->schedule($user)->json('id');

        $this->withHeaders($this->auth($user))
            ->putJson("/api/posts/{$first}", ['status' => 'scheduled', 'scheduled_at' => '2030-02-01T10:00:00Z'])
            ->assertOk();
        $this->withHeaders($this->auth($user))
            ->putJson("/api/posts/{$second}", ['status' => 'posted'])
            ->assertOk();

        // One scheduled, one published: still two used.
        $this->assertRefused($this->schedule($user));
    }

    public function test_deleting_a_scheduled_post_frees_its_slot_but_a_published_one_does_not(): void
    {
        $user = User::factory()->create();
        $scheduled = $this->schedule($user)->json('id');
        $posted = $this->schedule($user, ['status' => 'posted', 'scheduled_at' => null])->json('id');
        $this->assertSame(1, $user->fresh()->posts_published_count);

        $this->withHeaders($this->auth($user))->deleteJson("/api/posts/{$posted}")->assertNoContent();
        $this->assertRefused($this->schedule($user));

        $this->withHeaders($this->auth($user))->deleteJson("/api/posts/{$scheduled}")->assertNoContent();
        $this->schedule($user)->assertStatus(201);
    }

    public function test_posts_scheduled_before_the_cap_still_publish(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $post = $user->posts()->create([
                'caption' => 'hi',
                'media' => [],
                'status' => Post::STATUS_SCHEDULED,
                'scheduled_at' => now()->subMinute(),
            ]);
            $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);
        }

        $this->artisan('posts:publish-due')->assertExitCode(0);

        $this->assertSame(3, $user->posts()->where('status', Post::STATUS_POSTED)->count());
        $this->assertSame(3, $user->fresh()->posts_published_count);
    }

    public function test_legacy_social_posts_endpoint_is_capped_too(): void
    {
        $user = User::factory()->create();
        $this->schedule($user);
        $this->schedule($user);

        $this->assertRefused($this->withHeaders($this->auth($user))->postJson('/api/social/posts', [
            'content' => 'hi',
            'account_ids' => [1],
        ]));
        $this->assertSame(0, $user->socialPosts()->count());
    }
}
