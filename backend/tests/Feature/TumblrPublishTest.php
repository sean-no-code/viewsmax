<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToTumblrJob;
use App\Jobs\SendReconnectEmailJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\CaptionRules;
use App\Services\Social\MediaRules;
use App\Services\Social\PostPublishDispatcher;
use App\Services\Social\Providers\TumblrProvider;
use App\Services\Social\SocialConnectException;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tumblr publishing: one OAuth grant connects every blog the user can post
 * to (a row per blog, one shared token pair); posts are NPF content blocks,
 * with media uploaded as multipart parts referenced by identifier.
 */
class TumblrPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** A real 1x1 PNG, so type and size are read from the bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const POSTS_URL = 'https://api.tumblr.com/v2/blog/t%3Ablog-one/posts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'social.platforms.tumblr.client_id' => 'tumblr-key',
            'social.platforms.tumblr.client_secret' => 'tumblr-secret',
        ]);
    }

    private function account(array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => 'tumblr',
            'platform_account_id' => 't:blog-one',
            'name' => 'Blog One',
            'username' => 'blog-one',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addMinutes(30),
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['grant_id' => 'grant-1'],
        ], $overrides));
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function created(string $id = '1234567891234567'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['meta' => ['status' => 201, 'msg' => 'Created'], 'response' => ['id' => $id]], 201);
    }

    private function error(int $status, ?int $code = null, string $detail = ''): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(array_filter([
            'meta' => ['status' => $status, 'msg' => 'Error'],
            'response' => [],
            'errors' => $code !== null ? [['title' => 'Error', 'code' => $code, 'detail' => $detail]] : null,
        ]), $status);
    }

    /** The multipart parts of a recorded request, keyed by part name. */
    private function parts(Request $req): array
    {
        return collect($req->data())->keyBy('name')->all();
    }

    private function postFor(string $content, array $media = [], array $tags = []): SocialPost
    {
        $post = new SocialPost(['content' => $content, 'media' => $media]);
        $post->tags = $tags;

        return $post;
    }

    // --- connect -----------------------------------------------------------

    public function test_authorization_url_asks_for_write_and_offline_access(): void
    {
        $url = (new TumblrProvider)->getAuthorizationUrl('https://app.example/social/callback', 'state-1');

        $this->assertStringStartsWith('https://www.tumblr.com/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('tumblr-key', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('basic write offline_access', $query['scope']);
        $this->assertSame('state-1', $query['state']);
        $this->assertSame('https://app.example/social/callback', $query['redirect_uri']);
    }

    public function test_connect_creates_one_account_per_blog_sharing_the_token_pair(): void
    {
        Http::fake([
            'api.tumblr.com/v2/oauth2/token' => Http::response([
                'access_token' => 'acc-1', 'refresh_token' => 'ref-1', 'expires_in' => 2520,
                'id_token' => false, 'scope' => 'write offline_access', 'token_type' => 'bearer',
            ]),
            'api.tumblr.com/v2/user/info' => Http::response(['meta' => ['status' => 200, 'msg' => 'OK'], 'response' => ['user' => [
                'name' => 'artist',
                'blogs' => [
                    ['name' => 'artist', 'title' => 'Artist Works', 'uuid' => 't:aaa', 'url' => 'https://artist.tumblr.com/', 'primary' => true, 'type' => 'public'],
                    ['name' => 'sketches', 'title' => '', 'uuid' => 't:bbb', 'url' => 'https://sketches.tumblr.com/', 'primary' => false, 'type' => 'public'],
                ],
            ]]]),
        ]);

        $accounts = (new TumblrProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/social/callback');

        $this->assertCount(2, $accounts);
        [$main, $side] = [$accounts[0]->fresh(), $accounts[1]->fresh()];
        $this->assertSame('t:aaa', $main->platform_account_id);
        $this->assertSame('Artist Works', $main->name);
        $this->assertSame('artist', $main->username);
        $this->assertSame('https://artist.tumblr.com/', $main->profile_url);
        // An untitled blog is shown by its name.
        $this->assertSame('sketches', $side->name);
        foreach ([$main, $side] as $account) {
            $this->assertSame('acc-1', $account->access_token);
            $this->assertSame('ref-1', $account->refresh_token);
            $this->assertTrue($account->token_expires_at->between(now()->addSeconds(2500), now()->addSeconds(2530)));
        }
        $this->assertNotEmpty($main->meta('grant_id'));
        $this->assertSame($main->meta('grant_id'), $side->meta('grant_id'));

        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.tumblr.com/v2/oauth2/token'
            && $req['grant_type'] === 'authorization_code' && $req['code'] === 'code-1'
            && $req['client_id'] === 'tumblr-key' && $req['client_secret'] === 'tumblr-secret');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.tumblr.com/v2/user/info'
            && $req->hasHeader('Authorization', 'Bearer acc-1'));
    }

    public function test_connect_without_offline_access_is_refused(): void
    {
        Http::fake([
            'api.tumblr.com/v2/oauth2/token' => Http::response(['access_token' => 'a', 'expires_in' => 2520, 'scope' => 'write']),
        ]);

        $this->expectException(SocialConnectException::class);
        $this->expectExceptionMessage('offline_access');

        (new TumblrProvider)->connectFromCode($this->user, 'code', 'https://app.example/social/callback');
    }

    public function test_connect_with_no_blog_to_post_to_is_refused(): void
    {
        Http::fake([
            'api.tumblr.com/v2/oauth2/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 2520, 'scope' => 'write offline_access']),
            'api.tumblr.com/v2/user/info' => Http::response(['response' => ['user' => ['name' => 'x', 'blogs' => []]]]),
        ]);

        $this->expectException(SocialConnectException::class);
        (new TumblrProvider)->connectFromCode($this->user, 'code', 'https://app.example/social/callback');
    }

    // --- tokens ------------------------------------------------------------

    public function test_refresh_writes_the_new_token_pair_to_every_blog_of_the_grant(): void
    {
        Http::fake([
            'api.tumblr.com/v2/oauth2/token' => Http::response(['access_token' => 'new-acc', 'refresh_token' => 'new-ref', 'expires_in' => 2520, 'scope' => 'write offline_access']),
        ]);

        $one = $this->account(['token_expires_at' => now()->subMinute()]);
        $two = $this->account(['platform_account_id' => 't:blog-two', 'username' => 'blog-two', 'token_expires_at' => now()->subMinute()]);
        // Another grant of the same user (a second Tumblr login) is not touched.
        $other = $this->account(['platform_account_id' => 't:other', 'refresh_token' => 'other-ref', 'metadata' => ['grant_id' => 'grant-2']]);

        $fresh = (new TumblrProvider)->ensureFreshToken($one);

        $this->assertSame('new-acc', $fresh->access_token);
        foreach ([$one, $two] as $row) {
            $row->refresh();
            $this->assertSame('new-acc', $row->access_token);
            $this->assertSame('new-ref', $row->refresh_token);
            $this->assertTrue($row->token_expires_at->isFuture());
        }
        $this->assertSame('other-ref', $other->fresh()->refresh_token);

        Http::assertSent(fn (Request $req) => $req['grant_type'] === 'refresh_token' && $req['refresh_token'] === 'refresh'
            && $req['client_id'] === 'tumblr-key' && $req['client_secret'] === 'tumblr-secret');
    }

    public function test_a_sibling_refreshed_by_another_job_is_not_refreshed_again(): void
    {
        Http::fake([
            'api.tumblr.com/v2/oauth2/token' => Http::response(['access_token' => 'new-acc', 'refresh_token' => 'new-ref', 'expires_in' => 2520]),
        ]);

        $one = $this->account(['token_expires_at' => now()->subMinute()]);
        $two = $this->account(['platform_account_id' => 't:blog-two', 'token_expires_at' => now()->subMinute()]);
        // The job for blog two loaded its row before blog one's job refreshed.
        $staleTwo = SocialAccount::find($two->id);

        (new TumblrProvider)->ensureFreshToken($one);
        $result = (new TumblrProvider)->ensureFreshToken($staleTwo);

        $this->assertSame('new-acc', $result->access_token);
        Http::assertSentCount(1);
    }

    public function test_refresh_waits_for_the_grant_lock_held_by_a_sibling(): void
    {
        Http::fake();
        $one = $this->account(['token_expires_at' => now()->subMinute()]);
        $two = $this->account(['platform_account_id' => 't:blog-two', 'token_expires_at' => now()->subMinute()]);

        // Blog one's job holds the grant lock (mid-refresh); blog two's job
        // must not send its own refresh with the same refresh token.
        $lock = Cache::lock('social:token-refresh:tumblr-grant:grant-1', 25);
        $this->assertTrue($lock->get());

        try {
            $provider = new class extends TumblrProvider
            {
                protected function refreshingSafely(SocialAccount $account, callable $refresh, ?callable $isFresh = null, ?string $lockKey = null): SocialAccount
                {
                    // Same lock, no 15 s wait in a test.
                    $lock = Cache::lock((string) $lockKey, 25);

                    return $lock->get() ? tap($refresh($account), fn () => $lock->release()) : ($account->fresh() ?? $account);
                }
            };
            $provider->ensureFreshToken($two);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_token_close_to_expiry_is_refreshed_early_and_a_fresh_one_is_not(): void
    {
        Http::fake([
            'api.tumblr.com/v2/oauth2/token' => Http::response(['access_token' => 'new-acc', 'refresh_token' => 'new-ref', 'expires_in' => 2520]),
        ]);

        $fresh = $this->account(['token_expires_at' => now()->addMinutes(30)]);
        (new TumblrProvider)->ensureFreshToken($fresh);
        Http::assertNothingSent();

        $closing = $this->account(['platform_account_id' => 't:closing', 'metadata' => ['grant_id' => 'grant-9'], 'token_expires_at' => now()->addMinutes(2)]);
        $this->assertSame('new-acc', (new TumblrProvider)->ensureFreshToken($closing)->access_token);
    }

    public function test_rejected_refresh_flags_every_blog_of_the_grant_with_one_email(): void
    {
        Queue::fake();
        Http::fake(['api.tumblr.com/v2/oauth2/token' => $this->error(401)]);

        $one = $this->account(['token_expires_at' => now()->subMinute()]);
        $two = $this->account(['platform_account_id' => 't:blog-two', 'token_expires_at' => now()->subMinute()]);

        $result = (new TumblrProvider)->ensureFreshToken($one);

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $result->status);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $two->fresh()->status);
        Queue::assertPushed(SendReconnectEmailJob::class, 1);
    }

    public function test_transient_refresh_failure_keeps_the_grant_connected(): void
    {
        Http::fake(['api.tumblr.com/v2/oauth2/token' => Http::response('', 503)]);

        $one = $this->account(['token_expires_at' => now()->subMinute()]);

        $result = (new TumblrProvider)->ensureFreshToken($one);

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $result->status);
        $this->assertSame('refresh', $result->refresh_token);
    }

    public function test_publishing_with_a_token_that_could_not_be_renewed_is_retried_not_flagged(): void
    {
        Http::fake();
        $account = $this->account(['token_expires_at' => now()->subMinute()]);

        try {
            (new TumblrProvider)->publish($account, $this->postFor('hello'));
            $this->fail('Expected a retry.');
        } catch (TransientPublishException) {
        }

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
        Http::assertNothingSent();
    }

    // --- publish -----------------------------------------------------------

    public function test_text_post_sends_one_text_block_per_paragraph_as_json(): void
    {
        Http::fake([self::POSTS_URL => $this->created('42')]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor("First paragraph\nstill first.\n\n\nSecond paragraph."));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('42', $result->remotePostId);
        $this->assertSame('https://www.tumblr.com/blog-one/42', $result->remotePostUrl);
        Http::assertSent(fn (Request $req) => $req->url() === self::POSTS_URL
            && $req->method() === 'POST'
            && $req->isJson()
            && $req->hasHeader('Authorization', 'Bearer token')
            && $req['content'] === [
                ['type' => 'text', 'text' => "First paragraph\nstill first."],
                ['type' => 'text', 'text' => 'Second paragraph.'],
            ]
            && ! isset($req['tags']));
    }

    public function test_tags_are_sent_as_tumblrs_comma_separated_string_only_when_given(): void
    {
        Http::fake([self::POSTS_URL => $this->created()]);

        (new TumblrProvider)->publish($this->account(), $this->postFor('Tagged', [], ['#art', ' process ', 'a,b', '', 'Art']));

        Http::assertSent(fn (Request $req) => $req['tags'] === 'art,process,a b');
    }

    public function test_images_are_uploaded_as_multipart_parts_before_the_caption(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            self::POSTS_URL => $this->created('77'),
        ]);

        $post = $this->postFor('Two pictures', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.png', 'alt' => 'A red square'],
            ['type' => 'image', 'url' => 'https://cdn.example/b.png'],
        ], ['art']);

        $result = (new TumblrProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(function (Request $req) {
            if ($req->url() !== self::POSTS_URL || ! $req->isMultipart()) {
                return false;
            }
            $parts = $this->parts($req);
            $json = json_decode($parts['json']['contents'], true);

            return $json['content'] === [
                ['type' => 'image', 'media' => [['type' => 'image/png', 'identifier' => 'media-0', 'width' => 1, 'height' => 1]], 'alt_text' => 'A red square'],
                ['type' => 'image', 'media' => [['type' => 'image/png', 'identifier' => 'media-1', 'width' => 1, 'height' => 1]]],
                ['type' => 'text', 'text' => 'Two pictures'],
            ]
                && $json['tags'] === 'art'
                && $parts['media-0']['contents'] === base64_decode(self::PNG)
                && $parts['media-1']['filename'] === 'media-1.png';
        });
    }

    public function test_video_is_uploaded_as_one_media_object_and_counts_as_published_while_transcoding(): void
    {
        Http::fake([
            'cdn.example/clip.mp4' => Http::response('VIDEO-BYTES', 200, ['Content-Type' => 'video/mp4']),
            self::POSTS_URL => $this->created('99'),
        ]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor('', [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('99', $result->remotePostId);
        Http::assertSent(function (Request $req) {
            if ($req->url() !== self::POSTS_URL || ! $req->isMultipart()) {
                return false;
            }
            $parts = $this->parts($req);

            return json_decode($parts['json']['contents'], true)['content'] === [
                ['type' => 'video', 'media' => ['type' => 'video/mp4', 'identifier' => 'media-0']],
            ] && $parts['media-0']['contents'] === 'VIDEO-BYTES';
        });
    }

    public function test_an_empty_post_fails_without_calling_tumblr(): void
    {
        Http::fake();

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor("  \n\n "));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_media_links_to_internal_addresses_are_refused(): void
    {
        Http::fake(['169.254.169.254/*' => Http::response('SECRET')]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor('x', [['type' => 'image', 'url' => 'http://169.254.169.254/latest/meta-data']]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('could not be downloaded', (string) $result->error);
        Http::assertNothingSent();
    }

    // --- errors ------------------------------------------------------------

    public function test_401_flags_the_grant_for_reconnect(): void
    {
        Queue::fake();
        Http::fake([self::POSTS_URL => $this->error(401)]);
        $account = $this->account();
        $sibling = $this->account(['platform_account_id' => 't:blog-two']);

        $result = (new TumblrProvider)->publish($account, $this->postFor('hi'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('reconnect Tumblr', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $sibling->fresh()->status);
    }

    public function test_403_says_the_blog_cannot_be_posted_to_without_flagging_the_account(): void
    {
        Http::fake([self::POSTS_URL => $this->error(403, null)]);
        $account = $this->account();

        $result = (new TumblrProvider)->publish($account, $this->postFor('hi'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString("won't let this account post to blog-one", (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_daily_limits_get_their_own_message(): void
    {
        Http::fake([self::POSTS_URL => $this->error(403, 8023, 'You have reached your daily post limit')]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor('hi'));

        $this->assertStringContainsString('as many posts as it can today', (string) $result->error);
    }

    public function test_daily_video_limit_gets_its_own_message(): void
    {
        Http::fake([
            'cdn.example/clip.mp4' => Http::response('VIDEO', 200, ['Content-Type' => 'video/mp4']),
            self::POSTS_URL => $this->error(403, 8011, 'video limit'),
        ]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor('', [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']]));

        $this->assertStringContainsString('as many videos as it can today', (string) $result->error);
    }

    public function test_400_passes_tumblrs_reason_through(): void
    {
        Http::fake([self::POSTS_URL => $this->error(400, 8001, 'Invalid NPF content block')]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor('hi'));

        $this->assertSame('Tumblr refused the post: Invalid NPF content block', $result->error);
    }

    public function test_unusable_media_format_is_named(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            self::POSTS_URL => $this->error(400, 8005, 'Unacceptable media type'),
        ]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor('', [['type' => 'image', 'url' => 'https://cdn.example/a.png']]));

        $this->assertStringContainsString("can't use this media format: Unacceptable media type", (string) $result->error);
    }

    public function test_404_says_the_blog_is_gone(): void
    {
        Http::fake([self::POSTS_URL => $this->error(404)]);

        $result = (new TumblrProvider)->publish($this->account(), $this->postFor('hi'));

        $this->assertStringContainsString("couldn't find the blog blog-one", (string) $result->error);
    }

    public function test_rate_limit_is_retried_by_the_queue(): void
    {
        Http::fake([self::POSTS_URL => Http::response(['meta' => ['status' => 429, 'msg' => 'Limit Exceeded']], 429, ['X-Ratelimit-Perhour-Remaining' => '0'])]);

        $this->expectException(TransientPublishException::class);
        $this->expectExceptionCode(429);

        (new TumblrProvider)->publish($this->account(), $this->postFor('hi'));
    }

    public function test_a_video_still_transcoding_is_retried_by_the_queue(): void
    {
        Http::fake([
            'cdn.example/clip.mp4' => Http::response('VIDEO', 200, ['Content-Type' => 'video/mp4']),
            self::POSTS_URL => $this->error(403, 8010, 'video still transcoding'),
        ]);

        $this->expectException(TransientPublishException::class);
        $this->expectExceptionMessage('still processing an earlier video');

        (new TumblrProvider)->publish($this->account(), $this->postFor('', [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']]));
    }

    public function test_upstream_errors_are_retried_by_the_queue(): void
    {
        Http::fake([self::POSTS_URL => $this->error(500, 8006)]);

        $this->expectException(TransientPublishException::class);

        (new TumblrProvider)->publish($this->account(), $this->postFor('hi'));
    }

    // --- job, dispatcher, rules --------------------------------------------

    private function target(SocialAccount $account, array $options = [], array $media = []): PostTarget
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'From the composer',
            'media' => $media,
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'tumblr',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHING,
            'options' => $options,
        ]);
    }

    public function test_job_publishes_the_target_with_its_tags(): void
    {
        Http::fake([self::POSTS_URL => $this->created('555')]);
        $target = $this->target($this->account(), ['tags' => ['news']]);

        (new PublishToTumblrJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status);
        $this->assertSame('555', $target->platform_post_id);
        $this->assertSame('https://www.tumblr.com/blog-one/555', $target->meta['url']);
        Http::assertSent(fn (Request $req) => $req['tags'] === 'news'
            && $req['content'] === [['type' => 'text', 'text' => 'From the composer']]);
    }

    public function test_job_refreshes_an_expired_token_first(): void
    {
        Http::fake([
            'api.tumblr.com/v2/oauth2/token' => Http::response(['access_token' => 'new-acc', 'refresh_token' => 'new-ref', 'expires_in' => 2520]),
            self::POSTS_URL => $this->created('556'),
        ]);
        $target = $this->target($this->account(['token_expires_at' => now()->subMinute()]));

        (new PublishToTumblrJob($target->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status);
        Http::assertSent(fn (Request $req) => $req->url() === self::POSTS_URL && $req->hasHeader('Authorization', 'Bearer new-acc'));
    }

    public function test_job_marks_the_target_failed_with_tumblrs_reason(): void
    {
        Http::fake([self::POSTS_URL => $this->error(400, 8001, 'Bad block')]);
        $target = $this->target($this->account());

        (new PublishToTumblrJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('Tumblr refused the post: Bad block', $target->error);
    }

    public function test_job_lets_a_rate_limit_through_to_the_queue_and_names_it_when_retries_run_out(): void
    {
        Http::fake([self::POSTS_URL => Http::response([], 429)]);
        $target = $this->target($this->account());
        $job = new PublishToTumblrJob($target->id);

        try {
            $job->handle(app(SocialProviderManager::class));
            $this->fail('Expected the rate limit to reach the queue.');
        } catch (TransientPublishException $e) {
            $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->fresh()->status);
            $job->failed($e);
        }

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertSame(TumblrProvider::RATE_LIMIT_MESSAGE, $target->fresh()->error);
    }

    public function test_job_fails_a_target_whose_grant_needs_reconnect(): void
    {
        Queue::fake();
        Http::fake(['api.tumblr.com/v2/oauth2/token' => $this->error(401)]);
        $target = $this->target($this->account(['token_expires_at' => now()->subMinute()]));

        (new PublishToTumblrJob($target->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringContainsString('reconnect Tumblr', (string) $target->fresh()->error);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), '/posts'));
    }

    public function test_dispatcher_routes_tumblr_targets_to_the_tumblr_job(): void
    {
        Queue::fake();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $post->targets()->create(['platform' => 'tumblr', 'status' => PostTarget::STATUS_PENDING]);

        PostPublishDispatcher::dispatch($post);

        Queue::assertPushed(PublishToTumblrJob::class);
    }

    public function test_api_stores_tags_on_the_tumblr_target_and_publishes_text_only_posts(): void
    {
        Queue::fake();
        $account = $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Text only is fine on Tumblr',
            'targets' => [['platform' => 'tumblr', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_POSTED,
            'options' => ['tumblr' => ['tags' => ['art', 'process']]],
        ])->assertCreated();

        $target = PostTarget::where('platform', 'tumblr')->firstOrFail();
        $this->assertSame(['tags' => ['art', 'process']], $target->options);
        Queue::assertPushed(PublishToTumblrJob::class);
    }

    public function test_api_refuses_to_guess_the_blog_when_there_are_several(): void
    {
        Queue::fake();
        $this->account();
        $this->account(['platform_account_id' => 't:blog-two', 'username' => 'blog-two']);

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Which blog?',
            'platforms' => ['tumblr'],
            'status' => Post::STATUS_POSTED,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'more than one Tumblr blog'));

        Queue::assertNothingPushed();
    }

    public function test_api_allows_an_unpinned_target_when_there_is_one_blog(): void
    {
        Queue::fake();
        $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Only one blog',
            'platforms' => ['tumblr'],
            'status' => Post::STATUS_POSTED,
        ])->assertCreated();

        Queue::assertPushed(PublishToTumblrJob::class);
    }

    public function test_job_fails_an_unpinned_target_when_there_are_several_blogs(): void
    {
        Http::fake();
        $this->account();
        $this->account(['platform_account_id' => 't:blog-two', 'username' => 'blog-two']);
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create(['platform' => 'tumblr', 'status' => PostTarget::STATUS_PUBLISHING]);

        (new PublishToTumblrJob($target->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringContainsString('Choose which Tumblr blog', (string) $target->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_api_refuses_tags_that_are_not_a_list_of_strings(): void
    {
        $account = $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'x',
            'targets' => [['platform' => 'tumblr', 'social_account_id' => $account->id]],
            'options' => ['tumblr' => ['tags' => 'art,process']],
        ])->assertStatus(422);
    }

    public function test_caption_and_media_limits(): void
    {
        $this->assertSame(4096, CaptionRules::limit('tumblr'));
        $this->assertNotNull(CaptionRules::captionError('tumblr', str_repeat('a', 4097)));
        $this->assertNull(MediaRules::error('tumblr', array_fill(0, 30, ['type' => 'image', 'url' => 'https://cdn.example/a.png'])));
        $this->assertStringContainsString('at most 30 images', (string) MediaRules::error('tumblr', array_fill(0, 31, ['type' => 'image', 'url' => 'https://cdn.example/a.png'])));
        $this->assertStringContainsString('only 1 video', (string) MediaRules::error('tumblr', [
            ['type' => 'video', 'url' => 'https://cdn.example/a.mp4'],
            ['type' => 'video', 'url' => 'https://cdn.example/b.mp4'],
        ]));
    }

    public function test_tumblr_is_offered_only_once_its_keys_are_set(): void
    {
        $this->assertTrue(app(SocialProviderManager::class)->isConfigured('tumblr'));

        config(['social.platforms.tumblr.client_id' => null]);
        $this->assertFalse(app(SocialProviderManager::class)->isConfigured('tumblr'));
        $this->assertNotContains('tumblr', \App\Mcp\Tools\CreatePost::availablePlatforms());
        $this->assertSame('', \App\Mcp\Tools\CreatePost::tumblrOptions());
    }
}
