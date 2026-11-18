<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToDiscordJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Discord\RateLimitedException;
use App\Services\Social\Providers\DiscordProvider;
use App\Services\Social\SocialProviderManager;
use App\Services\UserErasure;
use App\Support\SentryScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Breadcrumb;
use Tests\TestCase;

/**
 * Discord publishing through an incoming webhook: connecting (OAuth with the
 * webhook.incoming scope) stores one channel's webhook, and every post is one
 * execute-webhook call. The webhook token is a secret, so it must never reach
 * logs, errors or API responses.
 */
class DiscordPublishTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'wh-secret-TOKEN_abc.123';

    private const EXECUTE = 'https://discord.com/api/v10/webhooks/111/'.self::TOKEN.'?wait=true';

    /** A real 1x1 PNG, so the file type is detected from the bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'social.platforms.discord.client_id' => 'discord-client',
            'social.platforms.discord.client_secret' => 'discord-secret',
        ]);
    }

    private function account(array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => 'discord',
            'platform_account_id' => '333',
            'name' => 'Creators · channel …333',
            'access_token' => self::TOKEN,
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['webhook_id' => '111', 'channel_id' => '333', 'guild_id' => '222', 'guild_name' => 'Creators'],
        ], $overrides));
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function tokenResponse(array $webhookOverrides = []): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => 'oauth-access',
            'scope' => 'webhook.incoming guilds',
            'expires_in' => 604800,
            'refresh_token' => 'oauth-refresh',
            'webhook' => array_merge([
                'application_id' => '999',
                'name' => 'ViewsMax',
                'url' => 'https://discord.com/api/webhooks/111/'.self::TOKEN,
                'channel_id' => '333',
                'token' => self::TOKEN,
                'type' => 1,
                'avatar' => null,
                'guild_id' => '222',
                'id' => '111',
            ], $webhookOverrides),
        ];
    }

    private function fakeConnect(array $tokenResponse): void
    {
        Http::fake([
            'discord.com/api/oauth2/token' => Http::response($tokenResponse),
            'discord.com/api/v10/users/@me/guilds' => Http::response([
                ['id' => '1', 'name' => 'Other server', 'icon' => null],
                ['id' => '222', 'name' => 'Creators', 'icon' => 'abc123'],
            ]),
            'discord.com/api/v10/webhooks/*' => Http::response(null, 204),
        ]);
    }

    private function publish(array $post, ?SocialAccount $account = null, ?DiscordProvider $provider = null)
    {
        return ($provider ?? new DiscordProvider)->publish($account ?? $this->account(), new SocialPost($post));
    }

    private function messageResponse(string $id = '444'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['id' => $id, 'channel_id' => '333', 'webhook_id' => '111', 'content' => 'x'], 200);
    }

    // ---------------------------------------------------------------- connect

    public function test_authorization_url_asks_discord_for_a_channel_webhook(): void
    {
        $url = (new DiscordProvider)->getAuthorizationUrl('https://app.example/social/callback', 'state-1');

        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('discord-client', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('webhook.incoming guilds', $query['scope']);
        $this->assertSame('state-1', $query['state']);
        $this->assertSame('https://app.example/social/callback', $query['redirect_uri']);
    }

    public function test_connect_stores_the_channel_webhook_with_the_token_encrypted_only(): void
    {
        $this->fakeConnect($this->tokenResponse());

        $response = $this->withHeaders($this->auth($this->user))
            ->postJson('/api/social/discord/exchange', ['code' => 'code-1', 'redirect_uri' => 'https://app.example/social/callback'])
            ->assertOk();

        // The token never comes back in the API response.
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());

        $account = SocialAccount::where('platform', 'discord')->sole();
        $this->assertSame('333', $account->platform_account_id);
        $this->assertSame('Creators · channel …333', $account->name);
        $this->assertSame('https://cdn.discordapp.com/icons/222/abc123.png', $account->avatar_url);
        $this->assertSame('https://discord.com/channels/222/333', $account->profile_url);
        $this->assertSame(self::TOKEN, $account->access_token);
        $this->assertNull($account->token_expires_at);
        $this->assertTrue($account->hasUsableCredentials());
        $this->assertSame(['webhook_id' => '111', 'channel_id' => '333', 'guild_id' => '222', 'guild_name' => 'Creators'], $account->metadata);

        // Stored encrypted, and nowhere else in the row (not the webhook url either).
        $raw = (array) DB::table('social_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString(self::TOKEN, json_encode($raw));

        Http::assertSent(fn (Request $req) => $req->url() === 'https://discord.com/api/oauth2/token'
            && $req['grant_type'] === 'authorization_code'
            && $req['code'] === 'code-1'
            && $req['redirect_uri'] === 'https://app.example/social/callback');
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/users/@me/guilds')
            && $req->hasHeader('Authorization', 'Bearer oauth-access'));
    }

    public function test_a_token_response_without_a_webhook_is_refused_with_a_clear_message(): void
    {
        $tokens = $this->tokenResponse();
        unset($tokens['webhook']);
        $this->fakeConnect($tokens);

        try {
            (new DiscordProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/cb');
            $this->fail('Expected the connect to be refused.');
        } catch (\App\Services\Social\SocialConnectException $e) {
            $this->assertStringContainsString("Discord didn't return a channel to post to", $e->getMessage());
        }

        $this->withHeaders($this->auth($this->user))
            ->postJson('/api/social/discord/exchange', ['code' => 'code-1'])
            ->assertStatus(422);

        $this->assertSame(0, SocialAccount::where('platform', 'discord')->count());
    }

    public function test_a_server_name_that_cannot_be_read_still_connects_the_channel(): void
    {
        Http::fake([
            'discord.com/api/oauth2/token' => Http::response($this->tokenResponse()),
            'discord.com/api/v10/users/@me/guilds' => Http::response(['message' => '401: Unauthorized', 'code' => 0], 401),
        ]);

        $account = (new DiscordProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/cb')->first();

        $this->assertSame('Discord server · channel …333', $account->name);
        $this->assertNull($account->avatar_url);
    }

    public function test_reconnecting_a_channel_replaces_its_webhook_and_deletes_the_old_one(): void
    {
        $old = $this->account(['access_token' => 'old-token', 'metadata' => ['webhook_id' => '100', 'channel_id' => '333', 'guild_id' => '222'],
            'status' => SocialAccount::STATUS_NEEDS_REAUTH, 'last_error' => 'gone']);
        $this->fakeConnect($this->tokenResponse());

        (new DiscordProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/cb');

        $old->refresh();
        $this->assertSame(1, SocialAccount::where('platform', 'discord')->count());
        $this->assertSame(self::TOKEN, $old->access_token);
        $this->assertSame('111', $old->meta('webhook_id'));
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $old->status);
        $this->assertNull($old->last_error);
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE'
            && $req->url() === 'https://discord.com/api/v10/webhooks/100/old-token');
    }

    public function test_a_second_channel_is_a_second_account(): void
    {
        $this->account(['platform_account_id' => '555', 'metadata' => ['webhook_id' => '50', 'channel_id' => '555', 'guild_id' => '222']]);
        $this->fakeConnect($this->tokenResponse());

        (new DiscordProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/cb');

        $this->assertSame(2, SocialAccount::where('platform', 'discord')->count());
        Http::assertNotSent(fn (Request $req) => $req->method() === 'DELETE');
    }

    public function test_discord_is_off_until_its_keys_are_set(): void
    {
        config(['social.platforms.discord.client_id' => null]);

        $this->assertFalse(app(SocialProviderManager::class)->isConfigured('discord'));
        $this->withHeaders($this->auth($this->user))->getJson('/api/social/discord/auth-url')->assertStatus(503);
    }

    // ---------------------------------------------------------------- publish

    public function test_text_post_is_one_execute_call_with_mass_mentions_suppressed(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => $this->messageResponse()]);

        $result = $this->publish(['content' => "  @everyone @here big news <@&42> and <@7>  "]);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('444', $result->remotePostId);
        $this->assertSame('https://discord.com/channels/222/333/444', $result->remotePostUrl);
        Http::assertSent(fn (Request $req) => $req->method() === 'POST'
            && $req->url() === self::EXECUTE
            && $req['content'] === '@everyone @here big news <@&42> and <@7>'
            // Only a deliberate user mention may ping: no @everyone/@here/roles.
            && $req['allowed_mentions'] === ['parse' => ['users']]
            && ! isset($req['attachments']));
        Http::assertSentCount(1);
    }

    public function test_images_and_video_upload_as_files_in_one_message(): void
    {
        Http::fake([
            'cdn.example/photo.png' => Http::response(base64_decode(self::PNG)),
            'cdn.example/no-extension' => Http::response(base64_decode(self::PNG)),
            'cdn.example/clip.mp4' => Http::response('not-really-a-video'),
            'discord.com/api/v10/webhooks/*' => $this->messageResponse(),
        ]);

        $result = $this->publish([
            'content' => 'Gallery',
            'media' => [
                ['type' => 'image', 'url' => 'https://cdn.example/photo.png', 'alt' => 'A tiny square'],
                ['type' => 'image', 'url' => 'https://cdn.example/no-extension'],
                ['type' => 'video', 'url' => 'https://cdn.example/clip.mp4'],
            ],
        ]);

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(function (Request $req) {
            if ($req->url() !== self::EXECUTE || ! $req->isMultipart()) {
                return false;
            }
            $parts = collect($req->data())->keyBy('name');
            $payload = json_decode($parts['payload_json']['contents'], true);

            return $parts->has('files[0]') && $parts->has('files[1]') && $parts->has('files[2]')
                && $parts['files[0]']['filename'] === '1-photo.png'
                && $parts['files[1]']['filename'] === '2-no-extension.png'
                && $parts['files[2]']['filename'] === '3-clip.mp4'
                && $payload['content'] === 'Gallery'
                && $payload['allowed_mentions'] === ['parse' => ['users']]
                && $payload['attachments'] === [
                    ['id' => 0, 'filename' => '1-photo.png', 'description' => 'A tiny square'],
                    ['id' => 1, 'filename' => '2-no-extension.png'],
                    ['id' => 2, 'filename' => '3-clip.mp4'],
                ];
        });
    }

    public function test_media_only_post_sends_no_content(): void
    {
        Http::fake([
            'cdn.example/photo.png' => Http::response(base64_decode(self::PNG)),
            'discord.com/api/v10/webhooks/*' => $this->messageResponse(),
        ]);

        $this->assertTrue($this->publish(['content' => '', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/photo.png']]])->success);
        Http::assertSent(function (Request $req) {
            if (! str_starts_with($req->url(), 'https://discord.com/')) {
                return false;
            }
            $payload = json_decode(collect($req->data())->firstWhere('name', 'payload_json')['contents'], true);

            return ! array_key_exists('content', $payload);
        });
    }

    public function test_a_file_over_the_per_file_limit_fails_naming_the_limit_without_posting(): void
    {
        Http::fake([
            'cdn.example/big.png' => Http::response(str_repeat('a', 2048)),
            'discord.com/api/v10/webhooks/*' => $this->messageResponse(),
        ]);
        $provider = new class extends DiscordProvider
        {
            public const MAX_FILE_BYTES = 1024 * 1024 / 1024; // 1 KiB, so the test needs no 20 MB body
        };

        $result = $this->publish(['content' => 'Big', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/big.png']]], provider: $provider);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('File 1 is too large for Discord', (string) $result->error);
        $this->assertStringContainsString('MB each', (string) $result->error);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'discord.com'));
    }

    public function test_files_over_the_per_message_limit_together_fail_without_posting(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response(str_repeat('a', 700)),
            'discord.com/api/v10/webhooks/*' => $this->messageResponse(),
        ]);
        $provider = new class extends DiscordProvider
        {
            public const MAX_MESSAGE_BYTES = 1024;
        };

        $result = $this->publish(['content' => 'Two', 'media' => [
            ['type' => 'image', 'url' => 'https://cdn.example/a.png'],
            ['type' => 'image', 'url' => 'https://cdn.example/b.png'],
        ]], provider: $provider);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('too large together for one Discord message', (string) $result->error);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'discord.com'));
    }

    public function test_the_real_limits_are_discords_documented_numbers(): void
    {
        $this->assertSame(20 * 1024 * 1024, DiscordProvider::MAX_FILE_BYTES);
        $this->assertSame(25 * 1024 * 1024, DiscordProvider::MAX_MESSAGE_BYTES);
        $this->assertSame(10, DiscordProvider::MAX_FILES);
        $this->assertSame('Discord takes files up to 20 MB each and 25 MB per message.', DiscordProvider::limitsSentence());
    }

    public function test_more_than_ten_files_fail_before_any_download(): void
    {
        Http::fake();
        $media = array_fill(0, 11, ['type' => 'image', 'url' => 'https://cdn.example/a.png']);

        $result = $this->publish(['content' => 'x', 'media' => $media]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('at most 10 files', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_media_links_to_internal_addresses_are_refused(): void
    {
        Http::fake(['169.254.169.254/*' => Http::response('SECRET'), 'discord.com/*' => $this->messageResponse()]);

        $result = $this->publish(['content' => 'x', 'media' => [['type' => 'image', 'url' => 'http://169.254.169.254/latest/meta-data']]]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('could not be downloaded', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_an_over_long_or_empty_message_fails_without_calling_discord(): void
    {
        Http::fake();
        $account = $this->account();

        $this->assertStringContainsString('2000 characters', (string) $this->publish(['content' => str_repeat('a', 2001)], $account)->error);
        $this->assertStringContainsString('needs text or at least one image', (string) $this->publish(['content' => '   '], $account)->error);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- errors

    public function test_a_deleted_webhook_flags_the_account_for_reconnect(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);
        $account = $this->account();

        $result = $this->publish(['content' => 'hi'], $account);

        $this->assertFalse($result->success);
        $this->assertSame(DiscordProvider::WEBHOOK_GONE, $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        $this->assertSame(DiscordProvider::WEBHOOK_GONE, $account->fresh()->last_error);
    }

    public function test_an_invalid_webhook_token_also_needs_a_reconnect(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(['message' => 'Invalid Webhook Token', 'code' => 50027], 401)]);
        $account = $this->account();

        $this->assertSame(DiscordProvider::WEBHOOK_GONE, $this->publish(['content' => 'hi'], $account)->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    /**
     * @return array<string, array{0:int, 1:array, 2:string}>
     */
    public static function refusals(): array
    {
        return [
            'forum channel' => [400, ['message' => 'Webhooks posted to forum channels must have a thread_name or thread_id', 'code' => 220001], 'forum or media channel'],
            'missing permissions' => [403, ['message' => 'Missing Permissions', 'code' => 50013], "isn't allowed to post in this channel"],
            'file too large' => [413, ['message' => 'Request entity too large', 'code' => 40005], 'too large'],
            'automod' => [400, ['message' => 'Message was blocked by automatic moderation', 'code' => 200000], "Discord's moderation blocked this message"],
            'other' => [400, ['message' => 'Invalid Form Body', 'code' => 50035], 'Discord refused the post (HTTP 400: Invalid Form Body)'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_refusals_map_to_distinct_messages_and_keep_the_account_connected(int $status, array $body, string $expected): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response($body, $status)]);
        $account = $this->account();

        $result = $this->publish(['content' => 'hi'], $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString($expected, (string) $result->error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_a_rate_limit_is_rethrown_with_discords_retry_after(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(['message' => 'You are being rate limited.', 'retry_after' => 4.2, 'global' => false], 429)]);

        try {
            $this->publish(['content' => 'hi']);
            $this->fail('Expected a rate-limit exception.');
        } catch (RateLimitedException $e) {
            $this->assertSame(4.2, $e->retryAfter);
            $this->assertStringContainsString('retrying in 5 seconds', $e->getMessage());
        }
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response('upstream', 502)]);

        $this->expectException(TransientPublishException::class);
        $this->publish(['content' => 'hi']);
    }

    public function test_a_network_failure_is_retried_without_the_token_in_the_error(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::failedConnection('cURL error 28 for '.self::EXECUTE)]);

        try {
            $this->publish(['content' => 'hi']);
            $this->fail('Expected a transient exception.');
        } catch (TransientPublishException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function test_redact_takes_the_token_out_of_webhook_urls(): void
    {
        $this->assertSame(
            'POST https://discord.com/api/v10/webhooks/111/[redacted]?wait=true',
            DiscordProvider::redact('POST '.self::EXECUTE),
        );
    }

    public function test_sentry_http_breadcrumbs_lose_the_webhook_token(): void
    {
        $crumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, [
            'url' => 'https://discord.com/api/v10/webhooks/111/'.self::TOKEN,
            'http.request.method' => 'POST',
        ]);

        $clean = SentryScrubber::breadcrumb($crumb);

        $this->assertSame('https://discord.com/api/v10/webhooks/111/[redacted]', $clean->getMetadata()['url']);
        $this->assertSame('POST', $clean->getMetadata()['http.request.method']);
        $this->assertSame([SentryScrubber::class, 'breadcrumb'], config('sentry.before_breadcrumb'));
        $this->assertSame([SentryScrubber::class, 'transaction'], config('sentry.before_send_transaction'));
    }

    // ---------------------------------------------------------------- job

    private function targetFor(?SocialAccount $account, string $caption = 'From the composer', array $media = []): PostTarget
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => $caption,
            'media' => $media,
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'discord',
            'social_account_id' => $account?->id,
            'status' => PostTarget::STATUS_PUBLISHING,
        ]);
    }

    private function runJob(PostTarget $target): PublishToDiscordJob
    {
        $job = (new PublishToDiscordJob($target->id))->withFakeQueueInteractions();
        $job->handle(app(SocialProviderManager::class));

        return $job;
    }

    public function test_job_publishes_the_target_and_records_the_message(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => $this->messageResponse('777')]);

        $target = $this->targetFor($this->account());
        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status);
        $this->assertSame('777', $target->platform_post_id);
        $this->assertSame('https://discord.com/channels/222/333/777', $target->meta['url']);
        Http::assertSent(fn (Request $req) => $req['content'] === 'From the composer');
    }

    public function test_job_fails_the_target_for_a_deleted_webhook_and_never_calls_it_again(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);
        $account = $this->account();

        $first = $this->targetFor($account);
        $this->runJob($first);
        $this->assertSame(PostTarget::STATUS_FAILED, $first->fresh()->status);
        $this->assertSame(DiscordProvider::WEBHOOK_GONE, $first->fresh()->error);

        $second = $this->targetFor($account);
        $this->runJob($second);
        $this->assertSame(PostTarget::STATUS_FAILED, $second->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_job_comes_back_when_discord_says_after_a_rate_limit(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(['message' => 'You are being rate limited.', 'retry_after' => 2.5, 'global' => false], 429)]);

        $target = $this->targetFor($this->account());
        $job = $this->runJob($target);

        $job->assertReleased(4);
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->fresh()->status);
    }

    public function test_job_lets_a_server_error_through_for_the_queue_to_retry(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response('upstream', 503)]);
        $target = $this->targetFor($this->account());

        $this->expectException(TransientPublishException::class);
        $this->runJob($target);
    }

    public function test_job_with_no_account_chosen_and_several_channels_fails_instead_of_guessing(): void
    {
        Http::fake();
        $this->account();
        $this->account(['platform_account_id' => '555', 'metadata' => ['webhook_id' => '50', 'channel_id' => '555', 'guild_id' => '222']]);

        $target = $this->targetFor(null);
        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringContainsString('choose which Discord channel', $target->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_job_with_no_account_chosen_and_one_channel_posts_there(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => $this->messageResponse()]);
        $this->account();

        $target = $this->targetFor(null);
        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status);
    }

    // ---------------------------------------------------------------- composer / API rules

    public function test_caption_limit_is_discords_2000_characters(): void
    {
        $this->assertSame(2000, \App\Services\Social\CaptionRules::limit('discord'));
        $this->assertSame(2000, \App\Mcp\Tools\CreatePost::PLATFORM_CHAR_LIMITS['discord']);
    }

    public function test_the_ai_tools_offer_discord_with_its_rules_only_once_it_is_set_up(): void
    {
        $this->assertContains('discord', \App\Mcp\Tools\CreatePost::availablePlatforms());
        $this->assertStringContainsString('@everyone, @here and role mentions', \App\Mcp\Tools\CreatePost::discordRule());

        config(['social.platforms.discord.client_secret' => null]);
        $this->assertNotContains('discord', \App\Mcp\Tools\CreatePost::availablePlatforms());
        $this->assertSame('', \App\Mcp\Tools\CreatePost::discordRule());
    }

    public function test_publishing_to_one_of_several_channels_requires_choosing_it(): void
    {
        Queue::fake();
        $first = $this->account();
        $second = $this->account(['platform_account_id' => '555', 'metadata' => ['webhook_id' => '50', 'channel_id' => '555', 'guild_id' => '222']]);

        $base = ['caption' => 'Hello', 'status' => Post::STATUS_POSTED];

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $base + ['platforms' => ['discord']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Several Discord channels are connected. Choose which Discord channel(s) to post to.');

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $base + ['targets' => [
            ['platform' => 'discord', 'social_account_id' => $second->id],
        ]])->assertCreated();

        Queue::assertPushed(PublishToDiscordJob::class);
        $this->assertNotNull($first);
    }

    public function test_an_empty_discord_message_cannot_be_published(): void
    {
        Queue::fake();
        $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => '', 'platforms' => ['discord'], 'status' => Post::STATUS_POSTED,
        ])->assertStatus(422)->assertJsonPath('message', 'Discord needs text or an image or video before publishing or scheduling.');

        // A draft may still be empty.
        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => '', 'platforms' => ['discord'], 'status' => Post::STATUS_DRAFT,
        ])->assertCreated();
    }

    public function test_more_than_ten_media_items_are_refused_at_schedule_time(): void
    {
        Queue::fake();
        $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'x',
            'platforms' => ['discord'],
            'status' => Post::STATUS_POSTED,
            'media' => array_fill(0, 11, ['type' => 'image', 'url' => 'https://cdn.example/a.png']),
        ])->assertStatus(422)->assertJsonPath('message', 'Discord allows at most 10 media items per post.');
    }

    // ---------------------------------------------------------------- disconnect

    public function test_disconnecting_deletes_the_webhook_in_discord(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(null, 204)]);
        $account = $this->account();

        $this->withHeaders($this->auth($this->user))->deleteJson("/api/social/accounts/{$account->id}")->assertOk();

        $this->assertNull($account->fresh());
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE'
            && $req->url() === 'https://discord.com/api/v10/webhooks/111/'.self::TOKEN);
    }

    public function test_disconnecting_still_removes_the_account_when_discord_refuses(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response('down', 500)]);
        $account = $this->account();

        $this->withHeaders($this->auth($this->user))->deleteJson("/api/social/accounts/{$account->id}")->assertOk();

        $this->assertNull($account->fresh());
    }

    public function test_disconnecting_one_channel_leaves_the_others(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(null, 204)]);
        $first = $this->account();
        $second = $this->account(['platform_account_id' => '555', 'access_token' => 'other-token', 'metadata' => ['webhook_id' => '50', 'channel_id' => '555']]);

        $this->withHeaders($this->auth($this->user))->deleteJson("/api/social/accounts/{$first->id}")->assertOk();

        $this->assertNotNull($second->fresh());
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), '/webhooks/50/'));
    }

    public function test_erasing_the_user_deletes_their_discord_webhooks(): void
    {
        Http::fake(['discord.com/api/v10/webhooks/*' => Http::response(null, 204)]);
        $this->account();

        $result = app(UserErasure::class)->erase($this->user);

        $this->assertSame('ok', $result['steps']['discord']);
        $this->assertNotContains('discord', $result['local_only_platforms']);
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE' && str_contains($req->url(), '/webhooks/111/'));
    }
}
