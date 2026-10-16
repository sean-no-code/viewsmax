<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToInstagramJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The Instagram job must let a transient Graph error reach the queue (so
 * $tries/$backoff retry the post) while a final rejection or a dead token
 * marks the target failed with the readable reason.
 */
class PublishToInstagramJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function connectInstagram(User $user): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'instagram',
            'platform_account_id' => 'ig-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDays(30),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function makeTarget(User $user): PostTarget
    {
        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'hello',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'instagram',
            'status' => PostTarget::STATUS_PENDING,
        ]);
    }

    private function runJob(PostTarget $target): void
    {
        (new PublishToInstagramJob($target->id))->handle(app(SocialProviderManager::class));
    }

    public function test_transient_graph_error_rethrows_so_the_queue_retries(): void
    {
        Http::fake(['*/media' => Http::response('Service Unavailable', 503)]);
        $user = User::factory()->create();
        $this->connectInstagram($user);
        $target = $this->makeTarget($user);

        try {
            $this->runJob($target);
            $this->fail('Expected a TransientPublishException for the queue to retry.');
        } catch (TransientPublishException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        // Not marked failed: the next attempt publishes the post.
        $this->assertSame(PostTarget::STATUS_PENDING, $target->fresh()->status);
        $this->assertNull($target->fresh()->error);
    }

    public function test_exhausted_retries_mark_the_target_failed_with_the_transient_reason(): void
    {
        $user = User::factory()->create();
        $this->connectInstagram($user);
        $target = $this->makeTarget($user);

        (new PublishToInstagramJob($target->id))->failed(new TransientPublishException('Meta is temporarily unavailable (HTTP 503); the post will be retried.'));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringContainsString('HTTP 503', (string) $target->fresh()->error);
    }

    public function test_rejected_post_marks_the_target_failed_with_a_readable_reason(): void
    {
        Http::fake(['*/media' => Http::response(['error' => ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100, 'error_subcode' => 2207010]], 400)]);
        $user = User::factory()->create();
        $account = $this->connectInstagram($user);
        $target = $this->makeTarget($user);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertSame('Caption is too long', $target->fresh()->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_dead_token_fails_the_target_and_flags_the_account(): void
    {
        Http::fake(['*/media' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463]], 400)]);
        $user = User::factory()->create();
        $account = $this->connectInstagram($user);
        $target = $this->makeTarget($user);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringContainsStringIgnoringCase('reconnect', (string) $target->fresh()->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_successful_publish_records_the_media_id(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['id' => 'media-1']),
            '*/media' => Http::response(['id' => 'container-1']),
        ]);
        $user = User::factory()->create();
        $this->connectInstagram($user);
        $target = $this->makeTarget($user);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status);
        $this->assertSame('media-1', $target->fresh()->platform_post_id);
    }
}
