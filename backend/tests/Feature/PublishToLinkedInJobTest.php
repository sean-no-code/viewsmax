<?php

namespace Tests\Feature;

use App\Jobs\PublishToLinkedInJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishToLinkedInJobTest extends TestCase
{
    use RefreshDatabase;

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

        (new PublishToLinkedInJob($target->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

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

        (new PublishToLinkedInJob($target->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
    }
}
