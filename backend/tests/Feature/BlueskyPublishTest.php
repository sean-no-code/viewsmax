<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PostTargetCommentJob;
use App\Jobs\PublishToBlueskyJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetComment;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Bluesky\BlobImage;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bluesky publishing through the Post/PostTarget pipeline. Bluesky session
 * JWTs live ~1 hour, so the job must refresh the session before posting.
 */
class BlueskyPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function connectBluesky(array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => 'bluesky',
            'platform_account_id' => 'did:plc:abc123',
            'name' => 'Tester',
            'username' => 'tester.bsky.social',
            'access_token' => 'access-jwt',
            'refresh_token' => 'refresh-jwt',
            'token_expires_at' => now()->addHour(),
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['did' => 'did:plc:abc123', 'handle' => 'tester.bsky.social'],
        ], $overrides));
    }

    private function makeTarget(string $caption, array $media = [], ?int $accountId = null): PostTarget
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => $caption,
            'media' => $media,
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'bluesky',
            'status' => PostTarget::STATUS_PENDING,
            'social_account_id' => $accountId,
        ]);
    }

    private function runJob(PostTarget $target): void
    {
        (new PublishToBlueskyJob($target->id))->handle(app(SocialProviderManager::class));
    }

    public function test_text_post_publishes_and_records_url(): void
    {
        Http::fake([
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
                'uri' => 'at://did:plc:abc123/app.bsky.feed.post/rkey99',
                'cid' => 'cid99',
            ]),
        ]);
        $account = $this->connectBluesky();
        $target = $this->makeTarget('Hello sky', [], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('at://did:plc:abc123/app.bsky.feed.post/rkey99', $target->platform_post_id);
        $this->assertSame('https://bsky.app/profile/tester.bsky.social/post/rkey99', data_get($target->meta, 'url'));
    }

    public function test_expired_session_is_refreshed_before_posting(): void
    {
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([
                'accessJwt' => 'fresh-access',
                'refreshJwt' => 'fresh-refresh',
            ]),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
                'uri' => 'at://did:plc:abc123/app.bsky.feed.post/rkey1',
            ]),
        ]);
        $account = $this->connectBluesky(['token_expires_at' => now()->subMinute()]);
        $target = $this->makeTarget('refresh me', [], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'refreshSession'));
        $this->assertSame('fresh-access', $account->fresh()->access_token);
    }

    public function test_session_refresh_sends_no_request_body(): void
    {
        // Live Bluesky rejects a refresh that carries any body with 400
        // "A request body was provided when none was expected", which used to
        // flip every account to needs-reauth an hour after connecting.
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.refreshSession' => fn ($request) => $request->body() === ''
                ? Http::response(['accessJwt' => 'fresh-access', 'refreshJwt' => 'fresh-refresh'])
                : Http::response(['error' => 'InvalidRequest', 'message' => 'A request body was provided when none was expected'], 400),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
                'uri' => 'at://did:plc:abc123/app.bsky.feed.post/rkey2',
            ]),
        ]);
        $account = $this->connectBluesky(['token_expires_at' => now()->subMinute()]);
        $target = $this->makeTarget('no body please', [], $account->id);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status, (string) $target->fresh()->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_session_expiry_comes_from_the_token_bluesky_issues(): void
    {
        // Bluesky access tokens carry their own expiry (two hours on the live
        // service); the stored expiry must follow it instead of a guess.
        $connectExp = now()->addHours(2)->startOfSecond();
        $refreshExp = now()->addHours(3)->startOfSecond();
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
                'did' => 'did:plc:new',
                'handle' => 'new.bsky.social',
                'accessJwt' => $this->jwt($connectExp->timestamp),
                'refreshJwt' => 'refresh-jwt',
            ]),
            'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([
                'accessJwt' => $this->jwt($refreshExp->timestamp),
                'refreshJwt' => 'refresh-jwt-2',
            ]),
        ]);
        $provider = app(SocialProviderManager::class)->for('bluesky');

        $account = $provider->connectWithCredentials($this->user, ['identifier' => 'new.bsky.social', 'password' => 'app-pass'])->first();
        $this->assertTrue($connectExp->equalTo($account->fresh()->token_expires_at));

        $account->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $provider->ensureFreshToken($account->fresh());
        $this->assertTrue($refreshExp->equalTo($account->fresh()->token_expires_at));
    }

    public function test_leading_at_sign_is_stripped_from_the_handle_at_login(): void
    {
        // Bluesky reads any identifier containing "@" as an email address, so
        // "@new.bsky.social" failed with "InvalidEmail: local part cannot be empty".
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
                'did' => 'did:plc:new',
                'handle' => 'new.bsky.social',
                'accessJwt' => $this->jwt(now()->addHours(2)->timestamp),
                'refreshJwt' => 'refresh-jwt',
            ]),
        ]);
        $provider = app(SocialProviderManager::class)->for('bluesky');

        $provider->connectWithCredentials($this->user, ['identifier' => '  @new.bsky.social ', 'password' => 'app-pass']);
        $provider->connectWithCredentials($this->user, ['identifier' => 'me@example.com', 'password' => 'app-pass']);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]['identifier'])->all();
        $this->assertSame(['new.bsky.social', 'me@example.com'], $sent);
    }

    private function jwt(int $exp): string
    {
        $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $part(['typ' => 'at+jwt', 'alg' => 'ES256K']).'.'.$part(['exp' => $exp, 'sub' => 'did:plc:new']).'.signature';
    }

    public function test_video_media_fails_with_clear_note(): void
    {
        Http::fake();
        $account = $this->connectBluesky();
        $target = $this->makeTarget('watch', [
            ['type' => 'video', 'url' => 'https://cdn.example/clip.mp4'],
        ], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('video', (string) $target->error);
        Http::assertNothingSent();
    }

    public function test_no_connected_account_fails_with_clear_note(): void
    {
        Http::fake();
        $target = $this->makeTarget('nobody home');

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('bluesky', (string) $target->error);
        Http::assertNothingSent();
    }

    /* ---------- facets ---------- */

    public function test_links_mentions_and_tags_become_facets_with_byte_offsets(): void
    {
        Http::fake([
            'bsky.social/xrpc/com.atproto.identity.resolveHandle*' => fn ($request) => $request['handle'] === 'alice.bsky.social'
                ? Http::response(['did' => 'did:plc:alice'])
                : Http::response(['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], 400),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc123/app.bsky.feed.post/r1', 'cid' => 'c1']),
        ]);
        $account = $this->connectBluesky();
        // "héllo " is 7 bytes for 6 characters: offsets must be bytes.
        $text = 'héllo @alice.bsky.social @ghost.bsky.social https://example.com/x #Tag';
        $target = $this->makeTarget($text, [], $account->id);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status, (string) $target->fresh()->error);
        Http::assertSent(function ($request) use ($text) {
            if (! str_contains($request->url(), 'createRecord')) {
                return false;
            }
            $record = $request['record'];
            $facets = $record['facets'] ?? [];
            $slice = fn ($f) => substr($text, $f['index']['byteStart'], $f['index']['byteEnd'] - $f['index']['byteStart']);

            return $record['text'] === $text
                && count($facets) === 3
                && $facets[0]['index'] === ['byteStart' => 7, 'byteEnd' => 25]
                && $facets[0]['features'][0] === ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:alice']
                && $slice($facets[1]) === 'https://example.com/x'
                && $facets[1]['features'][0]['$type'] === 'app.bsky.richtext.facet#link'
                && $slice($facets[2]) === '#Tag'
                && $facets[2]['features'][0] === ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'Tag'];
        });
        // The unresolved handle was looked up but stays plain text.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'resolveHandle') && $r['handle'] === 'ghost.bsky.social');
    }

    public function test_plain_text_sends_no_facets_key(): void
    {
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://x/app.bsky.feed.post/r', 'cid' => 'c'])]);
        $target = $this->makeTarget('nothing to link here', [], $this->connectBluesky()->id);

        $this->runJob($target);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'createRecord') && ! array_key_exists('facets', $r['record']));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'resolveHandle'));
    }

    /* ---------- image size + aspectRatio ---------- */

    /** A PNG of random pixels compresses badly, so 3000x2000 is well over 1 MB. */
    private function noisyPng(int $w, int $h, int $pixels): string
    {
        mt_srand(7);
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < $pixels; $i++) {
            imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function fakeImagePublish(string $imageBytes, array &$uploads): void
    {
        Http::fake([
            'cdn.example/*' => Http::response($imageBytes, 200, ['Content-Type' => 'image/png']),
            'bsky.social/xrpc/com.atproto.repo.uploadBlob' => function ($request) use (&$uploads) {
                $uploads[] = $request->body();

                return Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafy'.count($uploads)], 'mimeType' => 'image/jpeg', 'size' => strlen($request->body())]]);
            },
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc123/app.bsky.feed.post/r1', 'cid' => 'c1']),
        ]);
    }

    public function test_oversized_image_is_shrunk_under_the_blob_limit_and_sent_with_aspect_ratio(): void
    {
        $uploads = [];
        $big = $this->noisyPng(3000, 2000, 200000);
        $this->assertGreaterThan(BlobImage::MAX_BYTES, strlen($big));
        $this->fakeImagePublish($big, $uploads);
        $target = $this->makeTarget('big pic', [['type' => 'image', 'url' => 'https://cdn.example/big.png', 'alt' => 'noise']], $this->connectBluesky()->id);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status, (string) $target->fresh()->error);
        $this->assertCount(1, $uploads);
        $this->assertLessThanOrEqual(BlobImage::MAX_BYTES, strlen($uploads[0]));
        [$w, $h] = getimagesizefromstring($uploads[0]);
        $this->assertLessThan(3000, $w);
        Http::assertSent(function ($r) use ($w, $h) {
            $image = $r['record']['embed']['images'][0] ?? null;

            return str_contains($r->url(), 'createRecord')
                && $r['record']['embed']['$type'] === 'app.bsky.embed.images'
                && $image['alt'] === 'noise'
                && $image['image']['ref']['$link'] === 'bafy1'
                && $image['aspectRatio'] === ['width' => $w, 'height' => $h];
        });
    }

    public function test_small_image_is_uploaded_untouched_with_its_aspect_ratio(): void
    {
        $uploads = [];
        $small = $this->noisyPng(64, 48, 20);
        $this->fakeImagePublish($small, $uploads);
        $target = $this->makeTarget('small pic', [['type' => 'image', 'url' => 'https://cdn.example/small.png']], $this->connectBluesky()->id);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status, (string) $target->fresh()->error);
        $this->assertSame($small, $uploads[0]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'uploadBlob') && $r->hasHeader('Content-Type', 'image/png'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createRecord')
            && $r['record']['embed']['images'][0]['aspectRatio'] === ['width' => 64, 'height' => 48]);
    }

    /* ---------- a failed image fails the post, nothing is published ---------- */

    public function test_blob_upload_failure_fails_the_target_and_never_creates_the_record(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response($this->noisyPng(64, 48, 20), 200, ['Content-Type' => 'image/png']),
            'bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response(['error' => 'BlobTooLarge', 'message' => 'This file is too large. It is 1.20MB but the maximum size is 1.00MB.'], 400),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://x', 'cid' => 'c']),
        ]);
        $target = $this->makeTarget('pic', [['type' => 'image', 'url' => 'https://cdn.example/pic.png']], $this->connectBluesky()->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringStartsWith('Could not prepare the post for Bluesky, nothing was published: ', (string) $target->error);
        $this->assertStringContainsString('This file is too large', (string) $target->error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createRecord'));
    }

    public function test_undownloadable_image_fails_the_target_before_any_bluesky_call(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('gone', 404),
            'bsky.social/*' => Http::response(['uri' => 'at://x', 'cid' => 'c']),
        ]);
        $target = $this->makeTarget('pic', [['type' => 'image', 'url' => 'https://cdn.example/missing.png']], $this->connectBluesky()->id);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringStartsWith('Could not prepare the post for Bluesky, nothing was published: could not download', (string) $target->fresh()->error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'bsky.social'));
    }

    /* ---------- transient vs definite failures ---------- */

    private function assertJobRetries(PostTarget $target, string $expectedMessage): void
    {
        try {
            $this->runJob($target);
            $this->fail('Expected TransientPublishException');
        } catch (TransientPublishException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PENDING, $target->status, 'a retryable failure must not mark the target failed');
        $this->assertNull($target->error);
    }

    public function test_rate_limited_create_record_is_rethrown_for_the_queue_to_retry(): void
    {
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'RateLimitExceeded'], 429)]);
        $target = $this->makeTarget('slow down', [], $this->connectBluesky()->id);

        $this->assertJobRetries($target, 'Bluesky rate limit hit during publish — retrying automatically.');
    }

    public function test_server_error_during_blob_upload_is_retried(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response($this->noisyPng(64, 48, 20), 200, ['Content-Type' => 'image/png']),
            'bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response('upstream', 503),
        ]);
        $target = $this->makeTarget('pic', [['type' => 'image', 'url' => 'https://cdn.example/pic.png']], $this->connectBluesky()->id);

        $this->assertJobRetries($target, 'Bluesky returned a temporary error during image upload (HTTP 503) — retrying automatically.');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createRecord'));
    }

    public function test_network_failure_on_create_record_is_retried(): void
    {
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);
        $target = $this->makeTarget('hello?', [], $this->connectBluesky()->id);

        $this->assertJobRetries($target, 'Bluesky could not be reached during publish — retrying automatically.');
    }

    public function test_transient_session_refresh_failure_retries_instead_of_posting_with_a_dead_token(): void
    {
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response('upstream', 502),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400),
        ]);
        $account = $this->connectBluesky(['token_expires_at' => now()->subMinute()]);
        $target = $this->makeTarget('refresh me', [], $account->id);

        $this->assertJobRetries($target, 'Bluesky returned a temporary error during session refresh (HTTP 502) — retrying automatically.');
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createRecord'));
    }

    public function test_definite_rejection_fails_the_target_without_retry(): void
    {
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InvalidRecord', 'message' => 'Record/text must not be longer than 300 graphemes'], 400)]);
        $target = $this->makeTarget('too long', [], $this->connectBluesky()->id);

        $this->runJob($target); // no exception: a 4xx must not be retried

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('Bluesky rejected the post, nothing was published: Record/text must not be longer than 300 graphemes (HTTP 400)', $target->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, SocialAccount::sole()->status);
    }

    public function test_unauthorized_create_record_flags_the_account_for_reconnect(): void
    {
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InvalidToken', 'message' => 'Bad token'], 401)]);
        $account = $this->connectBluesky();
        $target = $this->makeTarget('who am i', [], $account->id);

        $this->runJob($target);

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame($account->fresh()->last_error, $target->error);
        $this->assertStringContainsString('reconnect', (string) $target->error);
    }

    public function test_expired_token_error_body_also_flags_reconnect(): void
    {
        // Bluesky reports an expired access JWT as 400 ExpiredToken, not 401.
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400)]);
        $account = $this->connectBluesky();
        $target = $this->makeTarget('stale', [], $account->id);

        $this->runJob($target);

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
    }

    /* ---------- comments reply in a thread ---------- */

    private function threadResponse(string $uri, string $cid, ?array $root = null): array
    {
        $record = ['$type' => 'app.bsky.feed.post', 'text' => 'x'];
        if ($root) {
            $record['reply'] = ['root' => $root, 'parent' => $root];
        }

        return ['thread' => ['$type' => 'app.bsky.feed.defs#threadViewPost', 'post' => ['uri' => $uri, 'cid' => $cid, 'record' => $record]]];
    }

    private function publishedTargetWithComments(SocialAccount $account): PostTarget
    {
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'main post', 'media' => [], 'status' => Post::STATUS_POSTED]);
        $post->comments()->create(['position' => 0, 'body' => 'first reply @alice.bsky.social', 'delay_seconds' => 0]);
        $post->comments()->create(['position' => 1, 'body' => 'second reply', 'delay_seconds' => 0]);
        $target = $post->targets()->create(['platform' => 'bluesky', 'social_account_id' => $account->id, 'status' => PostTarget::STATUS_PUBLISHING]);
        $target->forceFill([
            'status' => PostTarget::STATUS_PUBLISHED,
            'platform_post_id' => 'at://did:plc:abc123/app.bsky.feed.post/root',
            'published_at' => now(),
            'meta' => ['cid' => 'cid-root'],
        ])->save();

        return $target;
    }

    public function test_publishing_persists_the_cid_and_starts_the_comment_chain(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc123/app.bsky.feed.post/root', 'cid' => 'cid-root'])]);
        $target = $this->makeTarget('main', [], $this->connectBluesky()->id);
        $target->post->comments()->create(['position' => 0, 'body' => 'a reply', 'delay_seconds' => 0]);

        $this->runJob($target);

        $this->assertSame('cid-root', data_get($target->fresh()->meta, 'cid'));
        // Bluesky implements SupportsComments now, so the row is pending (not skipped) and its job is queued.
        $this->assertSame(PostTargetComment::STATUS_PENDING, PostTargetComment::where('post_target_id', $target->id)->sole()->status);
        Queue::assertPushed(PostTargetCommentJob::class, 1);
    }

    public function test_first_comment_replies_to_the_post_as_root_and_parent(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        $records = [];
        Http::fake([
            'bsky.social/xrpc/app.bsky.feed.getPostThread*' => Http::response($this->threadResponse('at://did:plc:abc123/app.bsky.feed.post/root', 'cid-root')),
            'bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:alice']),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => function ($request) use (&$records) {
                $records[] = $request['record'];

                return Http::response(['uri' => 'at://did:plc:abc123/app.bsky.feed.post/reply1', 'cid' => 'cid-reply1']);
            },
        ]);
        $target = $this->publishedTargetWithComments($this->connectBluesky());
        $first = PostTargetComment::where('post_target_id', $target->id)->whereHas('comment', fn ($q) => $q->where('position', 0))->firstOrFail();

        (new PostTargetCommentJob($first->id))->handle(app(SocialProviderManager::class));

        $first->refresh();
        $this->assertSame(PostTargetComment::STATUS_POSTED, $first->status, (string) $first->error);
        $this->assertSame('at://did:plc:abc123/app.bsky.feed.post/reply1', $first->platform_comment_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'getPostThread') && $r['uri'] === 'at://did:plc:abc123/app.bsky.feed.post/root' && (int) $r['depth'] === 0);
        $this->assertCount(1, $records);
        $this->assertSame('first reply @alice.bsky.social', $records[0]['text']);
        $this->assertSame([
            'root' => ['uri' => 'at://did:plc:abc123/app.bsky.feed.post/root', 'cid' => 'cid-root'],
            'parent' => ['uri' => 'at://did:plc:abc123/app.bsky.feed.post/root', 'cid' => 'cid-root'],
        ], $records[0]['reply']);
        $this->assertSame('did:plc:alice', $records[0]['facets'][0]['features'][0]['did'], 'comments get facets too');
    }

    public function test_second_comment_chains_off_the_first_keeping_the_thread_root(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        $root = ['uri' => 'at://did:plc:abc123/app.bsky.feed.post/root', 'cid' => 'cid-root'];
        Http::fake([
            'bsky.social/xrpc/app.bsky.feed.getPostThread*' => Http::response($this->threadResponse('at://did:plc:abc123/app.bsky.feed.post/reply1', 'cid-reply1', $root)),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc123/app.bsky.feed.post/reply2', 'cid' => 'cid-reply2']),
        ]);
        $target = $this->publishedTargetWithComments($this->connectBluesky());
        [$first, $second] = PostTargetComment::where('post_target_id', $target->id)
            ->join('post_comments', 'post_comments.id', '=', 'post_target_comments.post_comment_id')
            ->orderBy('post_comments.position')->select('post_target_comments.*')->get();
        $first->forceFill(['status' => PostTargetComment::STATUS_POSTED, 'platform_comment_id' => 'at://did:plc:abc123/app.bsky.feed.post/reply1'])->save();

        (new PostTargetCommentJob($second->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTargetComment::STATUS_POSTED, $second->fresh()->status, (string) $second->fresh()->error);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'getPostThread') && $r['uri'] === 'at://did:plc:abc123/app.bsky.feed.post/reply1');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createRecord') && $r['record']['reply'] === [
            'root' => $root,
            'parent' => ['uri' => 'at://did:plc:abc123/app.bsky.feed.post/reply1', 'cid' => 'cid-reply1'],
        ]);
    }

    public function test_comment_fails_cleanly_when_the_parent_cannot_be_loaded(): void
    {
        Queue::fake([PostTargetCommentJob::class]);
        Http::fake([
            'bsky.social/xrpc/app.bsky.feed.getPostThread*' => Http::response(['error' => 'NotFound', 'message' => 'Post not found'], 404),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://x', 'cid' => 'c']),
        ]);
        $target = $this->publishedTargetWithComments($this->connectBluesky());
        $first = PostTargetComment::where('post_target_id', $target->id)->whereHas('comment', fn ($q) => $q->where('position', 0))->firstOrFail();

        (new PostTargetCommentJob($first->id))->handle(app(SocialProviderManager::class));

        $first->refresh();
        $this->assertSame(PostTargetComment::STATUS_FAILED, $first->status);
        $this->assertSame('Could not load the Bluesky post to reply to: Post not found (HTTP 404)', $first->error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createRecord'));
    }
}
