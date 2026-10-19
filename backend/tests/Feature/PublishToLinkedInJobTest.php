<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToLinkedInJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PublishToLinkedInJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
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
            'platform' => 'linkedin',
            'status' => PostTarget::STATUS_PENDING,
        ]);
    }

    private function runJob(PostTarget $target): PostTarget
    {
        (new PublishToLinkedInJob($target->id))->handle(app(SocialProviderManager::class));

        return $target->refresh();
    }

    private function connectLinkedIn(User $user): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'scopes' => ['w_member_social'],
            'metadata' => ['author_urn' => 'urn:li:person:li-1'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /**
     * LinkedIn's API can't publish video. A video target must fail with a clear
     * note rather than silently posting text-only — and no request is sent.
     */
    public function test_video_target_fails_with_clear_note_and_sends_nothing(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $this->connectLinkedIn($user);

        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'Watch this',
            'media' => [['type' => 'video', 'path' => 'posts/1/clip.mp4', 'url' => 'https://cdn.example/clip.mp4']],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'linkedin',
            'status' => PostTarget::STATUS_PENDING,
        ]);

        (new PublishToLinkedInJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('does not support video', (string) $target->error);
        Http::assertNothingSent();
    }

    /**
     * A text post (no video) is not blocked by the video guard — it publishes.
     */
    public function test_text_post_is_not_blocked_by_video_guard(): void
    {
        Http::fake([
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:123']),
        ]);

        $user = User::factory()->create();
        $this->connectLinkedIn($user);

        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'Just text',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'linkedin',
            'status' => PostTarget::STATUS_PENDING,
        ]);

        (new PublishToLinkedInJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
    }

    /* ---------- a broken image upload fails the target, no post ---------- */

    public function test_image_upload_failure_fails_the_target_and_sends_no_post(): void
    {
        Http::fake([
            'api.linkedin.com/rest/images?action=initializeUpload' => Http::response('{"message":"owner mismatch"}', 400),
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']),
        ]);

        $user = User::factory()->create();
        $this->connectLinkedIn($user);
        $target = $this->runJob($this->makeTarget($user, 'With pic', [
            ['type' => 'image', 'path' => 'posts/1/a.jpg', 'url' => 'https://cdn.example/a.jpg'],
        ]));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('LinkedIn image upload could not be initialized (HTTP 400): {"message":"owner mismatch"}', $target->error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/rest/posts'));
    }

    /* ---------- through the job: Post media → multiImage ---------- */

    public function test_image_post_publishes_with_every_image_attached(): void
    {
        Http::fake([
            'api.linkedin.com/rest/images?action=initializeUpload' => Http::sequence()
                ->push(['value' => ['uploadUrl' => 'https://uploads.linkedin.test/1', 'image' => 'urn:li:image:1']])
                ->push(['value' => ['uploadUrl' => 'https://uploads.linkedin.test/2', 'image' => 'urn:li:image:2']]),
            'uploads.linkedin.test/*' => Http::response('', 201),
            'cdn.example/*' => Http::response('binary', 200, ['Content-Type' => 'image/jpeg']),
            // Member tokens cannot read image status: one grace cycle, then post.
            'api.linkedin.com/rest/images/*' => Http::response('', 403),
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:9']),
        ]);

        $user = User::factory()->create();
        $this->connectLinkedIn($user);
        $target = $this->runJob($this->makeTarget($user, 'Two pics', [
            ['type' => 'image', 'path' => 'posts/1/a.jpg', 'url' => 'https://cdn.example/a.jpg'],
            ['type' => 'image', 'path' => 'posts/1/b.jpg', 'url' => 'https://cdn.example/b.jpg'],
        ]));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('urn:li:share:9', $target->platform_post_id);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:9', $target->meta['url']);
        Sleep::assertSleptTimes(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/rest/posts')
            && $r['content']['multiImage']['images'] === [['id' => 'urn:li:image:1'], ['id' => 'urn:li:image:2']]);
    }

    /* ---------- a 401 from the Posts API flags reconnect ---------- */

    public function test_a_401_on_publish_fails_the_target_and_flags_the_account_for_reconnect(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response('{"message":"Invalid access token"}', 401)]);

        $user = User::factory()->create();
        $account = $this->connectLinkedIn($user);
        $target = $this->runJob($this->makeTarget($user, 'Hi'));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('LinkedIn authorization expired or was revoked — reconnect the account.', $target->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    /* ---------- 429 / 5xx leave the target pending for the retry ---------- */

    public function test_a_rate_limit_rethrows_so_the_queue_retries_without_failing_the_target(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response('{"message":"Too many requests"}', 429)]);

        $user = User::factory()->create();
        $this->connectLinkedIn($user);
        $target = $this->makeTarget($user, 'Hi');

        try {
            $this->runJob($target);
            $this->fail('A 429 should be rethrown for the queue to retry.');
        } catch (TransientPublishException $e) {
            $this->assertSame('LinkedIn rate limit hit during publish — retrying automatically.', $e->getMessage());
        }

        $this->assertSame(PostTarget::STATUS_PENDING, $target->fresh()->status);
        $this->assertNull($target->fresh()->error);
    }

    public function test_a_server_error_on_publish_rethrows_and_the_retry_publishes_once(): void
    {
        // First attempt 503s, the retry succeeds; a further run of the same
        // job is a no-op — nothing is ever posted twice.
        Http::fake(['api.linkedin.com/rest/posts' => Http::sequence()
            ->push('Service Unavailable', 503)
            ->push('', 201, ['x-restli-id' => 'urn:li:share:1'])]);

        $user = User::factory()->create();
        $this->connectLinkedIn($user);
        $target = $this->makeTarget($user, 'Hi');

        try {
            $this->runJob($target);
            $this->fail('A 503 should be rethrown for the queue to retry.');
        } catch (TransientPublishException) {
        }
        $this->assertSame(PostTarget::STATUS_PENDING, $target->fresh()->status);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $this->runJob($target)->status);
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $this->runJob($target)->status);
        Http::assertSentCount(2);
    }
}
