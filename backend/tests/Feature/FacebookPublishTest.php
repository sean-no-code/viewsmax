<?php

namespace Tests\Feature;

use App\Jobs\PublishToFacebookJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Facebook Page publishing through the Post/PostTarget pipeline. The provider
 * (FacebookProvider) already speaks the Graph API; this covers the job bridge.
 */
class FacebookPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function connectPage(): SocialAccount
    {
        return $this->user->socialAccounts()->create([
            'platform' => 'facebook',
            'platform_account_id' => 'page-1',
            'name' => 'My Page',
            'access_token' => 'page-token',
            // Facebook page tokens are long-lived; no expiry recorded.
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
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
            'platform' => 'facebook',
            'status' => PostTarget::STATUS_PENDING,
            'social_account_id' => $accountId,
        ]);
    }

    private function runJob(PostTarget $target): void
    {
        (new PublishToFacebookJob($target->id))->handle(app(SocialProviderManager::class));
    }

    public function test_text_post_publishes_to_the_page_feed(): void
    {
        Http::fake([
            'graph.facebook.com/*/page-1/feed' => Http::response(['id' => 'page-1_777']),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('Hello Facebook', [], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('page-1_777', $target->platform_post_id);
        $this->assertStringContainsString('facebook.com', (string) data_get($target->meta, 'url'));
    }

    public function test_image_post_publishes_a_photo(): void
    {
        Http::fake([
            'graph.facebook.com/*/page-1/photos' => Http::response(['id' => '888', 'post_id' => 'page-1_888']),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('With pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('page-1_888', $target->platform_post_id);
    }

    public function test_no_connected_page_fails_with_clear_note(): void
    {
        Http::fake();
        $target = $this->makeTarget('No page');

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('facebook', (string) $target->error);
        Http::assertNothingSent();
    }

    public function test_graph_error_marks_target_failed(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'expired token']], 400),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('boom', [], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertNotEmpty($target->error);
    }
}
