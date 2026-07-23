<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/social/x/posts lists the user's X posts published through the app
 * (both the legacy Post/PostTarget store and the SocialPost store) so a
 * tracking link can be pinned to one. Pure DB read — no X API calls.
 */
class XPublishedPostsTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function publishLegacy(User $user, string $tweetId, string $caption, string $publishedAt): void
    {
        $post = Post::create(['user_id' => $user->id, 'caption' => $caption, 'media' => [], 'status' => Post::STATUS_POSTED]);
        $post->targets()->create([
            'platform' => 'x',
            'status' => PostTarget::STATUS_PUBLISHED,
            'platform_post_id' => $tweetId,
            'published_at' => $publishedAt,
            'meta' => ['url' => "https://x.com/tester/status/{$tweetId}"],
        ]);
    }

    private function publishSocial(User $user, string $tweetId, string $content, string $publishedAt): void
    {
        $account = $user->socialAccounts()->create([
            'platform' => 'x', 'platform_account_id' => 'x-'.$tweetId, 'name' => 'T',
            'access_token' => 't', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $post = SocialPost::create(['user_id' => $user->id, 'content' => $content, 'media' => [], 'status' => SocialPost::STATUS_PUBLISHED]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'platform' => 'x',
            'status' => SocialPostTarget::STATUS_PUBLISHED,
            'remote_post_id' => $tweetId,
            'remote_post_url' => "https://x.com/tester/status/{$tweetId}",
            'published_at' => $publishedAt,
        ]);
    }

    public function test_lists_published_x_posts_from_both_stores_newest_first(): void
    {
        $user = User::factory()->create();
        $this->publishLegacy($user, 't-old', 'Older legacy tweet', now()->subDays(2)->toDateTimeString());
        $this->publishSocial($user, 't-new', 'Newer social tweet', now()->subDay()->toDateTimeString());

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/social/x/posts');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('t-new', $data[0]['id']);
        $this->assertSame('t-old', $data[1]['id']);
        $this->assertSame('Newer social tweet', $data[0]['text']);
        $this->assertStringContainsString('t-new', $data[0]['url']);
        $this->assertNotNull($data[0]['posted_at']);
    }

    public function test_thread_captions_list_only_the_first_tweet_text(): void
    {
        $user = User::factory()->create();
        $this->publishLegacy($user, 't1', "First tweet of thread\n---\nsecond tweet", now()->toDateTimeString());

        $data = $this->withHeaders($this->authHeaders($user))->getJson('/api/social/x/posts')->json('data');

        $this->assertSame('First tweet of thread', $data[0]['text']);
    }

    public function test_excludes_unpublished_targets_and_other_users_posts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Pending target (no tweet id yet) — must not appear.
        $pending = Post::create(['user_id' => $user->id, 'caption' => 'Pending', 'media' => [], 'status' => Post::STATUS_POSTED]);
        $pending->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);
        // Another user's published post — must not appear.
        $this->publishLegacy($other, 't-other', 'Not yours', now()->toDateTimeString());

        $data = $this->withHeaders($this->authHeaders($user))->getJson('/api/social/x/posts')->json('data');

        $this->assertSame([], $data);
    }
}
