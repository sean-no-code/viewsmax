<?php

namespace Tests\Feature;

use App\Exceptions\TikTokUploadInterruptedException;
use App\Jobs\PublishToTikTokJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Providers\TikTokProvider;
use App\Services\TikTokPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The TikTok publish is irreversible once init returns a publish_id, so the
 * job must persist it immediately, never init twice, and let TikTok's own
 * status verdict decide anything that goes wrong after that point. Plus the
 * error vocabulary, reauth flagging,
 * post URLs and the moderation-lag id resolver that hang off that flow.
 */
class TikTokPendingPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function account(User $user, array $extra = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'name' => 'Tester Name',
            'username' => 'Tester Name', // user.info.basic only gives display_name
            'metadata' => ['creator_username' => 'tester'],
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ], $extra));
    }

    private function videoTarget(User $user, SocialAccount $account, array $media = [], array $options = ['privacy_level' => 'PUBLIC_TO_EVERYONE']): PostTarget
    {
        config(['filesystems.default' => 'local', 'filesystems.media_disk' => 'r2test']);
        Storage::fake('r2test');
        Storage::disk('r2test')->put('posts/1/clip.mp4', str_repeat('v', 2048));

        $post = Post::create([
            'user_id' => $user->id,
            'caption' => 'My video',
            'media' => [array_merge(['type' => 'video', 'path' => 'posts/1/clip.mp4', 'disk' => 'r2test'], $media)],
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'tiktok',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PENDING,
            'options' => $options,
        ]);
    }

    /** A target TikTok already accepted (publish_id stored), waiting on status. */
    private function pendingTarget(User $user, SocialAccount $account, string $publishId = 'v_pub_1', array $options = ['privacy_level' => 'PUBLIC_TO_EVERYONE']): PostTarget
    {
        $target = $this->videoTarget($user, $account, [], $options);
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHING, 'meta' => ['publish_id' => $publishId, 'mode' => 'direct']])->save();

        return $target;
    }

    private function requests(string $contains): Collection
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r->url(), $contains));
    }

    // ---- publish_id is the point of no return ----------

    public function test_publish_id_is_persisted_before_the_chunks_and_a_network_error_after_init_keeps_the_target_pending(): void
    {
        config(['services.tiktok.video_pull_from_url' => false]);
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user));

        $persistedAtFirstChunk = null;
        Http::fake([
            '*/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'v_pub_1', 'upload_url' => 'https://upload.tiktok.test/x'], 'error' => ['code' => 'ok']], 200),
            'upload.tiktok.test/*' => function () use (&$persistedAtFirstChunk, $target) {
                $persistedAtFirstChunk ??= PostTarget::find($target->id)->meta;

                return Http::failedConnection(); // every retry fails; the bytes may or may not have landed
            },
            '*/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PROCESSING_UPLOAD'], 'error' => ['code' => 'ok']], 200),
        ]);

        $job = new PublishToTikTokJob($target->id);
        $job->handle(app(TikTokPublishService::class));

        $this->assertSame('v_pub_1', $persistedAtFirstChunk['publish_id'] ?? null, 'publish_id must be on the target before the first chunk goes out');
        $this->assertSame('https://upload.tiktok.test/x', $persistedAtFirstChunk['upload_url'] ?? null);
        $this->assertSame('upload', $persistedAtFirstChunk['step']['name'] ?? null);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->status, 'a network error after init is not TikTok\'s verdict');
        $this->assertNull($target->error);
        $last = collect($target->meta['log'])->last();
        $this->assertSame('pending', $last['step']);
        $this->assertStringContainsString('upload interrupted', $last['response']);

        // Next attempt: no second init, straight to status polling.
        $job->handle(app(TikTokPublishService::class));

        $this->assertCount(1, $this->requests('/video/init/'), 'a stored publish_id must never be re-inited');
        $this->assertCount(1, $this->requests('/status/fetch/'));
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->refresh()->status);
    }

    public function test_a_media_read_failure_after_init_is_an_interruption_not_a_rejection(): void
    {
        Http::fake([
            '*/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'v_pub_1', 'upload_url' => 'https://upload.tiktok.test/x']], 200),
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'short');
        rewind($stream);

        $this->expectException(TikTokUploadInterruptedException::class);
        try {
            app(TikTokPublishService::class)->publishVideoFromStream(new SocialAccount(['platform' => 'tiktok', 'access_token' => 'token']), 'Clip', $stream, 5000, []);
        } finally {
            fclose($stream);
        }
    }

    public function test_an_explicit_chunk_rejection_still_fails_the_target(): void
    {
        config(['services.tiktok.video_pull_from_url' => false]);
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user));

        Http::fake([
            '*/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'v_pub_1', 'upload_url' => 'https://upload.tiktok.test/x']], 200),
            'upload.tiktok.test/*' => Http::response(['error' => ['code' => 'invalid_file_upload']], 400),
        ]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame("TikTok rejected the upload: the uploaded file does not meet TikTok's specifications.", $target->error);
    }

    public function test_job_timeout_covers_the_upload_but_stays_under_the_queue_retry_window(): void
    {
        $job = new PublishToTikTokJob(1);

        $this->assertGreaterThanOrEqual(300, $job->timeout, 'init (≤100 s with retries) + chunked upload must fit');
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout,
            'past retry_after a second worker would run the same job concurrently');
    }

    // ---- transient status errors keep polling ---------------------

    public function test_a_5xx_on_status_fetch_keeps_the_target_pending(): void
    {
        $user = User::factory()->create();
        $target = $this->pendingTarget($user, $this->account($user));

        Http::fake(['*/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'internal_error']], 503)]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->status, (string) $target->error);
        $this->assertNull($target->error);
        $this->assertStringContainsString('HTTP 503', collect($target->meta['log'])->last()['response']);
        $this->assertSame('v_pub_1', $target->meta['publish_id']);
    }

    public function test_a_connection_error_on_status_fetch_keeps_the_target_pending(): void
    {
        $user = User::factory()->create();
        $target = $this->pendingTarget($user, $this->account($user));

        Http::fake(['*/post/publish/status/fetch/' => fn () => Http::failedConnection()]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->refresh()->status, (string) $target->error);
    }

    public function test_a_failed_status_still_fails_the_target(): void
    {
        $user = User::factory()->create();
        $target = $this->pendingTarget($user, $this->account($user));

        Http::fake(['*/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'FAILED', 'fail_reason' => 'spam_risk_too_many_pending_share']], 200)]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('TikTok rejected the post: TikTok allows at most 5 pending posts in any 24 hours — finish or discard them in the TikTok app and try again later.', $target->error);
    }

    // ---- one error vocabulary for init and fail_reason ------------

    public function test_init_errors_are_mapped_to_plain_english_not_raw_json(): void
    {
        $user = User::factory()->create();
        $target = $this->videoTarget($user, $this->account($user));

        Http::fake(['*/post/publish/video/init/' => Http::response(['error' => ['code' => 'file_format_check_failed', 'message' => 'bad'], 'data' => []], 400)]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame("TikTok rejected the post: the video's file format is not supported — check TikTok's video specifications.", $target->error);
        $this->assertStringNotContainsString('{', $target->error);
    }

    public function test_unknown_codes_fall_back_to_the_bare_code_and_known_ones_are_mapped(): void
    {
        $this->assertSame('TikTok rejected the post: something_new', TikTokPublishService::rejection('TikTok rejected the post', 'something_new'));
        $this->assertSame('TikTok rejected the post (HTTP 500).', TikTokPublishService::rejection('TikTok rejected the post', null, 500));
        // Creator-cannot-post codes keep their standalone "try again later" line (Required UX 1b).
        $this->assertStringContainsString('try again later', TikTokPublishService::rejection('TikTok rejected the post', 'spam_risk_too_many_posts'));
        $this->assertStringNotContainsString('rejected', TikTokPublishService::rejection('TikTok rejected the post', 'spam_risk_too_many_posts'));

        foreach ([
            'file_format_check_failed', 'duration_check_failed', 'frame_rate_check_failed', 'video_pull_failed',
            'photo_pull_failed', 'spam_risk_text', 'spam_risk_too_many_pending_share', 'url_ownership_unverified',
            'privacy_level_option_mismatch', 'invalid_params', 'picture_size_check_failed',
            'unaudited_client_can_only_post_to_private_accounts', 'internal_error', 'rate_limit_exceeded',
            'invalid_file_upload', 'app_version_check_failed',
        ] as $code) {
            $this->assertNotNull(TikTokPublishService::errorMessage($code), "{$code} must have a user-facing message");
            $this->assertStringNotContainsString($code, TikTokPublishService::errorMessage($code));
        }
    }

    // ---- dead token flags the account ------------------------------

    public function test_access_token_invalid_on_init_flags_the_account_for_reconnect(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $target = $this->videoTarget($user, $account);

        Http::fake(['*/post/publish/video/init/' => Http::response(['error' => ['code' => 'access_token_invalid']], 401)]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->refresh()->status);
        $this->assertSame(TikTokPublishService::REAUTH_MESSAGE, $account->last_error);
        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('reconnect', $target->error);
    }

    public function test_scope_not_authorized_on_status_fetch_flags_the_account_and_fails_the_target(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $target = $this->pendingTarget($user, $account);

        Http::fake(['*/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'scope_not_authorized']], 401)]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->refresh()->status);
        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status, 'an auth error is not transient');
        $this->assertSame(TikTokPublishService::REAUTH_MESSAGE, $target->error);
    }

    // ---- post URLs ------------------------------------------------

    public function test_post_url_uses_video_or_photo_by_publish_id_prefix_and_falls_back_to_the_profile(): void
    {
        $account = new SocialAccount(['username' => 'Display Name', 'metadata' => ['creator_username' => 'handle']]);

        $this->assertSame('https://www.tiktok.com/@handle/video/123', TikTokPublishService::postUrl($account, 'v_pub_file~x', '123'));
        $this->assertSame('https://www.tiktok.com/@handle/photo/456', TikTokPublishService::postUrl($account, 'p_pub_url~y', '456'));
        $this->assertSame('https://www.tiktok.com/@handle', TikTokPublishService::postUrl($account, 'v_pub_file~x', null));
        $this->assertNull(TikTokPublishService::postUrl(new SocialAccount, 'v_pub_file~x', '1'));
    }

    public function test_published_target_stores_the_post_url_or_the_profile_until_the_id_exists(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);

        // Moderation already approved the photo post; the video is still waiting.
        Http::fake(['*/post/publish/status/fetch/' => fn (Request $r) => Http::response(['data' => array_merge(
            ['status' => 'PUBLISH_COMPLETE'],
            $r->data()['publish_id'] === 'p_pub_url~1' ? ['publicaly_available_post_id' => [7331]] : [],
        )], 200)]);

        $withId = $this->pendingTarget($user, $account, 'p_pub_url~1');
        (new PublishToTikTokJob($withId->id))->handle(app(TikTokPublishService::class));
        $withId->refresh();
        $this->assertSame('7331', $withId->platform_post_id);
        $this->assertSame('https://www.tiktok.com/@tester/photo/7331', $withId->meta['url']);

        $lagging = $this->pendingTarget($user, $account, 'v_pub_file~2');
        (new PublishToTikTokJob($lagging->id))->handle(app(TikTokPublishService::class));
        $lagging->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $lagging->status);
        $this->assertNull($lagging->platform_post_id);
        $this->assertSame('https://www.tiktok.com/@tester', $lagging->meta['url']);
    }

    public function test_creator_info_remembers_the_handle_on_the_account(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, ['metadata' => []]);
        Http::fake(['*/post/publish/creator_info/query/' => Http::response(['data' => ['creator_username' => 'realhandle', 'privacy_level_options' => []], 'error' => ['code' => 'ok']], 200)]);

        app(TikTokPublishService::class)->creatorInfo($account);

        $this->assertSame('realhandle', $account->refresh()->meta('creator_username'));
    }

    // ---- resolve the id after moderation lag ------------------------

    public function test_resolver_fills_in_the_public_id_and_url_for_published_targets_without_one(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $target = $this->pendingTarget($user, $account, 'p_pub_url~1');
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => now()->subHour(), 'meta' => ['publish_id' => 'p_pub_url~1', 'mode' => 'direct', 'url' => 'https://www.tiktok.com/@tester']])->save();

        Http::fake(['*/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['9001']]], 200)]);

        $this->artisan('posts:resolve-tiktok-ids')->assertSuccessful();

        $target->refresh();
        $this->assertSame('9001', $target->platform_post_id);
        $this->assertSame('https://www.tiktok.com/@tester/photo/9001', $target->meta['url']);
        $this->assertSame('p_pub_url~1', $target->meta['publish_id']);
    }

    public function test_resolver_waits_when_the_id_is_still_missing_and_skips_private_old_and_inbox_targets(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);

        $waiting = $this->pendingTarget($user, $account, 'v_pub_file~1');
        $waiting->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => now()->subMinutes(5)])->save();

        $private = $this->pendingTarget($user, $account, 'v_pub_file~2', ['privacy_level' => 'SELF_ONLY']);
        $private->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => now()->subMinutes(5)])->save();

        $old = $this->pendingTarget($user, $account, 'v_pub_file~3');
        $old->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => now()->subDays(2)])->save();

        $inbox = $this->pendingTarget($user, $account, 'v_inbox_file~4');
        $inbox->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => now()->subMinutes(5), 'meta' => ['publish_id' => 'v_inbox_file~4', 'mode' => 'inbox']])->save();

        Http::fake(['*/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE']], 200)]);

        $this->artisan('posts:resolve-tiktok-ids')->assertSuccessful();

        $this->assertCount(1, $this->requests('/status/fetch/'), 'only the public, recent, direct target is asked about');
        $this->assertSame('v_pub_file~1', json_decode($this->requests('/status/fetch/')->first()->body(), true)['publish_id']);
        $this->assertNull($waiting->refresh()->platform_post_id);
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $waiting->status);
    }

    // ---- the legacy SocialPost path ---------------------------------

    public function test_legacy_provider_points_at_the_posts_api_instead_of_posting_unverified_media_as_self_only(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $account = $this->account($user);
        $post = new SocialPost(['user_id' => $user->id, 'content' => 'Hi', 'media' => [['type' => 'video', 'url' => 'https://r2.example/clip.mp4']]]);

        $result = app(TikTokProvider::class)->publish($account, $post);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('posts API', $result->error);
        Http::assertNothingSent();
    }

    // ---- Owner access ----------

    public function test_a_lapsed_owners_untouched_target_goes_back_to_draft_before_init(): void
    {
        Http::fake();
        $user = User::factory()->expired()->create();
        $target = $this->videoTarget($user, $this->account($user));

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PENDING, $target->status);
        $this->assertSame(Post::STATUS_DRAFT, $target->post->status);
        Http::assertNothingSent();
    }

    public function test_a_target_tiktok_already_holds_still_completes_for_a_lapsed_owner(): void
    {
        // publish_id = point of no return: the video is with TikTok, so we finish
        // tracking it rather than pulling a post back that may already be live.
        $user = User::factory()->expired()->create();
        $target = $this->pendingTarget($user, $this->account($user));
        Http::fake(['*/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['9001']]], 200)]);

        (new PublishToTikTokJob($target->id))->handle(app(TikTokPublishService::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame(Post::STATUS_POSTED, $target->post->status);
    }

    public function test_resolver_skips_targets_of_users_without_access(): void
    {
        $user = User::factory()->expired()->create();
        $target = $this->pendingTarget($user, $this->account($user), 'p_pub_lapsed~1');
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => now()->subHour()])->save();
        Http::fake();

        $this->artisan('posts:resolve-tiktok-ids')->assertSuccessful();

        $this->assertNull($target->refresh()->platform_post_id);
        Http::assertNothingSent();
    }
}
