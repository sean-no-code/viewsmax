<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToVkJob;
use App\Mcp\Tools\CreatePost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\CaptionRules;
use App\Services\Social\MediaRules;
use App\Services\Social\PostPublishDispatcher;
use App\Services\Social\Providers\VkProvider;
use App\Services\Social\SocialConnect;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * VK: VK ID sign-in (PKCE + the device_id the redirect returns), hourly token
 * refresh, and wall posts with text, photos and videos through the VK API.
 */
class VkPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const TOKEN_URL = 'https://id.vk.ru/oauth2/auth';

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'social.platforms.vk.client_id' => 'vk-client',
            'social.platforms.vk.service_token' => 'vk-service',
        ]);
    }

    private function account(array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => 'vk',
            'platform_account_id' => '1001',
            'name' => 'Ivan Petrov',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['device_id' => 'dev-1'],
        ], $overrides));
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function socialPost(string $content, array $media = []): SocialPost
    {
        return new SocialPost(['content' => $content, 'media' => $media]);
    }

    private static function vkError(int $code, string $msg = 'error'): array
    {
        return ['error' => ['error_code' => $code, 'error_msg' => $msg]];
    }

    private static function sentTo(Request $req, string $method): bool
    {
        return $req->url() === 'https://api.vk.ru/method/'.$method;
    }

    // ---------------------------------------------------------------- connect

    public function test_authorization_url_is_vk_id_with_pkce_and_the_wall_scopes(): void
    {
        $built = app(SocialConnect::class)->authorizationUrl($this->user, 'vk', 'https://app.example/oauth/callback');

        parse_str((string) parse_url($built['authorization_url'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://id.vk.ru/authorize?', $built['authorization_url']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('vk-client', $query['client_id']);
        $this->assertSame($built['state'], $query['state']);
        $this->assertGreaterThanOrEqual(32, strlen($built['state']));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('vkid.personal_info wall photos video', $query['scope']);

        $verifier = Cache::get('social_oauth_state:'.$built['state'])['code_verifier'];
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $query['code_challenge']);
    }

    public function test_exchange_endpoint_sends_the_device_id_and_verifier_and_keeps_the_device_id(): void
    {
        Http::fake([
            'id.vk.ru/oauth2/auth' => Http::response([
                'access_token' => 'vk-access', 'refresh_token' => 'vk-refresh', 'id_token' => 'idt',
                'token_type' => 'Bearer', 'expires_in' => 3600, 'user_id' => 1001,
                'state' => 'whatever', 'scope' => 'vkid.personal_info wall photos video',
            ]),
            'id.vk.ru/oauth2/user_info' => Http::response(['user' => [
                'user_id' => '1001', 'first_name' => 'Ivan', 'last_name' => 'Petrov', 'avatar' => 'https://sun.userapi.com/a.jpg',
            ]]),
        ]);

        $state = $this->withHeaders($this->auth($this->user))
            ->getJson('/api/social/vk/auth-url?redirect_uri='.urlencode('https://app.example/oauth/callback'))
            ->assertOk()->json('data.state');
        $verifier = Cache::get('social_oauth_state:'.$state)['code_verifier'];

        $this->withHeaders($this->auth($this->user))->postJson('/api/social/vk/exchange', [
            'code' => 'code-1', 'state' => $state, 'device_id' => 'dev-42',
        ])->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn (Request $req) => $req->url() === self::TOKEN_URL
            && $req['grant_type'] === 'authorization_code'
            && $req['code'] === 'code-1'
            && $req['code_verifier'] === $verifier
            && $req['device_id'] === 'dev-42'
            && $req['redirect_uri'] === 'https://app.example/oauth/callback'
            && $req['client_id'] === 'vk-client'
            && $req['service_token'] === 'vk-service');

        $account = SocialAccount::where('platform', 'vk')->firstOrFail();
        $this->assertSame('1001', $account->platform_account_id);
        $this->assertSame('Ivan Petrov', $account->name);
        $this->assertSame('https://vk.com/id1001', $account->profile_url);
        $this->assertSame('https://sun.userapi.com/a.jpg', $account->avatar_url);
        $this->assertSame('vk-access', $account->access_token);
        $this->assertSame('vk-refresh', $account->refresh_token);
        $this->assertSame('dev-42', $account->meta('device_id'));
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $account->token_expires_at->timestamp, 5);
    }

    public function test_web_connect_callback_forwards_the_device_id(): void
    {
        Http::fake([
            'id.vk.ru/oauth2/auth' => Http::response([
                'access_token' => 'vk-access', 'refresh_token' => 'vk-refresh', 'expires_in' => 3600,
                'user_id' => 1001, 'scope' => 'vkid.personal_info wall',
            ]),
            'id.vk.ru/oauth2/user_info' => Http::response(['user' => ['user_id' => '1001', 'first_name' => 'Ivan', 'last_name' => 'Petrov']]),
        ]);
        $built = app(SocialConnect::class)->authorizationUrl($this->user, 'vk', route('connect.callback', 'vk'));

        $accounts = app(SocialConnect::class)->complete($this->user, 'vk', 'code-1', $built['state'], route('connect.callback', 'vk'), 'dev-web');

        $this->assertSame('dev-web', $accounts->first()->meta('device_id'));
        Http::assertSent(fn (Request $req) => $req->url() === self::TOKEN_URL && $req['device_id'] === 'dev-web');
    }

    public function test_connect_without_a_device_id_fails_before_calling_vk(): void
    {
        Http::fake();
        $built = app(SocialConnect::class)->authorizationUrl($this->user, 'vk', 'https://app.example/oauth/callback');

        $this->withHeaders($this->auth($this->user))->postJson('/api/social/vk/exchange', [
            'code' => 'code-1', 'state' => $built['state'],
        ])->assertStatus(422)->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertSame(0, SocialAccount::where('platform', 'vk')->count());
    }

    public function test_connect_is_refused_when_vk_did_not_grant_wall_access(): void
    {
        Http::fake([
            'id.vk.ru/oauth2/auth' => Http::response([
                'access_token' => 'vk-access', 'refresh_token' => 'vk-refresh', 'expires_in' => 3600,
                'user_id' => 1001, 'scope' => 'vkid.personal_info',
            ]),
        ]);

        $this->expectExceptionMessage('missing wall');
        (new VkProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/cb', ['device_id' => 'dev-1', 'code_verifier' => str_repeat('v', 64)]);
    }

    public function test_a_refused_code_exchange_reports_vk_s_reason(): void
    {
        Http::fake(['id.vk.ru/oauth2/auth' => Http::response(['error' => 'invalid_grant', 'error_description' => 'code expired'], 400)]);

        $this->expectExceptionMessage('code expired');
        (new VkProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/cb', ['device_id' => 'dev-1', 'code_verifier' => str_repeat('v', 64)]);
    }

    public function test_vk_counts_as_configured_only_with_a_client_id_and_service_token(): void
    {
        $manager = app(SocialProviderManager::class);
        $this->assertTrue($manager->isConfigured('vk'));

        config(['social.platforms.vk.service_token' => null]);
        $this->assertFalse($manager->isConfigured('vk'));
        $this->assertNotContains('vk', CreatePost::availablePlatforms($this->user));

        config(['social.platforms.vk.service_token' => 'vk-service', 'social.platforms.vk.enabled' => false]);
        $this->assertFalse($manager->isConfigured('vk'));
    }

    // ---------------------------------------------------------------- tokens

    public function test_expired_token_refreshes_with_the_stored_device_id(): void
    {
        Http::fake(['id.vk.ru/oauth2/auth' => Http::response([
            'access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'user_id' => 1001,
        ])]);

        $account = (new VkProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->subMinute()]));

        $this->assertSame('new-token', $account->access_token);
        $this->assertSame('new-refresh', $account->refresh_token);
        $this->assertSame('dev-1', $account->meta('device_id'));
        Http::assertSent(fn (Request $req) => $req->url() === self::TOKEN_URL
            && $req['grant_type'] === 'refresh_token'
            && $req['refresh_token'] === 'refresh'
            && $req['device_id'] === 'dev-1'
            && $req['client_id'] === 'vk-client'
            && $req['service_token'] === 'vk-service'
            && strlen((string) $req['state']) >= 32);
    }

    public function test_token_in_its_last_minutes_is_refreshed_early(): void
    {
        Http::fake(['id.vk.ru/oauth2/auth' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 3600])]);

        $account = (new VkProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->addMinutes(3)]));

        $this->assertSame('new-token', $account->access_token);
    }

    public function test_token_with_time_left_is_not_refreshed(): void
    {
        Http::fake();

        $account = (new VkProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->addMinutes(40)]));

        $this->assertSame('token', $account->access_token);
        Http::assertNothingSent();
    }

    public function test_refused_refresh_of_an_expired_token_marks_the_account_for_reconnect(): void
    {
        Http::fake(['id.vk.ru/oauth2/auth' => Http::response(['error' => 'invalid_grant', 'error_description' => 'refresh token expired'], 400)]);

        $account = (new VkProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->subMinute()]));

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_refused_early_refresh_keeps_a_still_valid_account_connected(): void
    {
        Http::fake(['id.vk.ru/oauth2/auth' => Http::response(['error' => 'invalid_grant'], 400)]);

        (new VkProvider)->ensureFreshToken($account = $this->account(['token_expires_at' => now()->addMinutes(3)]));

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_vk_outage_during_refresh_does_not_mark_the_account_for_reconnect(): void
    {
        Http::fake(['id.vk.ru/oauth2/auth' => Http::response(['error' => 'temporarily_unavailable'], 503)]);

        (new VkProvider)->ensureFreshToken($account = $this->account(['token_expires_at' => now()->subMinute()]));

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_an_expired_account_without_a_device_id_must_reconnect(): void
    {
        Http::fake();

        (new VkProvider)->ensureFreshToken($account = $this->account(['token_expires_at' => now()->subMinute(), 'metadata' => []]));

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- publish

    public function test_text_post_goes_to_the_users_own_wall(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::response(['response' => ['post_id' => 55]])]);

        $result = (new VkProvider)->publish($this->account(), $this->socialPost("Hello VK\nSecond line"));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('55', $result->remotePostId);
        $this->assertSame('https://vk.com/wall1001_55', $result->remotePostUrl);
        Http::assertSent(fn (Request $req) => self::sentTo($req, 'wall.post')
            && $req->hasHeader('Authorization', 'Bearer token')
            && $req['owner_id'] === '1001'
            && $req['message'] === "Hello VK\nSecond line"
            && $req['v'] === '5.199'
            && ! isset($req['attachments']));
    }

    public function test_several_images_are_uploaded_and_attached_in_order(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
            'api.vk.ru/method/photos.getWallUploadServer' => Http::response(['response' => [
                'upload_url' => 'https://pu.vk.example/upload', 'album_id' => -14, 'user_id' => 1001,
            ]]),
            'pu.vk.example/*' => Http::sequence()
                ->push(['server' => 11, 'photo' => '[{"photo":"a"}]', 'hash' => 'h1'])
                ->push(['server' => 12, 'photo' => '[{"photo":"b"}]', 'hash' => 'h2']),
            'api.vk.ru/method/photos.saveWallPhoto' => Http::sequence()
                ->push(['response' => [['id' => 501, 'owner_id' => 1001, 'access_key' => 'k1']]])
                ->push(['response' => [['id' => 502, 'owner_id' => 1001, 'access_key' => 'k2']]]),
            'api.vk.ru/method/wall.post' => Http::response(['response' => ['post_id' => 56]]),
        ]);

        $result = (new VkProvider)->publish($this->account(), $this->socialPost('Two photos', [
            ['type' => 'image', 'url' => 'https://cdn.example/one.png'],
            ['type' => 'image', 'url' => 'https://cdn.example/two.png'],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $req) => $req->url() === 'https://pu.vk.example/upload'
            && $req->isMultipart() && str_contains($req->body(), 'name="photo"; filename="photo.png"'));
        Http::assertSent(fn (Request $req) => self::sentTo($req, 'photos.saveWallPhoto')
            && (string) $req['server'] === '11' && $req['hash'] === 'h1' && $req['photo'] === '[{"photo":"a"}]');
        Http::assertSent(fn (Request $req) => self::sentTo($req, 'wall.post')
            && $req['attachments'] === 'photo1001_501,photo1001_502'
            && $req['message'] === 'Two photos');
    }

    public function test_video_is_uploaded_then_attached(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('video-bytes', 200, ['Content-Type' => 'video/mp4']),
            'api.vk.ru/method/video.save' => Http::response(['response' => [
                'upload_url' => 'https://vu.vk.example/upload', 'video_id' => 77, 'owner_id' => 1001, 'access_key' => 'ak',
            ]]),
            'vu.vk.example/*' => Http::response(['video_hash' => 'vh', 'size' => 11, 'owner_id' => 1001, 'video_id' => 77]),
            'api.vk.ru/method/wall.post' => Http::response(['response' => ['post_id' => 57]]),
        ]);

        $result = (new VkProvider)->publish($this->account(), $this->socialPost('', [
            ['type' => 'video', 'url' => 'https://cdn.example/clip.mp4'],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $req) => self::sentTo($req, 'video.save') && (string) $req['wallpost'] === '0');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://vu.vk.example/upload'
            && $req->isMultipart() && str_contains($req->body(), 'name="video_file"'));
        Http::assertSent(fn (Request $req) => self::sentTo($req, 'wall.post')
            && $req['attachments'] === 'video1001_77' && ! isset($req['message']));
    }

    public function test_at_most_ten_attachments_are_sent(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'api.vk.ru/method/photos.getWallUploadServer' => Http::response(['response' => ['upload_url' => 'https://pu.vk.example/upload']]),
            'pu.vk.example/*' => Http::response(['server' => 1, 'photo' => '[{}]', 'hash' => 'h']),
            'api.vk.ru/method/photos.saveWallPhoto' => Http::response(['response' => [['id' => 9, 'owner_id' => 1001]]]),
            'api.vk.ru/method/wall.post' => Http::response(['response' => ['post_id' => 58]]),
        ]);
        $media = array_map(fn ($i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.jpg"], range(1, 12));

        $this->assertTrue((new VkProvider)->publish($this->account(), $this->socialPost('Many', $media))->success);

        Http::assertSentCount(1 + 10 * 4); // wall.post + 10 × (download, server, upload, save)
    }

    public function test_an_empty_post_fails_without_calling_vk(): void
    {
        Http::fake();

        $result = (new VkProvider)->publish($this->account(), $this->socialPost('   '));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_media_links_to_internal_addresses_are_refused(): void
    {
        Http::fake(['169.254.169.254/*' => Http::response('SECRET')]);

        $result = (new VkProvider)->publish($this->account(), $this->socialPost('x', [
            ['type' => 'image', 'url' => 'http://169.254.169.254/latest/meta-data'],
        ]));

        $this->assertFalse($result->success);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), '169.254.169.254'));
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'api.vk.ru'));
    }

    public function test_an_upload_vk_rejects_gets_a_clear_error(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('not-really-an-image', 200, ['Content-Type' => 'image/jpeg']),
            'api.vk.ru/method/photos.getWallUploadServer' => Http::response(['response' => ['upload_url' => 'https://pu.vk.example/upload']]),
            'pu.vk.example/*' => Http::response(['server' => 1, 'photo' => '[]', 'hash' => 'h']),
        ]);

        $result = (new VkProvider)->publish($this->account(), $this->socialPost('x', [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('did not accept the image', (string) $result->error);
        Http::assertNotSent(fn (Request $req) => self::sentTo($req, 'wall.post'));
    }

    // ---------------------------------------------------------------- errors

    public function test_vk_errors_get_distinct_messages(): void
    {
        $cases = [
            7 => 'refused permission to post on this wall',
            15 => 'refused permission to post on this wall',
            9 => 'flood control',
            29 => 'daily limit',
            14 => 'captcha',
            17 => 'confirm your account',
            18 => 'deleted or blocked',
            214 => 'does not allow posting on this wall',
            219 => 'advertising',
            222 => 'contains links',
            100 => 'One of the parameters specified was missing or invalid (error 100)',
        ];

        $code = 0;
        Http::fake(['api.vk.ru/method/wall.post' => function () use (&$code) {
            return Http::response(self::vkError($code, 'One of the parameters specified was missing or invalid'));
        }]);

        foreach ($cases as $code => $expected) {
            $result = (new VkProvider)->publish($account = $this->account(['platform_account_id' => 'u'.$code]), $this->socialPost('Hi'));

            $this->assertFalse($result->success, "code {$code}");
            $this->assertStringContainsString($expected, (string) $result->error, "code {$code}");
            $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status, "code {$code}");
        }
    }

    public function test_permission_refused_on_a_photo_upload_names_photos(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'api.vk.ru/method/photos.getWallUploadServer' => Http::response(self::vkError(15, 'Access denied: no access to call this method')),
        ]);

        $result = (new VkProvider)->publish($this->account(), $this->socialPost('x', [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));

        $this->assertStringContainsString('refused permission to upload photos', (string) $result->error);
    }

    public function test_a_revoked_token_marks_the_account_for_reconnect(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::response(self::vkError(5, 'User authorization failed: invalid access_token'))]);

        $result = (new VkProvider)->publish($account = $this->account(), $this->socialPost('Hi'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('reconnect', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_a_per_second_rate_limit_is_waited_out_once(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::sequence()
            ->push(self::vkError(6, 'Too many requests per second'))
            ->push(['response' => ['post_id' => 59]])]);

        $result = (new VkProvider)->publish($this->account(), $this->socialPost('Hi'));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSentCount(2);
    }

    public function test_a_lasting_rate_limit_is_left_to_the_queue_to_retry(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::response(self::vkError(6, 'Too many requests per second'))]);

        $this->expectException(TransientPublishException::class);
        (new VkProvider)->publish($this->account(), $this->socialPost('Hi'));
    }

    public function test_vk_server_trouble_is_left_to_the_queue_to_retry(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::response('Bad gateway', 502)]);

        $this->expectException(TransientPublishException::class);
        (new VkProvider)->publish($this->account(), $this->socialPost('Hi'));
    }

    // ---------------------------------------------------------------- job + rules

    private function target(SocialAccount $account, array $media = []): PostTarget
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'From the composer',
            'media' => $media,
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'vk',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHING,
        ]);
    }

    public function test_job_publishes_the_target_and_stores_the_post_link(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::response(['response' => ['post_id' => 60]])]);
        $target = $this->target($this->account());

        (new PublishToVkJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status);
        $this->assertSame('60', $target->platform_post_id);
        $this->assertSame('https://vk.com/wall1001_60', $target->meta['url']);
        Http::assertSent(fn (Request $req) => self::sentTo($req, 'wall.post') && $req['message'] === 'From the composer');
    }

    public function test_job_marks_the_target_failed_with_vk_s_reason(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::response(self::vkError(214, 'Access to adding post denied'))]);
        $target = $this->target($this->account());

        (new PublishToVkJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('does not allow posting on this wall', (string) $target->error);
    }

    public function test_job_fails_the_target_when_the_account_must_reconnect(): void
    {
        Http::fake(['id.vk.ru/oauth2/auth' => Http::response(['error' => 'invalid_grant'], 400)]);
        $target = $this->target($this->account(['token_expires_at' => now()->subMinute()]));

        (new PublishToVkJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('reconnect', (string) $target->error);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'api.vk.ru'));
    }

    public function test_job_lets_the_queue_retry_a_rate_limit(): void
    {
        Http::fake(['api.vk.ru/method/wall.post' => Http::response(self::vkError(6, 'Too many requests per second'))]);
        $target = $this->target($this->account());

        try {
            (new PublishToVkJob($target->id))->handle(app(SocialProviderManager::class));
            $this->fail('Expected a retry');
        } catch (TransientPublishException) {
        }

        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->fresh()->status);
    }

    public function test_dispatcher_routes_vk_targets_to_the_vk_job(): void
    {
        Queue::fake();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $post->targets()->create(['platform' => 'vk', 'status' => PostTarget::STATUS_PENDING]);

        PostPublishDispatcher::dispatch($post);

        Queue::assertPushed(PublishToVkJob::class);
    }

    public function test_publishing_a_text_post_to_vk_through_the_api_queues_the_job(): void
    {
        Queue::fake();
        $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Text only',
            'platforms' => ['vk'],
            'status' => Post::STATUS_POSTED,
        ])->assertCreated();

        Queue::assertPushed(PublishToVkJob::class);
    }

    public function test_more_than_ten_media_items_are_refused_for_vk(): void
    {
        $media = array_map(fn ($i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.jpg"], range(1, 11));

        $this->assertStringContainsString('at most 10', (string) MediaRules::error('vk', $media));
        $this->assertNull(MediaRules::error('vk', array_slice($media, 0, 9) + [9 => ['type' => 'video', 'url' => 'https://cdn.example/v.mp4']]));
    }

    public function test_caption_limit_and_labels(): void
    {
        $this->assertSame(15895, CaptionRules::limit('vk'));
        $this->assertSame(15895, CreatePost::PLATFORM_CHAR_LIMITS['vk']);
        $this->assertStringContainsString("VK's 15895 limit", (string) CaptionRules::captionError('vk', str_repeat('a', 15896)));
        $this->assertContains('vk', CreatePost::availablePlatforms($this->user));
    }
}
