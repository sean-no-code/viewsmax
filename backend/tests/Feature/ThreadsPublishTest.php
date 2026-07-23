<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Providers\ThreadsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThreadsPublishTest extends TestCase
{
    private function account(): SocialAccount
    {
        return new SocialAccount([
            'platform' => 'threads',
            'platform_account_id' => 'th-user-1',
            'access_token' => 'token',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /**
     * Regression: the old code blindly slept 5s then published, which raced the
     * async container and failed with "Media not found" (code 24 / 4279009).
     * We must poll the container status and only publish once it's FINISHED.
     */
    public function test_image_post_waits_for_container_before_publishing(): void
    {
        Http::fake([
            '*threads_publish*' => Http::response(['id' => 'thread-123'], 200),
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

        // The container status was polled before publishing.
        Http::assertSent(fn ($req) => $req->method() === 'GET'
            && str_contains($req->url(), 'fields=status'));
    }

    public function test_errored_container_fails_without_publishing(): void
    {
        Http::fake([
            '*threads_publish*' => Http::response(['id' => 'should-not-happen'], 200),
            '*fields=status*' => Http::response(['status' => 'ERROR'], 200),
            '*' => Http::response(['id' => 'container-1'], 200),
        ]);

        $post = new SocialPost([
            'content' => 'bad media',
            'media' => [['type' => 'video', 'url' => 'https://cdn.example/broken.mp4']],
        ]);

        $result = (new ThreadsProvider)->publish($this->account(), $post);

        $this->assertFalse($result->success);
        // Must not attempt to publish a container that failed processing.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'threads_publish'));
    }

    /**
     * TEXT-only posts have no media to process, so they publish immediately
     * without a status poll.
     */
    public function test_text_post_publishes_without_polling(): void
    {
        Http::fake([
            '*threads_publish*' => Http::response(['id' => 'thread-text'], 200),
            '*' => Http::response(['id' => 'container-text'], 200),
        ]);

        $post = new SocialPost(['content' => 'just text']);

        $result = (new ThreadsProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'fields=status'));
    }
}
