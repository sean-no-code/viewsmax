<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToThreadsJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\MetaGraphErrors;
use App\Services\Social\Providers\ThreadsProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * ThreadsProvider publish/comment/connect against a faked graph.threads.net:
 * container polling (every type), Threads' own error strings, the shared
 * Meta classifier (retry → queue, dead token → reconnect flag), carousels
 * and the long-lived token exchange.
 */
class ThreadsPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['social.platforms.threads.client_id' => 'id', 'social.platforms.threads.client_secret' => 'secret']);
    }

    private function account(): SocialAccount
    {
        return User::factory()->create()->socialAccounts()->create([
            'platform' => 'threads',
            'platform_account_id' => 'th-user-1',
            'name' => 'Tester',
            'username' => 'tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDays(30),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /** @param  array<string, mixed>  $error */
    private function graphError(array $error, int $status = 400): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => $error + ['type' => 'OAuthException']], $status);
    }

    private function publishedThread(string $id = 'thread-123'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['id' => $id], 200);
    }

    private static function isPoll(Request $request): bool
    {
        return $request->method() === 'GET' && str_contains($request->url(), 'fields=status');
    }

    private static function isPublish(Request $request): bool
    {
        return str_contains($request->url(), 'threads_publish');
    }

    /* ---------------- container polling ---------------- */

    /**
     * Regression: the old code blindly slept 5s then published, which raced the
     * async container and failed with "Media not found" (code 24 / 4279009).
     * We must poll the container status and only publish once it's FINISHED.
     */
    public function test_image_post_waits_for_container_before_publishing(): void
    {
        Http::fake([
            '*threads_publish*' => $this->publishedThread(),
            '*fields=status*' => Http::response(['status' => 'FINISHED'], 200),
            '*' => Http::response(['id' => 'container-1'], 200), // create container
        ]);

        $post = new SocialPost([
            'content' => 'hello threads',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/pic.jpg']],
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('thread-123', $result->remotePostId);

        // The container status (with Threads' error_message) was polled before publishing.
        Http::assertSent(fn (Request $req) => self::isPoll($req) && str_contains($req->url(), 'error_message'));
    }

    /**
     * TEXT containers process asynchronously too, so a text post must poll
     * before threads_publish instead
     * of racing the container.
     */
    public function test_text_post_polls_the_container_before_publishing(): void
    {
        $order = [];
        Http::fake(function (Request $request) use (&$order) {
            if (self::isPoll($request)) {
                $order[] = 'poll';

                return Http::response(['status' => 'FINISHED'], 200);
            }
            if (self::isPublish($request)) {
                $order[] = 'publish';

                return $this->publishedThread('thread-text');
            }
            $order[] = 'create:'.$request->data()['media_type'];

            return Http::response(['id' => 'container-text'], 200);
        });

        $result = (new ThreadsProvider)->publish($this->account(), new SocialPost(['content' => 'just text']));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(['create:TEXT', 'poll', 'publish'], $order);
    }

    public function test_in_progress_container_is_polled_until_finished(): void
    {
        $polls = 0;
        Http::fake([
            '*threads_publish*' => $this->publishedThread(),
            '*fields=status*' => function () use (&$polls) {
                return Http::response(['status' => ++$polls < 3 ? 'IN_PROGRESS' : 'FINISHED'], 200);
            },
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), new SocialPost(['content' => 'x']));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(3, $polls);
        Sleep::assertSleptTimes(2);
    }

    public function test_container_that_never_finishes_fails_with_timeout_message(): void
    {
        Http::fake([
            '*fields=status*' => Http::response(['status' => 'IN_PROGRESS'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), new SocialPost(['content' => 'x']));

        $this->assertFalse($result->success);
        $this->assertSame(ThreadsProvider::MESSAGE_MEDIA_TIMEOUT, $result->error);
        Http::assertNotSent(fn (Request $req) => self::isPublish($req));
    }

    /* ---------------- container ERROR → error_message ---------------- */

    public function test_errored_container_surfaces_threads_error_message_without_publishing(): void
    {
        Http::fake([
            '*threads_publish*' => $this->publishedThread('should-not-happen'),
            '*fields=status*' => Http::response(['status' => 'ERROR', 'error_message' => 'The video is too long. Max duration is 300 seconds.'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);

        $post = new SocialPost([
            'content' => 'bad media',
            'media' => [['type' => 'video', 'url' => 'https://cdn.example/broken.mp4']],
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), $post);

        $this->assertFalse($result->success);
        $this->assertSame('The video is too long. Max duration is 300 seconds.', $result->error);
        // Must not attempt to publish a container that failed processing.
        Http::assertNotSent(fn (Request $req) => self::isPublish($req));
    }

    public function test_unknown_container_error_message_falls_back_to_a_format_hint(): void
    {
        Http::fake([
            '*fields=status*' => Http::response(['status' => 'ERROR', 'error_message' => 'UNKNOWN'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);

        $post = new SocialPost([
            'content' => 'bad media',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/broken.gif']],
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), $post);

        $this->assertFalse($result->success);
        $this->assertSame(ThreadsProvider::MESSAGE_MEDIA_UNPROCESSABLE, $result->error);
    }

    public function test_expired_container_without_error_message_uses_the_format_hint(): void
    {
        Http::fake([
            '*fields=status*' => Http::response(['status' => 'EXPIRED'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), new SocialPost([
            'content' => 'x',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/pic.jpg']],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame(ThreadsProvider::MESSAGE_MEDIA_UNPROCESSABLE, $result->error);
    }

    /* ---------------- 4279009 → retry ---------------- */

    public function test_media_not_found_on_publish_is_handed_to_the_queue_as_transient(): void
    {
        Http::fake([
            '*threads_publish*' => $this->graphError(['message' => 'Media Not Found', 'code' => 24, 'error_subcode' => 4279009], 500),
            '*fields=status*' => Http::response(['status' => 'FINISHED'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);
        $account = $this->account();

        try {
            (new ThreadsProvider)->publish($account, new SocialPost(['content' => 'x']));
            $this->fail('expected TransientPublishException');
        } catch (TransientPublishException $e) {
            $this->assertSame(ThreadsProvider::ERRORS[4279009][1], $e->getMessage());
        }

        // A race on the container is not a token problem.
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_http_5xx_on_container_creation_is_transient(): void
    {
        Http::fake(['*' => Http::response('<html>Bad gateway</html>', 502)]);

        $this->expectException(TransientPublishException::class);

        (new ThreadsProvider)->publish($this->account(), new SocialPost(['content' => 'x']));
    }

    /* ---------------- token errors → reconnect flag ---------------- */

    public function test_expired_token_on_publish_flags_the_account_for_reconnect(): void
    {
        Http::fake([
            '*' => $this->graphError(['message' => 'Error validating access token: Session has expired', 'code' => 190, 'error_subcode' => 463], 401),
        ]);
        $account = $this->account();

        $result = (new ThreadsProvider)->publish($account, new SocialPost(['content' => 'x']));

        $this->assertFalse($result->success);
        $this->assertSame(MetaGraphErrors::MESSAGE_REAUTH, $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        $this->assertSame(MetaGraphErrors::MESSAGE_REAUTH, $account->fresh()->last_error);
    }

    public function test_expired_token_on_comment_flags_the_account_for_reconnect(): void
    {
        Http::fake([
            '*' => $this->graphError(['message' => 'Error validating access token: The session is invalid', 'code' => 190], 400),
        ]);
        $account = $this->account();

        $result = (new ThreadsProvider)->comment($account, 'thread-1', 'nice one');

        $this->assertFalse($result->success);
        $this->assertSame(MetaGraphErrors::MESSAGE_REAUTH, $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_other_graph_errors_do_not_flag_reconnect(): void
    {
        Http::fake([
            '*' => $this->graphError(['message' => 'Invalid parameter', 'code' => 100], 400),
        ]);
        $account = $this->account();

        $result = (new ThreadsProvider)->publish($account, new SocialPost(['content' => 'x']));

        $this->assertFalse($result->success);
        $this->assertSame('Meta rejected the post: Invalid parameter', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    /* ---------------- Threads error table ---------------- */

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function threadsErrors(): array
    {
        return [
            'community restriction' => [
                ['message' => 'We restrict certain activity to protect our community.', 'code' => 368, 'error_subcode' => 2207051],
                'Error from Meta: We restrict certain activity to protect our community',
            ],
            'user restricted' => [
                ['message' => 'User restricted', 'code' => 24, 'error_subcode' => 4279013],
                'User restricted',
            ],
            'media could not be fetched' => [
                ['message' => 'The media could not be fetched from this URI: https://cdn.example/x.jpg', 'code' => 24],
                "One of the media URLs is invalid or inaccessible, make sure it's uploaded to ViewsMax first",
            ],
            'text over 500' => [
                ['message' => 'Invalid parameter: text must be at most 500 characters', 'code' => 100],
                'Post text exceeds 500 characters limit',
            ],
        ];
    }

    /** @param  array<string, mixed>  $error */
    #[DataProvider('threadsErrors')]
    public function test_threads_error_is_mapped_to_an_actionable_message(array $error, string $expected): void
    {
        Http::fake(['*' => $this->graphError($error, 400)]);
        $account = $this->account();

        $result = (new ThreadsProvider)->publish($account, new SocialPost([
            'content' => 'x',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/x.jpg']],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame($expected, $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    /* ---------------- comments ---------------- */

    public function test_reply_polls_its_container_then_publishes(): void
    {
        $order = [];
        Http::fake(function (Request $request) use (&$order) {
            if (self::isPoll($request)) {
                $order[] = 'poll';

                return Http::response(['status' => 'FINISHED'], 200);
            }
            if (self::isPublish($request)) {
                $order[] = 'publish';

                return $this->publishedThread('reply-1');
            }
            $order[] = 'create:'.$request->data()['reply_to_id'];

            return Http::response(['id' => 'reply-container'], 200);
        });

        $result = (new ThreadsProvider)->comment($this->account(), 'thread-1', 'first reply', 'previous-reply');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('reply-1', $result->remoteCommentId);
        $this->assertSame(['create:previous-reply', 'poll', 'publish'], $order);
    }

    public function test_transient_error_on_a_reply_is_a_plain_failure(): void
    {
        // The comment job never retries (a reply isn't idempotent), so the
        // provider must not let the retry exception escape.
        Http::fake(['*' => Http::response(null, 503)]);

        $result = (new ThreadsProvider)->comment($this->account(), 'thread-1', 'nice one');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('503', (string) $result->error);
    }

    /* ---------------- carousels ---------------- */

    public function test_multiple_media_publish_as_a_carousel(): void
    {
        $creates = [];
        $polled = [];
        $containers = 0;
        Http::fake(function (Request $request) use (&$creates, &$polled, &$containers) {
            if (self::isPoll($request)) {
                $polled[] = basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response(['status' => 'FINISHED'], 200);
            }
            if (self::isPublish($request)) {
                return $this->publishedThread('thread-carousel');
            }
            $creates[] = $request->data();

            return Http::response(['id' => 'c'.(++$containers)], 200);
        });

        $result = (new ThreadsProvider)->publish($this->account(), new SocialPost([
            'content' => 'three things',
            'media' => [
                ['type' => 'image', 'url' => 'https://cdn.example/1.jpg'],
                ['type' => 'video', 'url' => 'https://cdn.example/2.mp4'],
                ['type' => 'image', 'url' => 'https://cdn.example/3.jpg'],
            ],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('thread-carousel', $result->remotePostId);

        $this->assertSame([
            ['media_type' => 'IMAGE', 'image_url' => 'https://cdn.example/1.jpg', 'is_carousel_item' => 'true'],
            ['media_type' => 'VIDEO', 'video_url' => 'https://cdn.example/2.mp4', 'is_carousel_item' => 'true'],
            ['media_type' => 'IMAGE', 'image_url' => 'https://cdn.example/3.jpg', 'is_carousel_item' => 'true'],
            ['media_type' => 'CAROUSEL', 'children' => 'c1,c2,c3', 'text' => 'three things'],
        ], $creates);
        // Every child was processed before the parent was created, then the parent itself.
        $this->assertSame(['c1', 'c2', 'c3', 'c4'], $polled);
        Http::assertSent(fn (Request $req) => self::isPublish($req) && $req->data()['creation_id'] === 'c4');
    }

    public function test_single_media_is_not_a_carousel(): void
    {
        Http::fake([
            '*threads_publish*' => $this->publishedThread(),
            '*fields=status*' => Http::response(['status' => 'FINISHED'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);

        (new ThreadsProvider)->publish($this->account(), new SocialPost([
            'content' => 'one pic',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/1.jpg']],
        ]));

        Http::assertSent(fn (Request $req) => $req->method() === 'POST' && ! self::isPublish($req)
            && $req->data() === ['media_type' => 'IMAGE', 'image_url' => 'https://cdn.example/1.jpg', 'text' => 'one pic']);
        Http::assertNotSent(fn (Request $req) => isset($req->data()['is_carousel_item']));
    }

    public function test_failed_carousel_child_stops_before_the_parent_is_created(): void
    {
        Http::fake([
            '*fields=status*' => Http::response(['status' => 'ERROR', 'error_message' => 'Unsupported image format'], 200),
            '*' => Http::response(['id' => 'child'], 200),
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), new SocialPost([
            'content' => 'x',
            'media' => [
                ['type' => 'image', 'url' => 'https://cdn.example/1.jpg'],
                ['type' => 'image', 'url' => 'https://cdn.example/2.jpg'],
            ],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('Unsupported image format', $result->error);
        Http::assertNotSent(fn (Request $req) => ($req->data()['media_type'] ?? null) === 'CAROUSEL');
        Http::assertNotSent(fn (Request $req) => self::isPublish($req));
    }

    public function test_more_than_ten_items_is_rejected_before_any_api_call(): void
    {
        Http::fake();

        $media = array_map(fn (int $i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.jpg"], range(1, 11));
        $result = (new ThreadsProvider)->publish($this->account(), new SocialPost(['content' => 'x', 'media' => $media]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('at most 10 items', (string) $result->error);
        Http::assertNothingSent();
    }

    /* ---------------- connect ---------------- */

    /**
     * Regression (the Instagram bug, same code): a failed long-lived exchange
     * silently stored the 1-hour short-lived token with a null expiry, so a
     * "successful" connect died within the hour at publish time.
     */
    public function test_failed_long_lived_exchange_fails_loudly_instead_of_storing_the_short_token(): void
    {
        Http::fake([
            'graph.threads.net/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 'th-1'], 200),
            'graph.threads.net/access_token*' => Http::response(['error' => ['message' => 'bad secret']], 400),
            'graph.threads.net/*' => Http::response(['id' => 'th-1', 'username' => 'someone'], 200),
        ]);

        try {
            (new ThreadsProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');
            $this->fail('expected the connect to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('long-lived token exchange', $e->getMessage());
        }

        // Nothing half-connected may be stored — the popup shows the real error.
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_successful_connect_stores_the_long_lived_token_with_its_expiry(): void
    {
        Http::fake([
            'graph.threads.net/oauth/access_token' => Http::response(['access_token' => 'short-lived', 'user_id' => 'th-1'], 200),
            'graph.threads.net/access_token*' => Http::response(['access_token' => 'long-lived', 'expires_in' => 5184000], 200),
            'graph.threads.net/*' => Http::response(['id' => 'th-1', 'username' => 'someone'], 200),
        ]);

        $accounts = (new ThreadsProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $account = $accounts->first();
        $this->assertSame('long-lived', $account->access_token);
        $this->assertNotNull($account->token_expires_at);
        $this->assertTrue($account->token_expires_at->gt(now()->addDays(59)));
    }

    /* ---------------- job ---------------- */

    private function makeTarget(SocialAccount $account): PostTarget
    {
        $post = Post::create([
            'user_id' => $account->user_id,
            'caption' => 'queued thread',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'threads',
            'status' => PostTarget::STATUS_PENDING,
        ]);
    }

    public function test_job_lets_a_transient_error_reach_the_queue_without_failing_the_target(): void
    {
        Http::fake([
            '*threads_publish*' => $this->graphError(['message' => 'Media Not Found', 'code' => 24, 'error_subcode' => 4279009], 500),
            '*fields=status*' => Http::response(['status' => 'FINISHED'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);
        $target = $this->makeTarget($this->account());

        try {
            (new PublishToThreadsJob($target->id))->handle(app(SocialProviderManager::class));
            $this->fail('expected TransientPublishException to propagate');
        } catch (TransientPublishException) {
        }

        // Still pending: the queue's next attempt may publish it.
        $this->assertSame(PostTarget::STATUS_PENDING, $target->fresh()->status);
        $this->assertNull($target->fresh()->error);
    }

    public function test_job_marks_the_target_failed_with_the_mapped_message(): void
    {
        Http::fake([
            '*' => $this->graphError(['message' => 'User restricted', 'code' => 24, 'error_subcode' => 4279013], 400),
        ]);
        $target = $this->makeTarget($this->account());

        (new PublishToThreadsJob($target->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertSame('User restricted', $target->fresh()->error);
    }

    public function test_job_publishes_a_text_post(): void
    {
        Http::fake([
            '*threads_publish*' => $this->publishedThread('thread-job'),
            '*fields=status*' => Http::response(['status' => 'FINISHED'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);
        $target = $this->makeTarget($this->account());

        (new PublishToThreadsJob($target->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status);
        $this->assertSame('thread-job', $target->fresh()->platform_post_id);
    }
}
