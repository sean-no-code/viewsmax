<?php

namespace Tests\Feature;

use App\Jobs\PostTargetCommentJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetComment;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Comments on posts: extra messages posted after a target publishes, each with
 * an optional delay. Platforms that support it (x/linkedin/threads/instagram)
 * post them as replies/comments; others mark them skipped. Comment jobs chain
 * sequentially so ordering survives delays and worker restarts.
 */
class PostCommentsFlowTest extends TestCase
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

    private function connect(string $platform, array $metadata = []): SocialAccount
    {
        return $this->user->socialAccounts()->create([
            'platform' => $platform,
            'platform_account_id' => "{$platform}-1",
            'name' => 'Tester',
            'username' => 'tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => $metadata,
        ]);
    }

    /** A published target carrying two pending comments. */
    private function publishedTargetWithComments(string $platform, array $meta = [], array $metadata = []): PostTarget
    {
        $account = $this->connect($platform, $metadata);
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'main post',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $post->comments()->create(['position' => 0, 'body' => 'first comment', 'delay_seconds' => 0]);
        $post->comments()->create(['position' => 1, 'body' => 'second comment', 'delay_seconds' => 300]);

        return $post->targets()->create([
            'platform' => $platform,
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHING,
            'platform_post_id' => null,
            'meta' => $meta,
        ]);
    }

    private function markPublished(PostTarget $target, string $remoteId, array $extraMeta = []): void
    {
        $target->forceFill([
            'status' => PostTarget::STATUS_PUBLISHED,
            'platform_post_id' => $remoteId,
            'published_at' => now(),
            'meta' => array_merge($target->meta ?? [], $extraMeta),
        ])->save();
    }

    public function test_comments_persist_and_return_in_payload(): void
    {
        Queue::fake();
        $response = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'hello',
            'platforms' => ['x'],
            'status' => 'draft',
            'comments' => [
                ['body' => 'first', 'delay_seconds' => 0],
                ['body' => 'second', 'delay_seconds' => 600],
            ],
        ]);

        $response->assertStatus(201);
        $id = $response->json('id');

        $shown = $this->withHeaders($this->auth())->getJson("/api/posts/{$id}")->json();
        $this->assertCount(2, $shown['comments']);
        $this->assertSame('first', $shown['comments'][0]['body']);
        $this->assertSame(600, $shown['comments'][1]['delay_seconds']);
    }

    public function test_publishing_target_starts_the_comment_chain(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        $target = $this->publishedTargetWithComments('x');

        $this->markPublished($target, 't-root');

        // Rows for both comments; only the FIRST job queued (it chains the next).
        $this->assertSame(2, PostTargetComment::where('post_target_id', $target->id)->count());
        Queue::assertPushed(PostTargetCommentJob::class, 1);
    }

    public function test_x_comment_posts_reply_and_chains_next_with_delay(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        $bodies = [];
        Http::fake([
            'api.twitter.com/2/tweets' => function ($request) use (&$bodies) {
                $bodies[] = $request->data();

                return Http::response(['data' => ['id' => 'c'.count($bodies)]]);
            },
        ]);

        $target = $this->publishedTargetWithComments('x');
        $this->markPublished($target, 't-root');

        $first = PostTargetComment::where('post_target_id', $target->id)
            ->whereHas('comment', fn ($q) => $q->where('position', 0))->firstOrFail();

        (new PostTargetCommentJob($first->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $first->refresh();
        $this->assertSame(PostTargetComment::STATUS_POSTED, $first->status, (string) $first->error);
        $this->assertSame('c1', $first->platform_comment_id);
        $this->assertSame('t-root', $bodies[0]['reply']['in_reply_to_tweet_id'] ?? null);
        $this->assertSame('first comment', $bodies[0]['text'] ?? null);

        // The next comment's job is dispatched by this one, delayed 300s.
        Queue::assertPushed(PostTargetCommentJob::class, function (PostTargetCommentJob $job) {
            return $job->delay !== null;
        });
    }

    public function test_x_thread_comments_anchor_to_the_last_segment(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        $bodies = [];
        Http::fake([
            'api.twitter.com/2/tweets' => function ($request) use (&$bodies) {
                $bodies[] = $request->data();

                return Http::response(['data' => ['id' => 'c1']]);
            },
        ]);

        $target = $this->publishedTargetWithComments('x');
        $this->markPublished($target, 't1', ['x_thread' => ['tweet_ids' => ['t1', 't2', 't3']]]);

        $first = PostTargetComment::where('post_target_id', $target->id)
            ->whereHas('comment', fn ($q) => $q->where('position', 0))->firstOrFail();
        (new PostTargetCommentJob($first->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $this->assertSame('t3', $bodies[0]['reply']['in_reply_to_tweet_id'] ?? null);
    }

    public function test_second_comment_chains_off_the_first(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'c2']]),
        ]);

        $target = $this->publishedTargetWithComments('x');
        $this->markPublished($target, 't-root');

        [$first, $second] = PostTargetComment::where('post_target_id', $target->id)
            ->join('post_comments', 'post_comments.id', '=', 'post_target_comments.post_comment_id')
            ->orderBy('post_comments.position')
            ->select('post_target_comments.*')
            ->get();
        $first->forceFill(['status' => PostTargetComment::STATUS_POSTED, 'platform_comment_id' => 'c1'])->save();

        (new PostTargetCommentJob($second->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        Http::assertSent(fn ($request) => ($request['reply']['in_reply_to_tweet_id'] ?? null) === 'c1');
        $this->assertSame(PostTargetComment::STATUS_POSTED, $second->fresh()->status);
    }

    public function test_linkedin_comment_posts_to_social_actions(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        Http::fake([
            'api.linkedin.com/rest/socialActions/*' => Http::response(['id' => 'comment-9']),
        ]);

        $target = $this->publishedTargetWithComments('linkedin', [], ['author_urn' => 'urn:li:person:me1']);
        $this->markPublished($target, 'urn:li:share:777');

        $first = PostTargetComment::where('post_target_id', $target->id)
            ->whereHas('comment', fn ($q) => $q->where('position', 0))->firstOrFail();
        (new PostTargetCommentJob($first->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $first->refresh();
        $this->assertSame(PostTargetComment::STATUS_POSTED, $first->status, (string) $first->error);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'socialActions')
                && str_contains($request->url(), urlencode('urn:li:share:777'))
                && ($request['message']['text'] ?? null) === 'first comment'
                && ($request['actor'] ?? null) === 'urn:li:person:me1';
        });
    }

    public function test_instagram_comment_posts_to_media_endpoint(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        Http::fake([
            'graph.instagram.com/*/ig-media-1/comments' => Http::response(['id' => 'ig-c-1']),
        ]);

        $target = $this->publishedTargetWithComments('instagram');
        $this->markPublished($target, 'ig-media-1');

        $first = PostTargetComment::where('post_target_id', $target->id)
            ->whereHas('comment', fn ($q) => $q->where('position', 0))->firstOrFail();
        (new PostTargetCommentJob($first->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $this->assertSame(PostTargetComment::STATUS_POSTED, $first->fresh()->status, (string) $first->fresh()->error);
    }

    public function test_unsupported_platform_marks_comments_skipped(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        $account = $this->connect('tiktok');
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'main',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $post->comments()->create(['position' => 0, 'body' => 'first', 'delay_seconds' => 0]);
        $target = $post->targets()->create([
            'platform' => 'tiktok',
            'status' => PostTarget::STATUS_PUBLISHING,
        ]);

        $this->markPublished($target, 'tt-1');

        $row = PostTargetComment::where('post_target_id', $target->id)->firstOrFail();
        $this->assertSame(PostTargetComment::STATUS_SKIPPED, $row->status);
        Queue::assertNothingPushed();
    }

    public function test_legacy_linkedin_first_comment_becomes_a_comment_row(): void
    {
        Queue::fake();
        $response = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'hello',
            'platforms' => ['linkedin'],
            'status' => 'draft',
            'options' => ['linkedin' => ['first_comment' => 'check out my newsletter']],
        ]);

        $response->assertStatus(201);
        $post = Post::findOrFail($response->json('id'));
        $this->assertSame('check out my newsletter', $post->comments()->first()?->body);
    }

    public function test_over_limit_x_comment_is_rejected_at_publish_time(): void
    {
        Queue::fake();
        $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'ok',
            'platforms' => ['x'],
            'status' => 'posted',
            'comments' => [['body' => str_repeat('a', 300), 'delay_seconds' => 0]],
        ])->assertStatus(422);
    }

    public function test_failed_comment_does_not_block_the_rest_of_the_chain(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response(['error' => 'nope'], 403),
        ]);

        $target = $this->publishedTargetWithComments('x');
        $this->markPublished($target, 't-root');

        $first = PostTargetComment::where('post_target_id', $target->id)
            ->whereHas('comment', fn ($q) => $q->where('position', 0))->firstOrFail();
        (new PostTargetCommentJob($first->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $first->refresh();
        $this->assertSame(PostTargetComment::STATUS_FAILED, $first->status);
        $this->assertNotEmpty($first->error);
        // Two pushes: the chain start on publish, plus the second comment
        // dispatched by the failed first one — the chain keeps going.
        Queue::assertPushed(PostTargetCommentJob::class, 2);
    }
}
