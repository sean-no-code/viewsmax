<?php

namespace Tests\Feature;

use App\Jobs\PublishToTikTokJob;
use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-platform publish throughput caps (config/publishing.php): a YouTube or
 * TikTok spike is released back to the queue once over its own cap instead of
 * starving the other platforms. The test queue is sync, so a released job
 * simply does not run.
 */
class PublishRateLimitTest extends TestCase
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

    private function videoTarget(User $user, SocialAccount $account): PostTarget
    {
        config(['filesystems.default' => 'local', 'filesystems.media_disk' => 'r2test']);
        Storage::disk('r2test')->put('posts/1/clip.mp4', 'fake-video-bytes');

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
            'options' => $account->platform === 'youtube' ? ['privacy_status' => 'public'] : ['privacy_level' => 'SELF_ONLY'],
        ]);
    }

    public function test_limiters_read_the_configured_per_minute_caps(): void
    {
        config(['publishing.youtube_per_minute' => 7, 'publishing.tiktok_per_minute' => 0]);

        $youtube = RateLimiter::limiter('publish-youtube')(null);
        $this->assertInstanceOf(Limit::class, $youtube);
        $this->assertSame(7, $youtube->maxAttempts);
        $this->assertSame(60, $youtube->decaySeconds);

        $this->assertInstanceOf(Unlimited::class, RateLimiter::limiter('publish-tiktok')(null), '0 removes the cap');
    }

    public function test_both_jobs_run_through_the_rate_limited_middleware(): void
    {
        $this->assertInstanceOf(RateLimited::class, (new PublishYouTubeJob(1))->middleware()[0]);
        $this->assertInstanceOf(RateLimited::class, (new PublishToTikTokJob(1))->middleware()[0]);
    }

    public function test_a_youtube_spike_over_the_cap_is_released_without_blocking_tiktok(): void
    {
        config(['publishing.youtube_per_minute' => 1, 'publishing.tiktok_per_minute' => 100]);
        Storage::fake('r2test');
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
            '*/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'v_1'], 'error' => ['code' => 'ok']], 200),
        ]);

        $user = User::factory()->create();
        $youtube = $this->account($user, 'youtube');
        $tiktok = $this->account($user, 'tiktok');
        $first = $this->videoTarget($user, $youtube);
        $second = $this->videoTarget($user, $youtube);
        $other = $this->videoTarget($user, $tiktok);

        PublishYouTubeJob::dispatch($first->id);
        PublishYouTubeJob::dispatch($second->id);
        PublishToTikTokJob::dispatch($other->id);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $first->refresh()->status, (string) $first->error);
        $this->assertSame(PostTarget::STATUS_PENDING, $second->refresh()->status, 'over the cap: released, not run and not failed');
        $this->assertNull($second->error);
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $other->refresh()->status, 'TikTok has its own cap and keeps flowing');
    }

    public function test_an_uncapped_platform_runs_every_job(): void
    {
        config(['publishing.youtube_per_minute' => 0]);
        Storage::fake('r2test');
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);

        $user = User::factory()->create();
        $youtube = $this->account($user, 'youtube');
        $targets = [$this->videoTarget($user, $youtube), $this->videoTarget($user, $youtube)];

        foreach ($targets as $target) {
            PublishYouTubeJob::dispatch($target->id);
        }

        foreach ($targets as $target) {
            $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status, (string) $target->error);
        }
    }
}
