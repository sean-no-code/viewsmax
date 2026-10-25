<?php

namespace Tests\Feature;

use App\Jobs\PublishToTikTokJob;
use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\TikTokPublishService;
use App\Services\YouTubePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A publish job killed by $timeout never reaches its catch block, so the step
 * it was in must already be on the target (meta.step) before each outbound
 * call, and failed() must turn it into a message that names that step.
 */
class PublishStepMarkerTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user, string $platform): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function videoTarget(User $user, SocialAccount $account, array $options = []): PostTarget
    {
        config(['filesystems.default' => 'local', 'filesystems.media_disk' => 'r2test']);
        Storage::fake('r2test');
        Storage::disk('r2test')->put('posts/1/clip.mp4', 'fake-video-bytes');
        Storage::disk('r2test')->put('posts/1/cover.jpg', 'fake-jpeg');

        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'My video',
            'media' => [['type' => 'video', 'path' => 'posts/1/clip.mp4', 'disk' => 'r2test']],
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => $account->platform,
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => $options,
        ]);
    }

    /** The step persisted on the target at the moment a request goes out. */
    private function stepAt(int $targetId): ?string
    {
        return PostTarget::find($targetId)->meta['step']['name'] ?? null;
    }

    public function test_youtube_job_persists_the_step_before_each_call(): void
    {
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user, 'youtube'), [
            'privacy_status' => 'public',
            'thumbnail' => ['path' => 'posts/1/cover.jpg', 'disk' => 'r2test', 'mime' => 'image/jpeg'],
        ]);

        $seen = [];
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => function () use (&$seen, $target) {
                $seen['init'] = $this->stepAt($target->id);

                return Http::response('', 200, ['Location' => 'https://upload.youtube/session']);
            },
            'upload.youtube/*' => function () use (&$seen, $target) {
                $seen['put'] = $this->stepAt($target->id);

                return Http::response(['id' => 'yt-vid-1'], 200);
            },
            'googleapis.com/upload/youtube/v3/thumbnails/set*' => function () use (&$seen, $target) {
                $seen['thumb'] = $this->stepAt($target->id);

                return Http::response(['kind' => 'youtube#thumbnailSetResponse'], 200);
            },
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status, (string) $target->error);
        $this->assertSame(['init' => 'session-init', 'put' => 'upload', 'thumb' => 'thumbnail'], $seen);
        $this->assertNotEmpty($target->meta['step']['at']);
        $this->assertSame('yt-vid-1', $target->meta['video_id'], 'the marker must not clobber the rest of meta');
    }

    public function test_youtube_timeout_names_the_step_it_stalled_in(): void
    {
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user, 'youtube'));
        $target->forceFill([
            'status' => PostTarget::STATUS_PENDING,
            'meta' => ['step' => ['name' => 'upload', 'at' => now()->toIso8601String()]],
        ])->save();

        (new PublishYouTubeJob($target->id))->failed(new TimeoutExceededException(PublishYouTubeJob::class.' has timed out.'));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        $this->assertSame('Timed out while uploading the video to YouTube.', $target->error);
    }

    public function test_youtube_non_timeout_failure_keeps_its_own_message(): void
    {
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user, 'youtube'));
        $target->forceFill(['meta' => ['step' => ['name' => 'upload', 'at' => now()->toIso8601String()]]])->save();

        (new PublishYouTubeJob($target->id))->failed(new \RuntimeException('Worker ran out of memory'));

        $this->assertSame('Worker ran out of memory', $target->refresh()->error);
    }

    public function test_tiktok_job_persists_the_step_before_init_and_status_calls(): void
    {
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user, 'tiktok'), ['privacy_level' => 'SELF_ONLY']);

        $seen = [];
        Http::fake([
            '*/post/publish/video/init/' => function () use (&$seen, $target) {
                $seen['init'] = $this->stepAt($target->id);

                return Http::response(['data' => ['publish_id' => 'v_1'], 'error' => ['code' => 'ok']], 200);
            },
            '*/post/publish/status/fetch/' => function () use (&$seen, $target) {
                $seen['status'] = $this->stepAt($target->id);

                return Http::response(['data' => ['status' => 'PUBLISH_COMPLETE'], 'error' => ['code' => 'ok']], 200);
            },
        ]);

        $job = new PublishToTikTokJob($target->id);
        $job->handle(app(TikTokPublishService::class)); // init, then release
        $job->handle(app(TikTokPublishService::class)); // status poll

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status, (string) $target->error);
        $this->assertSame(['init' => 'init', 'status' => 'status'], $seen);
        $this->assertSame('v_1', $target->meta['publish_id'], 'the marker must not clobber the rest of meta');
        $this->assertNotEmpty($target->meta['log']);
    }

    public function test_tiktok_timeout_names_the_step_it_stalled_in(): void
    {
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user, 'tiktok'));
        $target->forceFill([
            'status' => PostTarget::STATUS_PUBLISHING,
            'meta' => ['publish_id' => 'v_1', 'step' => ['name' => 'status', 'at' => now()->toIso8601String()]],
        ])->save();

        (new PublishToTikTokJob($target->id))->failed(new TimeoutExceededException(PublishToTikTokJob::class.' has timed out.'));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        $this->assertSame("Timed out while checking TikTok's processing status.", $target->error);
    }
}
