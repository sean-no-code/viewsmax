<?php

namespace Tests\Feature;

use App\Jobs\PublishToXJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
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
            'scopes' => ['tweet.write'],
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
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
    }
}
