<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishYouTubeJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Providers\GoogleOAuthProvider;
use App\Services\Social\Providers\YouTubeProvider;
use App\Models\User;
use App\Services\YouTubePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * YouTube publishing now resolves the target's account from the multi-account
 * `social_accounts` store (per channel), not the single `connections` row.
 */
class PublishYouTubeJobTest extends TestCase
{
    use RefreshDatabase;

    private function ytAccount(User $user, string $channelId = 'yt-chan-1', ?array $scopes = null): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => $channelId,
            'name' => 'My Channel',
            'access_token' => 'yt-token',
            'refresh_token' => 'yt-refresh',
            'token_expires_at' => now()->addDay(), // valid -> ensureFreshToken is a no-op
            'status' => SocialAccount::STATUS_CONNECTED,
        ] + ($scopes !== null ? ['scopes' => $scopes] : []));
    }

    /** A pending target on a video post, pinned to the given account. */
    private function pendingTarget(User $user, SocialAccount $account): PostTarget
    {
        return $this->videoPost($user)->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_status' => 'public'],
        ]);
    }

    public function test_account_whose_grant_lacks_the_upload_scope_fails_before_uploading(): void
    {
        // The Settings/Analytics connect asks for read + analytics only.
        Http::fake();
        $user = User::factory()->create();
        $account = $this->ytAccount($user, 'yt-chan-1', [
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
        ]);
        $target = $this->pendingTarget($user, $account);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        $this->assertSame(YouTubePublishService::RECONNECT_FOR_UPLOAD, $target->error);
        Http::assertNothingSent();
    }

    public function test_refusals_name_the_real_reason_instead_of_always_saying_reconnect(): void
    {
        $cases = [
            [403, ['errors' => [['reason' => 'quotaExceeded']], 'message' => 'quota'], 'daily API quota'],
            [403, ['errors' => [['reason' => 'insufficientPermissions']], 'message' => 'Insufficient Permission'], 'grant video upload permission'],
            [403, ['errors' => [['reason' => 'youtubeSignupRequired']], 'message' => 'x'], 'no YouTube channel'],
            [401, ['errors' => [['reason' => 'authError']], 'message' => 'Invalid Credentials'], 'session expired'],
            // Google sends the channel's own upload cap as a 400, not a 403.
            [400, ['code' => 400, 'errors' => [['message' => 'The user has exceeded the number of videos they may upload.', 'domain' => 'youtube.video', 'reason' => 'uploadLimitExceeded']], 'message' => 'The user has exceeded the number of videos they may upload.'], "reached YouTube's upload limit"],
        ];

        // One stub that reads the current case: Http::fake() calls stack, and
        // the first registered stub would otherwise answer every case.
        $current = null;
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => function () use (&$current) {
                return Http::response(['error' => $current[1]], $current[0]);
            },
        ]);

        foreach ($cases as [$status, $error, $expected]) {
            $current = [$status, $error];
            $user = User::factory()->create();
            $target = $this->pendingTarget($user, $this->ytAccount($user, 'yt-chan-'.$status.count($error)));

            (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

            $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
            $this->assertStringContainsString($expected, (string) $target->error, "status {$status}");
        }
    }

    public function test_legacy_social_post_path_is_retired_with_a_pointer_to_the_posts_api(): void
    {
        // It used to download the whole video into memory before the PUT.
        Http::fake();
        $user = User::factory()->create();
        $post = SocialPost::create([
            'user_id' => $user->id,
            'content' => 'My video',
            'media' => [['type' => 'video', 'url' => 'https://cdn.example.com/clip.mp4']],
        ]);

        $result = app(YouTubeProvider::class)->publish($this->ytAccount($user), $post);

        $this->assertFalse($result->success);
        $this->assertSame(YouTubeProvider::USE_POSTS_API, $result->error);
        Http::assertNothingSent();
    }

    public function test_privacy_defaults_to_private_when_the_target_did_not_choose(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);
        $user = User::factory()->create();
        $target = $this->videoPost($user)->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $this->ytAccount($user)->id,
            'status' => PostTarget::STATUS_PENDING,
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status, (string) $target->error);
        $this->assertSame('private', $target->meta['privacy_status']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['status']['privacyStatus'] === 'private');
    }

    public function test_session_uri_is_on_the_target_before_the_first_byte_goes_out(): void
    {
        $seen = null;
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => function () use (&$seen) {
                $seen = PostTarget::first()->meta['upload_url'] ?? null;

                return Http::response(['id' => 'yt-vid-1'], 200);
            },
        ]);
        $user = User::factory()->create();
        $target = $this->pendingTarget($user, $this->ytAccount($user));

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame('https://upload.youtube/session', $seen);
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status);
        $this->assertArrayNotHasKey('upload_url', $target->meta, 'a finished session is forgotten');
    }

    public function test_a_retry_whose_session_already_holds_the_video_never_uploads_it_again(): void
    {
        // A worker killed after Google received the last byte: the stored
        // session answers the probe with the created video.
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => fn () => $this->fail('no new session'),
            'upload.youtube/*' => function (Request $request) {
                $this->assertSame('bytes */16', $request->header('Content-Range')[0]);

                return Http::response(['id' => 'yt-vid-done'], 200);
            },
        ]);
        $user = User::factory()->create();
        $target = $this->pendingTarget($user, $this->ytAccount($user));
        $target->forceFill([
            'status' => PostTarget::STATUS_PUBLISHING,
            'meta' => ['upload_url' => 'https://upload.youtube/session', 'step' => ['name' => 'upload', 'at' => now()->toIso8601String()]],
        ])->save();

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('yt-vid-done', $target->platform_post_id);
        Http::assertSentCount(1);
    }

    public function test_a_transient_upload_failure_keeps_the_session_and_lets_the_queue_retry(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['error' => ['message' => 'Backend Error']], 503),
        ]);
        $user = User::factory()->create();
        $target = $this->pendingTarget($user, $this->ytAccount($user));
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHING])->save();

        try {
            (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));
            $this->fail('Expected the transient failure to propagate to the queue.');
        } catch (TransientPublishException) {
        }

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->status, 'not failed: the queue retries');
        $this->assertSame('https://upload.youtube/session', $target->meta['upload_url'], 'the next attempt probes this session');
        $this->assertNull($target->error);
    }

    public function test_a_definitive_failure_forgets_the_session(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['error' => [
                'message' => 'Media body required', 'errors' => [['reason' => 'mediaBodyRequired']],
            ]], 400),
        ]);
        $user = User::factory()->create();
        $target = $this->pendingTarget($user, $this->ytAccount($user));

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('did not receive the video file', (string) $target->error);
        $this->assertArrayNotHasKey('upload_url', $target->meta, 'a manual Retry after a fix starts a fresh session');
    }

    public function test_a_dead_token_fails_the_target_and_flags_the_account_for_reconnect(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response(['error' => [
                'status' => 'UNAUTHENTICATED', 'message' => 'Request had invalid authentication credentials.',
            ]], 401),
        ]);
        $user = User::factory()->create();
        $account = $this->ytAccount($user);
        $target = $this->pendingTarget($user, $account);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        $this->assertSame(YouTubePublishService::SESSION_EXPIRED, $target->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->refresh()->status);
        $this->assertSame(YouTubePublishService::SESSION_EXPIRED, $account->last_error);
    }

    public function test_a_rejected_thumbnail_leaves_the_target_published_with_a_visible_warning(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
            'googleapis.com/upload/youtube/v3/thumbnails/set*' => Http::response(['error' => [
                'message' => 'Forbidden', 'errors' => [['reason' => 'forbidden', 'domain' => 'youtube.thumbnail']],
            ]], 403),
        ]);
        $user = User::factory()->create();
        $post = $this->videoPost($user);
        Storage::disk('r2test')->put('posts/1/cover.jpg', 'fake-jpeg');
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $this->ytAccount($user)->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_status' => 'public', 'thumbnail' => ['path' => 'posts/1/cover.jpg', 'disk' => 'r2test', 'mime' => 'image/jpeg']],
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertNull($target->error);
        $this->assertFalse($target->meta['thumbnail_set']);
        $this->assertSame(
            'We uploaded your video but could not set the thumbnail: This channel is not verified, so YouTube does not allow custom thumbnails. Verify the channel on YouTube, then try again.',
            $target->meta['warning']
        );
    }

    public function test_workspace_policy_refresh_rejections_get_their_own_message(): void
    {
        $cases = [
            [['error' => 'invalid_grant', 'error_subtype' => 'invalid_rapt', 'error_description' => 'reauth related error (invalid_rapt)'], GoogleOAuthProvider::WORKSPACE_POLICY_MESSAGE],
            [['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 'Google token refresh was rejected — reconnect the account.'],
        ];

        config(['social.platforms.youtube.client_id' => 'cid', 'social.platforms.youtube.client_secret' => 'csecret']);
        $current = null;
        Http::fake(['oauth2.googleapis.com/token' => function () use (&$current) {
            return Http::response($current, 400);
        }]);

        foreach ($cases as $i => [$current, $expected]) {
            $user = User::factory()->create();
            $account = $this->ytAccount($user, 'yt-chan-'.$i);
            $account->forceFill(['token_expires_at' => now()->subHour()])->save();

            app(YouTubeProvider::class)->ensureFreshToken($account);

            $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->refresh()->status);
            $this->assertSame($expected, $account->last_error);
        }
    }

    private function videoPost(User $user): Post
    {
        config(['filesystems.default' => 'local', 'filesystems.media_disk' => 'r2test']);
        Storage::fake('local');
        Storage::fake('r2test');
        Storage::disk('r2test')->put('posts/1/clip.mp4', 'fake-video-bytes');

        return Post::create([
            'user_id' => $user->id,
            'caption' => 'My video',
            'media' => [['type' => 'video', 'path' => 'posts/1/clip.mp4', 'disk' => 'r2test']],
            'status' => Post::STATUS_POSTED,
        ]);
    }

    public function test_publishes_video_through_the_resolved_channel_account(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);

        $user = User::factory()->create();
        $account = $this->ytAccount($user);
        $post = $this->videoPost($user);
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_status' => 'public'],
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('yt-vid-1', $target->platform_post_id);
    }

    public function test_pinned_target_with_missing_account_fails_clearly(): void
    {
        $user = User::factory()->create();
        $post = $this->videoPost($user);
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => 999, // no such account
            'status' => PostTarget::STATUS_PENDING,
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('reconnect', strtolower((string) $target->error));
    }

    public function test_an_explicit_title_option_is_sent_as_the_video_title_with_the_caption_as_description(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);
        $user = User::factory()->create();
        $target = $this->videoPost($user)->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $this->ytAccount($user)->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_status' => 'public', 'title' => 'Chosen title', 'community_guidelines_accepted' => true],
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status, (string) $target->error);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['snippet']['title'] === 'Chosen title'
            && $r['snippet']['description'] === 'My video');
    }

    public function test_without_a_title_option_the_caption_is_cut_at_youtubes_100_not_shorter(): void
    {
        Http::fake([
            'googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube/session']),
            'upload.youtube/*' => Http::response(['id' => 'yt-vid-1'], 200),
        ]);
        $user = User::factory()->create();
        $post = $this->videoPost($user);
        $post->forceFill(['caption' => str_repeat('a', 120)])->save();
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $this->ytAccount($user)->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_status' => 'public', 'title' => ''],
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->refresh()->status, (string) $target->error);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && mb_strlen($r['snippet']['title']) === 100);
    }

    public function test_a_title_youtube_would_refuse_fails_before_uploading(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $target = $this->videoPost($user)->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $this->ytAccount($user)->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => ['privacy_status' => 'public', 'title' => 'No <tags> here'],
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        $this->assertStringContainsString('< or >', $target->error);
        Http::assertNothingSent();
    }

    public function test_untitled_post_fails_instead_of_uploading_with_a_placeholder_title(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $account = $this->ytAccount($user);
        $post = $this->videoPost($user);
        $post->forceFill(['caption' => ''])->save();
        $target = $post->targets()->create([
            'platform' => 'youtube',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PENDING,
        ]);

        (new PublishYouTubeJob($target->id))->handle(app(YouTubePublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame(\App\Services\Social\CaptionRules::YOUTUBE_TITLE_REQUIRED, $target->error);
        Http::assertNothingSent();
    }
}
