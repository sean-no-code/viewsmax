<?php

namespace Tests\Feature;

use App\Jobs\PublishToBlueskyJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
