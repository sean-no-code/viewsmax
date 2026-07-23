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
