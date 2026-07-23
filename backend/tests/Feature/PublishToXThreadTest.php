<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishSocialPostJob;
use App\Jobs\PublishToXJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * X threads: a `---` line in the caption splits it into a reply chain. Tweet
 * ids are persisted per segment so a queue retry resumes mid-thread instead of
 * re-posting earlier tweets.
 */
class PublishToXThreadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function connectX(User $user): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'x',
            'platform_account_id' => 'x-1',
            'name' => 'Tester',
            'username' => 'tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'scopes' => ['tweet.write'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function makeTarget(User $user, string $caption, array $media = []): PostTarget
    {
        $post = Post::create([
            'user_id' => $user->id,
            'caption' => $caption,
            'media' => $media,
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'x',
            'status' => PostTarget::STATUS_PENDING,
        ]);
    }

    private function runJob(PostTarget $target): void
    {
        (new PublishToXJob($target->id))->handle(app(SocialProviderManager::class));
    }

    /** Fake /2/tweets returning t1, t2, t3… on successive calls. */
    private function fakeTweetSequence(array $extra = []): void
    {
        $calls = 0;
        Http::fake(array_merge([
            'api.twitter.com/2/tweets' => function () use (&$calls) {
                $calls++;

                return Http::response(['data' => ['id' => "t{$calls}"]]);
            },
        ], $extra));
    }

    /** The recorded /2/tweets request bodies, in order. */
    private function tweetRequests(): array
    {
        $bodies = [];
        Http::assertSent(function ($request) use (&$bodies) {
            if (str_contains($request->url(), '/2/tweets')) {
                $bodies[] = $request->data();
            }

            return true;
        });

        return $bodies;
    }

    public function test_publishes_three_segment_thread_as_reply_chain(): void
    {
        $this->fakeTweetSequence();
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, "one\n---\ntwo\n---\nthree");

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('t1', $target->platform_post_id);
        $this->assertSame(['t1', 't2', 't3'], data_get($target->meta, 'x_thread.tweet_ids'));
        $this->assertStringContainsString('/status/t1', data_get($target->meta, 'url'));

        $requests = $this->tweetRequests();
        $this->assertCount(3, $requests);
        $this->assertSame('one', $requests[0]['text']);
        $this->assertArrayNotHasKey('reply', $requests[0]);
        $this->assertSame('t1', $requests[1]['reply']['in_reply_to_tweet_id']);
        $this->assertSame('t2', $requests[2]['reply']['in_reply_to_tweet_id']);

        // A polite pause between chained tweets, but not after the last one.
        Sleep::assertSleptTimes(2);
    }

    public function test_images_attach_to_first_tweet_only(): void
    {
        $this->fakeTweetSequence([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => Http::response(['data' => ['id' => 'm1']]),
        ]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, "one\n---\ntwo", [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $requests = $this->tweetRequests();
        $this->assertSame(['m1'], $requests[0]['media']['media_ids'] ?? null);
        $this->assertArrayNotHasKey('media', $requests[1]);
    }

    public function test_resume_skips_already_published_segments(): void
    {
        $this->fakeTweetSequence([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => Http::response(['data' => ['id' => 'm9']]),
        ]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, "one\n---\ntwo\n---\nthree\n---\nfour", [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);
        // Tweets 1–2 already went out on a previous attempt.
        $target->forceFill(['meta' => ['x_thread' => ['tweet_ids' => ['t101', 't102']]]])->save();

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('t101', $target->platform_post_id);
        $this->assertSame(['t101', 't102', 't1', 't2'], data_get($target->meta, 'x_thread.tweet_ids'));

        $requests = $this->tweetRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('three', $requests[0]['text']);
        $this->assertSame('t102', $requests[0]['reply']['in_reply_to_tweet_id']);
        // Resume never re-uploads the first tweet's media.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'media/upload'));
    }

    public function test_transient_failure_mid_thread_throws_for_retry_and_persists_progress(): void
    {
        $calls = 0;
        Http::fake([
            'api.twitter.com/2/tweets' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? Http::response(['data' => ['id' => 't1']])
                    : Http::response(['error' => 'overloaded'], 500);
            },
        ]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, "one\n---\ntwo\n---\nthree");

        try {
            $this->runJob($target);
            $this->fail('Expected a TransientPublishException for the queue to retry.');
        } catch (TransientPublishException $e) {
            $this->assertStringContainsString('tweet 2 of 3', $e->getMessage());
        }

        $target->refresh();
        $this->assertNotSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame(['t1'], data_get($target->meta, 'x_thread.tweet_ids'));
    }

    public function test_permanent_failure_mid_thread_marks_failed_with_progress(): void
    {
        $calls = 0;
        Http::fake([
            'api.twitter.com/2/tweets' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? Http::response(['data' => ['id' => 't1']])
                    : Http::response(['error' => 'duplicate'], 403);
            },
        ]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, "one\n---\ntwo\n---\nthree");

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('tweet 2 of 3', (string) $target->error);
        $this->assertSame(['t1'], data_get($target->meta, 'x_thread.tweet_ids'));
        $this->assertTrue((bool) data_get($target->meta, 'x_thread.partial'));
    }

    public function test_single_segment_behaves_like_a_plain_tweet(): void
    {
        $this->fakeTweetSequence();
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'just one tweet');

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $requests = $this->tweetRequests();
        $this->assertCount(1, $requests);
        $this->assertArrayNotHasKey('reply', $requests[0]);
        Sleep::assertNeverSlept();
    }

    /**
     * The MCP/SocialPost path publishes threads too — the loop lives in
     * XProvider, with progress persisted to the target's response column.
     */
    public function test_social_post_path_publishes_threads(): void
    {
        $this->fakeTweetSequence();
        $user = User::factory()->create();
        $account = $this->connectX($user);

        $post = SocialPost::create([
            'user_id' => $user->id,
            'content' => "one\n---\ntwo",
            'media' => [],
            'status' => SocialPost::STATUS_QUEUED,
        ]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'platform' => 'x',
            'status' => SocialPostTarget::STATUS_PENDING,
        ]);

        (new PublishSocialPostJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(SocialPostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('t1', $target->remote_post_id);
        $this->assertCount(2, $this->tweetRequests());
        $this->assertSame(['t1', 't2'], data_get($target->response, 'tweet_ids'));
    }
}
