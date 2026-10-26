<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToXJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\XProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PublishToXJobTest extends TestCase
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
            'scopes' => ['tweet.write', 'media.write'],
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

    public function test_video_target_fails_with_clear_note_and_sends_nothing(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'Watch this', [
            ['type' => 'video', 'url' => 'https://cdn.example/clip.mp4'],
        ]);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('video', (string) $target->error);
        Http::assertNothingSent();
    }

    public function test_images_are_capped_at_four(): void
    {
        $uploads = 0;
        Http::fake([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => function () use (&$uploads) {
                $uploads++;

                return Http::response(['data' => ['id' => "m{$uploads}"]]);
            },
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 't1']]),
        ]);

        $user = User::factory()->create();
        $this->connectX($user);
        $media = array_map(
            fn ($i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.jpg"],
            range(1, 6)
        );
        $target = $this->makeTarget($user, 'Six pics', $media);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame(4, $uploads);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/2/tweets')
                && count($request['media']['media_ids'] ?? []) === 4;
        });
    }

    public function test_image_post_publishes_with_media_ids(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => Http::response(['data' => ['id' => 'm1']]),
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 't1']]),
        ]);

        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'One pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('t1', $target->platform_post_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets')
            && ($request['media']['media_ids'] ?? null) === ['m1']);
    }

    /**
     * If the user attached images but X refuses the upload (e.g. the app lacks
     * the media.write scope), fail loudly instead of silently posting text-only.
     */
    public function test_failed_image_upload_fails_the_target(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'One pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('image', (string) $target->error);
        $this->assertStringContainsStringIgnoringCase('reconnect', (string) $target->error);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
    }

    /**
     * A token minted before media.write was requested is a guaranteed 403, so
     * the publisher says "reconnect" up front instead of relaying X's bare
     * `Forbidden` — and makes no request at all.
     */
    public function test_image_upload_without_media_write_scope_asks_to_reconnect(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $this->connectX($user)->update(['scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access']]);
        $target = $this->makeTarget($user, 'One pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('reconnect', (string) $target->error);
        Http::assertNothingSent();
    }

    /** Text-only posts never needed media.write, so the scope guard must not touch them. */
    public function test_text_post_ignores_missing_media_write_scope(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 't1']])]);

        $user = User::factory()->create();
        $this->connectX($user)->update(['scopes' => ['tweet.write']]);
        $target = $this->makeTarget($user, 'Just words');

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
    }

    /** The connect flow must request what the publisher needs, or every image post 403s. */
    public function test_connect_flow_requests_media_write_by_default(): void
    {
        $this->assertContains('media.write', config('social.platforms.x.scopes'));
    }

    /* ---------- X error bodies become readable messages ---------- */

    /**
     * X's bodies are mapped to short readable messages; the raw
     * JSON never reaches the user.
     */
    public function test_known_x_rejections_are_mapped_to_readable_messages(): void
    {
        $user = User::factory()->create();
        $this->connectX($user);

        $cases = [
            '{"detail":"You are not allowed to create a Tweet with duplicate content.","title":"Forbidden","status":403}'
                => 'You are not allowed to create a post with duplicate content',
            '{"errors":[{"message":"usage-capped"}],"title":"UsageCapExceeded","status":429}'
                => 'Posting failed - capped reached. Please try again later',
            '{"detail":"user-suspended","title":"Forbidden","status":403}'
                => 'Your X account has been suspended, please reconnect with another account',
            '{"detail":"Your account is temporarily locked.","title":"Forbidden","status":403}'
                => 'Your X account is temporarily locked, log in to x.com to unlock it and then try again',
            '{"detail":"The Tweet contains an invalid URL.","title":"Forbidden","status":403}'
                => 'The Tweet contains a URL that is not allowed on X',
            '{"detail":"Your media IDs are invalid.","title":"Invalid Request","status":400}'
                => 'X rejected the attached media, please re-upload the media and try again',
            '{"detail":"There can be a maximum of one cashtag per Tweet.","title":"Forbidden","status":403}'
                => 'There can be maximum of one cashtag ($SYMBOL) per post',
            '{"detail":"Please include either text or media in your Tweet.","title":"Invalid Request","status":400}'
                => 'One of the posts in this thread has no text or media, please add some text or remove it',
            '{"detail":"Crypto addresses are prohibited","title":"Forbidden","status":403}'
                => 'X does not allow crypto addresses in posts for the first 7 days after connecting the account',
            '{"detail":"You are not permitted to perform this action.","title":"Forbidden","status":403}'
                => 'There is a problem posting, please edit your post and check character count and media attachments',
        ];

        // Http::fake() stubs accumulate, so one stub reads the current body.
        $body = '';
        Http::fake(['api.twitter.com/2/tweets' => function () use (&$body) {
            return Http::response($body, 403);
        }]);

        foreach ($cases as $body => $expected) {
            $target = $this->makeTarget($user, 'Hello');

            $this->runJob($target);

            $target->refresh();
            $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
            $this->assertSame($expected, $target->error, $body);
        }
    }

    /** Unlisted rejections quote X's own detail instead of dumping the JSON body. */
    public function test_unmapped_rejection_quotes_the_detail_not_the_json(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response(
            '{"detail":"Your Tweet text is too long.","title":"Forbidden","type":"about:blank","status":403}', 403
        )]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, str_repeat('x', 300));

        $this->runJob($target);

        $this->assertSame('X rejected the post: Your Tweet text is too long.', $target->refresh()->error);
    }

    public function test_comment_rejections_use_the_same_table(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response(
            '{"detail":"You are not allowed to create a Tweet with duplicate content.","title":"Forbidden","status":403}', 403
        )]);
        $account = $this->connectX(User::factory()->create());

        $result = app(SocialProviderManager::class)->for('x')->comment($account, 't1', 'again');

        $this->assertFalse($result->success);
        $this->assertSame('You are not allowed to create a post with duplicate content', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    /* ---------- a dead token flags the account for reconnect ---------- */

    public function test_401_from_tweets_marks_the_account_needs_reauth(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response('{"title":"Unauthorized","status":401}', 401)]);
        $user = User::factory()->create();
        $account = $this->connectX($user);
        $target = $this->makeTarget($user, 'Hello');

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('X authorization expired or was revoked — reconnect the account.', $target->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        $this->assertSame($target->error, $account->fresh()->last_error);
    }

    /** X sometimes answers a revoked token with a 403 whose body still says Unauthorized. */
    public function test_unauthorized_bodies_on_other_statuses_also_mark_needs_reauth(): void
    {
        $body = '';
        Http::fake(['api.twitter.com/2/tweets' => function () use (&$body) {
            return Http::response($body, 403);
        }]);

        foreach (['{"title":"Unauthorized","detail":"Unauthorized","status":401}', '{"errors":[{"message":"Unsupported Authentication"}]}'] as $body) {
            $user = User::factory()->create();
            $account = $this->connectX($user);
            $target = $this->makeTarget($user, 'Hello');

            $this->runJob($target);

            $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status, $body);
            $this->assertStringContainsString('reconnect', (string) $target->refresh()->error);
        }
    }

    public function test_401_from_media_upload_marks_the_account_needs_reauth_and_posts_nothing(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => Http::response('{"title":"Unauthorized","status":401}', 401),
        ]);
        $user = User::factory()->create();
        $account = $this->connectX($user);
        $target = $this->makeTarget($user, 'One pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        $this->assertSame('X authorization expired or was revoked — reconnect the account.', $target->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
    }

    public function test_401_on_comment_marks_the_account_needs_reauth(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response('{"title":"Unauthorized","status":401}', 401)]);
        $account = $this->connectX(User::factory()->create());

        $result = app(SocialProviderManager::class)->for('x')->comment($account, 't1', 'reply');

        $this->assertFalse($result->success);
        $this->assertSame('X authorization expired or was revoked — reconnect the account.', $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    /* ---------- exhausted rate-limit retries read as a friendly message ---------- */

    /**
     * A 429 asks the queue to retry; once $tries are spent the queue hands the
     * same exception to failed(), which must leave the friendly wording on the
     * target rather than X's "Too Many Requests" body.
     */
    public function test_exhausted_rate_limit_retries_store_the_friendly_message(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response(
            '{"title":"Too Many Requests","detail":"Too Many Requests","type":"about:blank","status":429}', 429
        )]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'Hello');

        try {
            $this->runJob($target);
            $this->fail('Expected a TransientPublishException for the queue to retry.');
        } catch (TransientPublishException $e) {
            $this->assertSame(XProvider::RATE_LIMIT_MESSAGE, $e->getMessage());
            $this->assertSame(429, $e->getCode());
        }
        $this->assertSame(PostTarget::STATUS_PENDING, $target->refresh()->status);

        (new PublishToXJob($target->id))->failed($e);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('X rate limit reached, please try again later', $target->error);
    }

    /** failed() maps the status itself, so a raw 429 body from any source still reads well. */
    public function test_failed_maps_a_raw_429_exception_to_the_friendly_message(): void
    {
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'Hello');

        (new PublishToXJob($target->id))->failed(new TransientPublishException(
            '{"title":"Too Many Requests","detail":"Too Many Requests","status":429}', 429
        ));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('X rate limit reached, please try again later', $target->error);
    }

    public function test_server_errors_read_as_unavailable_once_retries_are_spent(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response('<html>502 Bad Gateway</html>', 502)]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'Hello');

        try {
            $this->runJob($target);
            $this->fail('Expected a TransientPublishException for the queue to retry.');
        } catch (TransientPublishException $e) {
            (new PublishToXJob($target->id))->failed($e);
        }

        $this->assertSame('X is currently unavailable, please try again later', $target->refresh()->error);
    }

    /* ---------- image uploads retry rate limits in place ---------- */

    public function test_image_upload_retries_rate_limits_and_server_errors_then_succeeds(): void
    {
        $uploads = 0;
        Http::fake([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => function () use (&$uploads) {
                $uploads++;

                return match ($uploads) {
                    1 => Http::response('{"title":"Too Many Requests","status":429}', 429),
                    2 => Http::response('{"title":"Service Unavailable","status":503}', 503),
                    default => Http::response(['data' => ['id' => 'm1']]),
                };
            },
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 't1']]),
        ]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'One pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status, (string) $target->error);
        $this->assertSame(3, $uploads);
        // 5 s × attempt between attempts; the image is fetched once.
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(10)->seconds()]);
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets')
            && ($request['media']['media_ids'] ?? null) === ['m1']);
    }

    /** A 429 that outlives the three in-place attempts hands the whole post to the queue: nothing was tweeted. */
    public function test_persistent_upload_rate_limit_throws_transient_for_the_queue(): void
    {
        $uploads = 0;
        Http::fake([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => function () use (&$uploads) {
                $uploads++;

                return Http::response('{"title":"Too Many Requests","status":429}', 429);
            },
        ]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'One pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        try {
            $this->runJob($target);
            $this->fail('Expected a TransientPublishException for the queue to retry.');
        } catch (TransientPublishException $e) {
            $this->assertSame(XProvider::RATE_LIMIT_MESSAGE, $e->getMessage());
        }

        $this->assertSame(3, $uploads);
        $this->assertSame(PostTarget::STATUS_PENDING, $target->refresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/tweets'));

        // …and once the queue gives up, the user reads why.
        (new PublishToXJob($target->id))->failed($e);
        $this->assertSame('X rate limit reached, please try again later', $target->refresh()->error);
    }

    /** Permanent upload refusals are not retried. */
    public function test_image_upload_does_not_retry_permanent_refusals(): void
    {
        $uploads = 0;
        Http::fake([
            'cdn.example/*' => Http::response('binary'),
            'api.twitter.com/2/media/upload*' => function () use (&$uploads) {
                $uploads++;

                return Http::response('{"detail":"Your media IDs are invalid.","title":"Invalid Request","status":400}', 400);
            },
        ]);
        $user = User::factory()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'One pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ]);

        $this->runJob($target);

        $this->assertSame(1, $uploads);
        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        $this->assertSame('X rejected the attached media, please re-upload the media and try again', $target->error);
        Sleep::assertNeverSlept();
    }

    public function test_a_lapsed_owners_queued_target_goes_back_to_draft_before_any_api_call(): void
    {
        Http::fake();
        $user = User::factory()->expired()->create();
        $this->connectX($user);
        $target = $this->makeTarget($user, 'Scheduled while I was subscribed');

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PENDING, $target->status);
        $this->assertNull($target->error);
        $this->assertSame(Post::STATUS_DRAFT, $target->post->status);
        $this->assertNull($target->post->scheduled_at);
        Http::assertNothingSent();
    }
}
