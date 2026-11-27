<?php

namespace Tests\Feature;

use App\Jobs\PublishToTelegramJob;
use App\Mcp\Tools\CreatePost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\PostPublishDispatcher;
use App\Services\Social\Providers\TelegramProvider;
use App\Services\Social\SocialProviderManager;
use App\Services\Social\Telegram\TelegramBotApi;
use App\Services\Social\Telegram\TelegramRateLimited;
use App\Support\SafeHttp;
use App\Support\SentryScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Sentry\Breadcrumb;
use Tests\TestCase;

/**
 * Telegram: one shared bot, chats connected by posting a one-time code, and
 * publishing through the Bot API (sendMessage / sendPhoto / sendVideo /
 * sendMediaGroup). Every Bot API call is faked; nothing reaches Telegram.
 */
class TelegramPublishTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '7012345678:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';

    private const BOT_ID = 7012345678;

    private const CHANNEL_ID = '-1001234567890';

    private const GROUP_ID = '-1009876543210';

    private User $user;

    /** @var array<string, mixed> Bot API method => response (array, or callable(Request)) */
    private array $bot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'social.platforms.telegram.bot_token' => self::TOKEN,
            'social.platforms.telegram.bot_username' => 'ViewsMaxTestBot',
        ]);
        SafeHttp::resolveUsing(fn (string $host) => $host === 'cdn.test' ? ['93.184.216.34'] : []);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        SafeHttp::resolveUsing(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function auth(User $user): array
    {
        // The test client keeps the guard's resolved user between requests;
        // forget it so each request is really made as $user.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private static function ok(mixed $result = true): array
    {
        return ['ok' => true, 'result' => $result];
    }

    private static function error(int $code, string $description, array $parameters = []): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(array_filter([
            'ok' => false, 'error_code' => $code, 'description' => $description, 'parameters' => $parameters ?: null,
        ]), $code);
    }

    /** Fake the Bot API by method name, plus any media downloads from cdn.test. */
    private function fakeBot(array $methods): void
    {
        $this->bot = $methods + [
            'getMe' => self::ok(['id' => self::BOT_ID, 'is_bot' => true, 'username' => 'ViewsMaxTestBot']),
            'deleteMessage' => self::ok(true),
        ];

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://cdn.test/')) {
                return str_contains($request->url(), 'video')
                    ? Http::response('VIDEODATA', 200, ['Content-Type' => 'video/mp4'])
                    : Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']);
            }

            $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $response = $this->bot[$method] ?? self::error(404, 'Not Found');
            if (is_callable($response)) {
                $response = $response($request);
            }

            return is_array($response) ? Http::response($response) : $response;
        });
    }

    /** getChatMember answers for the bot and for given users. */
    private static function members(array $byUserId): callable
    {
        return function (Request $request) use ($byUserId) {
            $member = $byUserId[(int) self::param($request, 'user_id')] ?? null;

            return $member === null ? self::error(400, 'Bad Request: user not found') : self::ok($member);
        };
    }

    private static function botAdmin(bool $canPost = true): array
    {
        return ['status' => 'administrator', 'user' => ['id' => self::BOT_ID, 'is_bot' => true], 'can_post_messages' => $canPost];
    }

    /** A Bot API parameter, from a JSON or a multipart request. */
    private static function param(Request $request, string $name): mixed
    {
        if ($request->isMultipart()) {
            foreach ($request->data() as $part) {
                if (($part['name'] ?? null) === $name) {
                    return $part['contents'];
                }
            }

            return null;
        }

        return $request->data()[$name] ?? null;
    }

    private static function method(Request $request): string
    {
        return basename((string) parse_url($request->url(), PHP_URL_PATH));
    }

    private function issueCode(?User $user = null): string
    {
        return $this->withHeaders($this->auth($user ?? $this->user))
            ->postJson('/api/social/telegram/connect-code')
            ->assertOk()
            ->json('data.code');
    }

    private function webhook(array $update, ?string $secret = null)
    {
        return $this->postJson('/api/telegram/webhook', $update + ['update_id' => random_int(1, 1_000_000)], [
            'X-Telegram-Bot-Api-Secret-Token' => $secret ?? app(TelegramBotApi::class)->webhookSecret(),
        ]);
    }

    private function channelPost(string $text, string $chatId = self::CHANNEL_ID, ?string $username = 'creatorlab'): array
    {
        return ['channel_post' => [
            'message_id' => 55,
            'date' => time(),
            'chat' => array_filter(['id' => (int) $chatId, 'type' => 'channel', 'title' => 'Creator Lab', 'username' => $username]),
            'sender_chat' => ['id' => (int) $chatId, 'type' => 'channel', 'title' => 'Creator Lab'],
            'text' => $text,
        ]];
    }

    private function groupMessage(string $text, int $fromId = 111, array $extra = [], string $chatId = self::GROUP_ID): array
    {
        return ['message' => array_merge([
            'message_id' => 77,
            'date' => time(),
            'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Creator Chat'],
            'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Ana'],
            'text' => $text,
        ], $extra)];
    }

    private function connect(string $code, ?User $user = null)
    {
        return $this->withHeaders($this->auth($user ?? $this->user))
            ->postJson('/api/social/telegram/connect', ['code' => $code]);
    }

    private function account(array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => 'telegram',
            'platform_account_id' => self::CHANNEL_ID,
            'name' => 'Creator Lab',
            'username' => 'creatorlab',
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['chat_type' => 'channel'],
        ], $overrides));
    }

    private function provider(): TelegramProvider
    {
        return app(TelegramProvider::class);
    }

    private function message(string $text, array $media = []): SocialPost
    {
        return new SocialPost(['content' => $text, 'media' => $media]);
    }

    // ---------------------------------------------------------- configuration

    public function test_telegram_is_configured_only_with_both_bot_token_and_username(): void
    {
        $manager = app(SocialProviderManager::class);
        $this->assertTrue($manager->isConfigured('telegram'));

        config(['social.platforms.telegram.bot_username' => '']);
        $this->assertFalse($manager->isConfigured('telegram'));

        config(['social.platforms.telegram.bot_username' => 'ViewsMaxTestBot', 'social.platforms.telegram.bot_token' => '']);
        $this->assertFalse($manager->isConfigured('telegram'));

        config(['social.platforms.telegram.bot_token' => self::TOKEN]);

        // The other credential shapes are unchanged.
        $this->assertTrue($manager->isConfigured('bluesky'));
        config(['social.platforms.pinterest.client_id' => 'id', 'social.platforms.pinterest.client_secret' => '']);
        $this->assertFalse($manager->isConfigured('pinterest'));
    }

    public function test_telegram_is_offered_to_mcp_only_while_configured(): void
    {
        $this->assertContains('telegram', CreatePost::availablePlatforms());
        $this->assertSame(4096, CreatePost::PLATFORM_CHAR_LIMITS['telegram']);
        $this->assertStringContainsString('album', CreatePost::telegramRule() ?: '');

        config(['social.platforms.telegram.bot_token' => '']);
        $this->assertNotContains('telegram', CreatePost::availablePlatforms());
    }

    public function test_a_code_is_refused_while_telegram_is_not_configured(): void
    {
        config(['social.platforms.telegram.bot_token' => '']);

        $this->withHeaders($this->auth($this->user))->postJson('/api/social/telegram/connect-code')->assertStatus(503);
        $this->connect('ABCDEFGH')->assertStatus(503);
        $this->webhook($this->channelPost('/connect ABCDEFGH'))->assertNotFound();
    }

    // ---------------------------------------------------------------- connect

    public function test_issuing_a_code_returns_the_command_and_the_bot(): void
    {
        $response = $this->withHeaders($this->auth($this->user))->postJson('/api/social/telegram/connect-code')->assertOk();

        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
        $response->assertJsonPath('data.command', "/connect {$code}")
            ->assertJsonPath('data.bot_username', 'ViewsMaxTestBot')
            ->assertJsonPath('data.bot_url', 'https://t.me/ViewsMaxTestBot');
        $this->assertNotNull($response->json('data.expires_at'));
    }

    public function test_a_channel_is_connected_when_its_admin_posts_the_code(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $code = $this->issueCode();

        $this->webhook($this->channelPost("/connect {$code}"))->assertOk();
        $this->connect($code)->assertOk()->assertJsonPath('data.0.platform', 'telegram')
            ->assertJsonPath('data.0.token_valid', true);

        $account = SocialAccount::where('platform', 'telegram')->sole();
        $this->assertSame($this->user->id, $account->user_id);
        $this->assertSame(self::CHANNEL_ID, $account->platform_account_id);
        $this->assertSame('Creator Lab', $account->name);
        $this->assertSame('creatorlab', $account->username);
        $this->assertSame('https://t.me/creatorlab', $account->profile_url);
        $this->assertSame('channel', $account->meta('chat_type'));
        $this->assertNull($account->access_token);
        $this->assertTrue($account->hasUsableCredentials());

        // The /connect message is cleaned up, and the code is spent.
        Http::assertSent(fn (Request $r) => self::method($r) === 'deleteMessage'
            && (string) self::param($r, 'chat_id') === self::CHANNEL_ID && (int) self::param($r, 'message_id') === 55);
        $this->connect($code)->assertStatus(422);
    }

    public function test_a_group_is_connected_when_a_group_admin_posts_the_code(): void
    {
        $this->fakeBot(['getChatMember' => self::members([
            self::BOT_ID => self::botAdmin(false), // groups have no "post messages" right
            111 => ['status' => 'administrator', 'user' => ['id' => 111]],
        ])]);
        $code = $this->issueCode();

        $this->webhook($this->groupMessage("/connect {$code}"))->assertOk();
        $this->connect($code)->assertOk();

        $account = SocialAccount::where('platform', 'telegram')->sole();
        $this->assertSame(self::GROUP_ID, $account->platform_account_id);
        $this->assertSame('Creator Chat', $account->name);
        $this->assertNull($account->username);
        $this->assertNull($account->profile_url);
    }

    public function test_an_anonymous_group_admin_can_post_the_code(): void
    {
        // Anonymous admins post on behalf of the group itself.
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $code = $this->issueCode();

        $this->webhook($this->groupMessage("/connect {$code}", 1087968824, [
            'sender_chat' => ['id' => (int) self::GROUP_ID, 'type' => 'supergroup', 'title' => 'Creator Chat'],
        ]));

        $this->connect($code)->assertOk();
        $this->assertSame(1, SocialAccount::where('platform', 'telegram')->count());
    }

    public function test_a_group_member_who_is_not_an_admin_cannot_connect_the_group(): void
    {
        // The bot is already an admin there (someone else set it up). A plain
        // member posting their own code must not get to publish into the group.
        $this->fakeBot(['getChatMember' => self::members([
            self::BOT_ID => self::botAdmin(),
            222 => ['status' => 'member', 'user' => ['id' => 222]],
        ])]);
        $code = $this->issueCode();

        $this->webhook($this->groupMessage("/connect {$code}", 222));

        $this->connect($code)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'administrator of "Creator Chat"'));
        $this->assertSame(0, SocialAccount::where('platform', 'telegram')->count());
        // The refused sighting is dropped, so an admin can post the same code.
        $this->connect($code)->assertStatus(202);
    }

    public function test_a_code_sent_as_another_channel_or_auto_forwarded_proves_nothing(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);

        $asOtherChannel = $this->issueCode();
        $this->webhook($this->groupMessage("/connect {$asOtherChannel}", 136817688, [
            'sender_chat' => ['id' => -1005555555555, 'type' => 'channel', 'title' => 'Some channel'],
        ]));
        $this->connect($asOtherChannel)->assertStatus(422);

        $forwarded = $this->issueCode();
        $this->webhook($this->groupMessage("/connect {$forwarded}", 777000, [
            'is_automatic_forward' => true,
            'sender_chat' => ['id' => (int) self::CHANNEL_ID, 'type' => 'channel', 'title' => 'Linked channel'],
        ]));
        $this->connect($forwarded)->assertStatus(422);

        $this->assertSame(0, SocialAccount::where('platform', 'telegram')->count());
    }

    public function test_a_private_chat_is_not_a_destination(): void
    {
        $this->fakeBot([]);
        $code = $this->issueCode();

        $this->webhook(['message' => [
            'message_id' => 3, 'date' => time(),
            'chat' => ['id' => 111, 'type' => 'private', 'first_name' => 'Ana'],
            'from' => ['id' => 111, 'is_bot' => false, 'first_name' => 'Ana'],
            'text' => "/connect {$code}",
        ]]);

        $this->connect($code)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'private chat'));
        $this->assertSame(0, SocialAccount::where('platform', 'telegram')->count());
    }

    public function test_connect_reports_pending_until_the_code_is_seen(): void
    {
        $this->fakeBot([]);
        $code = $this->issueCode();

        $this->connect($code)->assertStatus(202)
            ->assertJsonPath('pending', true)
            ->assertJsonPath('message', fn ($m) => str_contains($m, "/connect {$code}"));
        Http::assertNothingSent();
    }

    public function test_overlapping_checks_of_one_code_run_one_at_a_time(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $code = $this->issueCode();
        $this->webhook($this->channelPost("/connect {$code}"));

        $held = \Illuminate\Support\Facades\Cache::lock('telegram:connect:check:'.$code, 30);
        $this->assertTrue($held->get());
        $this->connect($code)->assertStatus(202);
        $this->assertSame(0, SocialAccount::where('platform', 'telegram')->count());

        $held->release();
        $this->connect($code)->assertOk();
    }

    public function test_another_users_code_connects_nothing(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $victim = User::factory()->create();
        $victimCode = $this->issueCode($victim);
        $this->webhook($this->channelPost("/connect {$victimCode}"));

        // The attacker submits the victim's code.
        $this->connect($victimCode, $this->user)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'expired or belongs to another account'));
        $this->assertSame(0, $this->user->socialAccounts()->count());

        // The victim is unaffected.
        $this->connect($victimCode, $victim)->assertOk();
        $this->assertSame(1, $victim->socialAccounts()->where('platform', 'telegram')->count());
    }

    public function test_an_expired_code_is_refused_and_ignored_by_the_webhook(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $code = $this->issueCode();

        $this->travel(16)->minutes();

        $this->webhook($this->channelPost("/connect {$code}"))->assertOk();
        $this->connect($code)->assertStatus(422);
        $this->assertSame(0, SocialAccount::where('platform', 'telegram')->count());
    }

    public function test_an_unknown_code_is_ignored_and_a_new_code_replaces_the_old_one(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $first = $this->issueCode();
        $second = $this->issueCode();
        $this->assertNotSame($first, $second);

        $this->webhook($this->channelPost('/connect ZZZZ2222'))->assertOk();
        $this->webhook($this->channelPost("/connect {$first}"))->assertOk();

        $this->connect($first)->assertStatus(422);
        $this->connect($second)->assertStatus(202);
    }

    public function test_concurrent_connects_never_see_each_others_codes(): void
    {
        $this->fakeBot(['getChatMember' => self::members([
            self::BOT_ID => self::botAdmin(),
            111 => ['status' => 'creator', 'user' => ['id' => 111]],
        ])]);
        $other = User::factory()->create();
        $mine = $this->issueCode();
        $theirs = $this->issueCode($other);

        // Both codes arrive before either user checks, in the "wrong" order.
        $this->webhook($this->groupMessage("/connect {$theirs}"));
        $this->webhook($this->channelPost("/connect {$mine}"));

        $this->connect($mine)->assertOk();
        $this->connect($theirs, $other)->assertOk();

        $this->assertSame(self::CHANNEL_ID, $this->user->socialAccounts()->sole()->platform_account_id);
        $this->assertSame(self::GROUP_ID, $other->socialAccounts()->sole()->platform_account_id);
    }

    public function test_a_bot_without_the_post_right_is_refused_and_can_be_fixed_without_reposting(): void
    {
        $canPost = false;
        $this->fakeBot(['getChatMember' => function () use (&$canPost) {
            return self::ok(self::botAdmin($canPost));
        }]);
        $code = $this->issueCode();
        $this->webhook($this->channelPost("/connect {$code}"));

        $this->connect($code)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '"Post messages"'));

        $canPost = true;
        $this->connect($code)->assertOk();
    }

    public function test_a_bot_that_is_not_an_admin_is_refused(): void
    {
        $this->fakeBot(['getChatMember' => self::members([
            self::BOT_ID => ['status' => 'member', 'user' => ['id' => self::BOT_ID]],
            111 => ['status' => 'creator', 'user' => ['id' => 111]],
        ])]);
        $code = $this->issueCode();
        $this->webhook($this->groupMessage("/connect {$code}"));

        $this->connect($code)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Make @ViewsMaxTestBot an administrator'));
    }

    public function test_the_webhook_refuses_a_wrong_secret(): void
    {
        $this->fakeBot([]);
        $code = $this->issueCode();

        $this->webhook($this->channelPost("/connect {$code}"), 'not-the-secret')->assertForbidden();
        $this->postJson('/api/telegram/webhook', $this->channelPost("/connect {$code}"))->assertForbidden();

        $this->connect($code)->assertStatus(202);
    }

    public function test_a_command_for_another_bot_is_ignored_and_one_for_ours_counts(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $code = $this->issueCode();

        $this->webhook($this->channelPost("/connect@SomeOtherBot {$code}"));
        $this->connect($code)->assertStatus(202);

        $this->webhook($this->channelPost('/connect@viewsmaxtestbot '.strtolower($code)));
        $this->connect($code)->assertOk();
    }

    public function test_a_chat_connected_by_another_viewsmax_user_is_refused(): void
    {
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $first = User::factory()->create();
        $code = $this->issueCode($first);
        $this->webhook($this->channelPost("/connect {$code}"));
        $this->connect($code, $first)->assertOk();

        $code = $this->issueCode();
        $this->webhook($this->channelPost("/connect {$code}"));
        $this->connect($code)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already connected to another ViewsMax account'));
    }

    public function test_the_agent_setup_page_shows_the_code_steps_instead_of_a_password_form(): void
    {
        $this->actingAs($this->user, 'web');

        $html = $this->get(route('register.setup'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~/connect [A-Z2-9]{8}~', $html);
        $this->assertStringContainsString('@ViewsMaxTestBot', html_entity_decode($html));
        // Reloading keeps the same code (it may already be posted).
        preg_match('~/connect ([A-Z2-9]{8})~', $html, $m);
        $this->assertStringContainsString("/connect {$m[1]}", $this->get(route('register.setup'))->getContent());
    }

    // ---------------------------------------------------------------- publish

    public function test_text_goes_out_with_send_message_and_links_to_the_public_post(): void
    {
        $this->fakeBot(['sendMessage' => self::ok(['message_id' => 42, 'chat' => ['id' => (int) self::CHANNEL_ID]])]);

        $result = $this->provider()->publish($this->account(), $this->message("Big news 🎉\nhttps://example.com"));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('42', $result->remotePostId);
        $this->assertSame('https://t.me/creatorlab/42', $result->remotePostUrl);
        Http::assertSent(fn (Request $r) => self::method($r) === 'sendMessage'
            && (string) self::param($r, 'chat_id') === self::CHANNEL_ID
            && self::param($r, 'text') === "Big news 🎉\nhttps://example.com"
            && self::param($r, 'parse_mode') === null);
    }

    public function test_a_private_chat_gets_a_c_link_and_a_basic_group_none(): void
    {
        $this->fakeBot(['sendMessage' => self::ok(['message_id' => 9])]);

        $private = $this->provider()->publish($this->account(['username' => null]), $this->message('hi'));
        $this->assertSame('https://t.me/c/1234567890/9', $private->remotePostUrl);

        $basic = $this->provider()->publish($this->account(['username' => null, 'platform_account_id' => '-4001']), $this->message('hi'));
        $this->assertTrue($basic->success);
        $this->assertNull($basic->remotePostUrl);
    }

    public function test_one_photo_is_uploaded_with_send_photo_and_the_caption(): void
    {
        $this->fakeBot(['sendPhoto' => self::ok(['message_id' => 43])]);

        $result = $this->provider()->publish($this->account(), $this->message('Look at this', [
            ['type' => 'image', 'url' => 'https://cdn.test/a.jpg'],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $r) => self::method($r) === 'sendPhoto'
            && $r->isMultipart()
            && $r->hasFile('photo', 'JPEGDATA', 'photo.jpg')
            && self::param($r, 'caption') === 'Look at this'
            && (string) self::param($r, 'chat_id') === self::CHANNEL_ID);
        // Uploaded, never handed to Telegram as a URL (lower limits).
        Http::assertNotSent(fn (Request $r) => str_contains((string) self::param($r, 'photo'), 'cdn.test'));
    }

    public function test_one_video_is_uploaded_with_send_video(): void
    {
        $this->fakeBot(['sendVideo' => self::ok(['message_id' => 44])]);

        $result = $this->provider()->publish($this->account(), $this->message('Clip', [
            ['type' => 'video', 'url' => 'https://cdn.test/video.mp4'],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $r) => self::method($r) === 'sendVideo'
            && $r->hasFile('video', 'VIDEODATA', 'video.mp4')
            && self::param($r, 'supports_streaming') === 'true'
            && self::param($r, 'caption') === 'Clip');
    }

    public function test_several_media_become_one_album_with_the_caption_on_the_first_item(): void
    {
        $this->fakeBot(['sendMediaGroup' => self::ok([['message_id' => 50], ['message_id' => 51], ['message_id' => 52]])]);

        $result = $this->provider()->publish($this->account(), $this->message('Trip', [
            ['type' => 'image', 'url' => 'https://cdn.test/1.jpg'],
            ['type' => 'video', 'url' => 'https://cdn.test/video.mp4'],
            ['type' => 'image', 'url' => 'https://cdn.test/2.jpg'],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('50', $result->remotePostId);
        $this->assertSame('https://t.me/creatorlab/50', $result->remotePostUrl);

        Http::assertSent(function (Request $r) {
            if (self::method($r) !== 'sendMediaGroup') {
                return false;
            }
            $media = json_decode((string) self::param($r, 'media'), true);

            return $media === [
                ['type' => 'photo', 'media' => 'attach://file0', 'caption' => 'Trip'],
                ['type' => 'video', 'media' => 'attach://file1', 'supports_streaming' => true],
                ['type' => 'photo', 'media' => 'attach://file2'],
            ] && $r->hasFile('file0', 'JPEGDATA') && $r->hasFile('file1', 'VIDEODATA') && $r->hasFile('file2', 'JPEGDATA');
        });
    }

    public function test_text_too_long_for_a_caption_follows_the_media_as_its_own_message(): void
    {
        $this->fakeBot([
            'sendPhoto' => self::ok(['message_id' => 60]),
            'sendMessage' => self::ok(['message_id' => 61]),
        ]);
        $long = str_repeat('a', 1025);

        $result = $this->provider()->publish($this->account(), $this->message($long, [['type' => 'image', 'url' => 'https://cdn.test/a.jpg']]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('60', $result->remotePostId);
        Http::assertSent(fn (Request $r) => self::method($r) === 'sendPhoto' && self::param($r, 'caption') === null);
        Http::assertSent(fn (Request $r) => self::method($r) === 'sendMessage' && self::param($r, 'text') === $long);
        $sent = collect(Http::recorded())->map(fn ($pair) => self::method($pair[0]))->filter(fn ($m) => str_starts_with($m, 'send'))->values()->all();
        $this->assertSame(['sendPhoto', 'sendMessage'], $sent);
    }

    public function test_a_failed_text_after_live_media_says_so_without_retrying(): void
    {
        $this->fakeBot([
            'sendPhoto' => self::ok(['message_id' => 60]),
            'sendMessage' => Http::sequence()
                ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 2', 'parameters' => ['retry_after' => 2]], 429)
                ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is too long'], 400),
        ]);

        $result = $this->provider()->publish($this->account(), $this->message(str_repeat('b', 2000), [['type' => 'image', 'url' => 'https://cdn.test/a.jpg']]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('The media was posted to Telegram', (string) $result->error);
        $this->assertStringContainsString('retrying would post the media again', (string) $result->error);
        Sleep::assertSleptTimes(1);
    }

    public function test_media_rules_are_checked_before_anything_is_sent(): void
    {
        $this->fakeBot([]);
        $account = $this->account();
        $eleven = array_fill(0, 11, ['type' => 'image', 'url' => 'https://cdn.test/a.jpg']);

        $tooMany = $this->provider()->publish($account, $this->message('x', $eleven));
        $this->assertStringContainsString('at most 10', (string) $tooMany->error);

        $empty = $this->provider()->publish($account, $this->message('  '));
        $this->assertStringContainsString('nothing to post', (string) $empty->error);

        $tooLong = $this->provider()->publish($account, $this->message(str_repeat('x', 4097)));
        $this->assertStringContainsString('4096', (string) $tooLong->error);

        // Emoji outside the BMP count twice, as Telegram counts them.
        $emoji = $this->provider()->publish($account, $this->message(str_repeat('😀', 2049)));
        $this->assertFalse($emoji->success);

        Http::assertNothingSent();
    }

    public function test_a_file_over_telegrams_upload_limit_fails_clearly_and_posts_nothing(): void
    {
        Http::fake([
            'cdn.test/*' => Http::response(str_repeat('x', 10 * 1024 * 1024 + 1), 200, ['Content-Type' => 'image/jpeg']),
            'api.telegram.org/*' => Http::response(self::ok(['message_id' => 1])),
        ]);

        $result = $this->provider()->publish($this->account(), $this->message('big', [['type' => 'image', 'url' => 'https://cdn.test/big.jpg']]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('up to 10 MB', (string) $result->error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org'));
    }

    public function test_a_kicked_bot_flags_the_account_for_reconnect(): void
    {
        $this->fakeBot(['sendMessage' => self::error(403, 'Forbidden: bot was kicked from the channel chat')]);
        $account = $this->account();

        $result = $this->provider()->publish($account, $this->message('hi'));

        $this->assertFalse($result->success);
        $account->refresh();
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
        $this->assertStringContainsString('Add @ViewsMaxTestBot back as an administrator', (string) $account->last_error);
        $this->assertSame($account->last_error, $result->error);
    }

    public function test_lost_rights_and_a_vanished_chat_also_flag_the_account(): void
    {
        foreach ([
            'Bad Request: chat not found',
            'Bad Request: not enough rights to send photos to the chat',
            'Bad Request: need administrator rights in the channel chat',
            'Bad Request: PEER_ID_INVALID',
        ] as $description) {
            $this->fakeBot(['sendMessage' => self::error(400, $description)]);
            $account = $this->account(['platform_account_id' => (string) random_int(-1009999999999, -1001000000000)]);

            $this->assertFalse($this->provider()->publish($account, $this->message('hi'))->success);
            $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status, $description);
        }
    }

    public function test_content_telegram_refuses_fails_the_post_but_keeps_the_account(): void
    {
        $this->fakeBot(['sendPhoto' => self::error(400, 'Bad Request: PHOTO_INVALID_DIMENSIONS')]);
        $account = $this->account();

        $result = $this->provider()->publish($account, $this->message('x', [['type' => 'image', 'url' => 'https://cdn.test/a.jpg']]));

        $this->assertSame('Telegram refused the post, nothing was published: Bad Request: PHOTO_INVALID_DIMENSIONS', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_a_rejected_bot_token_fails_without_blaming_the_users_chat(): void
    {
        $this->fakeBot(['sendMessage' => self::error(401, 'Unauthorized')]);
        $account = $this->account();

        $result = $this->provider()->publish($account, $this->message('hi'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('problem on our side', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_flood_control_raises_a_retry_with_telegrams_delay(): void
    {
        $this->fakeBot(['sendMessage' => self::error(429, 'Too Many Requests: retry after 17', ['retry_after' => 17])]);

        try {
            $this->provider()->publish($this->account(), $this->message('hi'));
            $this->fail('Expected a rate-limit exception.');
        } catch (TelegramRateLimited $e) {
            $this->assertSame(17, $e->retryAfter);
        }
    }

    public function test_a_server_error_or_no_connection_asks_the_queue_to_retry(): void
    {
        $this->fakeBot(['sendMessage' => self::error(502, 'Bad Gateway')]);
        $account = $this->account();
        try {
            $this->provider()->publish($account, $this->message('hi'));
            $this->fail('Expected a transient exception.');
        } catch (\App\Exceptions\TransientPublishException $e) {
            $this->assertStringContainsString('temporary error', $e->getMessage());
        }

        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'));
        try {
            $this->provider()->publish($account, $this->message('hi'));
            $this->fail('Expected a transient exception.');
        } catch (\App\Exceptions\TransientPublishException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function test_a_group_that_became_a_supergroup_is_followed_and_the_account_moves(): void
    {
        $this->fakeBot(['sendMessage' => function (Request $r) {
            return (string) self::param($r, 'chat_id') === '-4001'
                ? self::error(400, 'Bad Request: group chat was upgraded to a supergroup chat', ['migrate_to_chat_id' => -1004001])
                : self::ok(['message_id' => 5]);
        }]);
        $account = $this->account(['platform_account_id' => '-4001', 'username' => null, 'metadata' => ['chat_type' => 'group']]);

        $result = $this->provider()->publish($account, $this->message('hi'));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('https://t.me/c/4001/5', $result->remotePostUrl);
        $account->refresh();
        $this->assertSame('-1004001', $account->platform_account_id);
        $this->assertSame('supergroup', $account->meta('chat_type'));
    }

    // -------------------------------------------------------------------- job

    public function test_the_job_publishes_the_target(): void
    {
        $this->fakeBot(['sendMessage' => self::ok(['message_id' => 42])]);
        $account = $this->account();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'From the composer', 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create([
            'platform' => 'telegram', 'social_account_id' => $account->id, 'status' => PostTarget::STATUS_PUBLISHING,
        ]);

        (new PublishToTelegramJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status);
        $this->assertSame('42', $target->platform_post_id);
        $this->assertSame('https://t.me/creatorlab/42', $target->meta['url']);
    }

    public function test_the_job_uses_the_caption_override(): void
    {
        $this->fakeBot(['sendMessage' => self::ok(['message_id' => 1])]);
        $account = $this->account();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'Generic', 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create([
            'platform' => 'telegram', 'social_account_id' => $account->id, 'status' => PostTarget::STATUS_PUBLISHING,
            'caption_override' => 'Telegram only',
        ]);

        (new PublishToTelegramJob($target->id))->handle(app(SocialProviderManager::class));

        Http::assertSent(fn (Request $r) => self::param($r, 'text') === 'Telegram only');
    }

    public function test_the_job_fails_fast_for_an_account_that_needs_reconnecting(): void
    {
        $this->fakeBot([]);
        $account = $this->account(['status' => SocialAccount::STATUS_NEEDS_REAUTH, 'last_error' => 'Re-add the bot.']);
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create(['platform' => 'telegram', 'social_account_id' => $account->id, 'status' => PostTarget::STATUS_PUBLISHING]);

        (new PublishToTelegramJob($target->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertSame('Re-add the bot.', $target->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_the_job_waits_exactly_as_long_as_flood_control_asks(): void
    {
        $this->fakeBot(['sendMessage' => self::error(429, 'Too Many Requests: retry after 7', ['retry_after' => 7])]);
        $account = $this->account();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create(['platform' => 'telegram', 'social_account_id' => $account->id, 'status' => PostTarget::STATUS_PUBLISHING]);

        $job = (new PublishToTelegramJob($target->id))->withFakeQueueInteractions();
        $job->handle(app(SocialProviderManager::class));

        $job->assertReleased(delay: 7);
        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->fresh()->status);
    }

    public function test_the_dispatcher_routes_telegram_targets_to_the_telegram_job(): void
    {
        Queue::fake();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $post->targets()->create(['platform' => 'telegram', 'status' => PostTarget::STATUS_PENDING]);

        PostPublishDispatcher::dispatch($post);

        Queue::assertPushed(PublishToTelegramJob::class);
    }

    // --------------------------------------------------------- post validation

    public function test_publishing_checks_telegram_limits_before_saving(): void
    {
        Queue::fake();
        $account = $this->account();
        $base = ['targets' => [['platform' => 'telegram', 'social_account_id' => $account->id]], 'status' => Post::STATUS_POSTED];

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $base + ['caption' => ''])
            ->assertStatus(422)->assertJsonPath('message', 'Telegram needs text, a photo or a video before publishing or scheduling.');

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $base + ['caption' => str_repeat('a', 4097)])
            ->assertStatus(422)->assertJsonPath('message', "Caption is 1 character over Telegram's 4096 limit.");

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $base + [
            'caption' => 'x', 'media' => array_fill(0, 11, ['type' => 'image', 'url' => 'https://cdn.test/a.jpg']),
        ])->assertStatus(422)->assertJsonPath('message', 'Telegram allows at most 10 media items per post.');

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $base + [
            'caption' => '', 'media' => [['type' => 'image', 'url' => 'https://cdn.test/a.jpg']],
        ])->assertCreated();
        Queue::assertPushed(PublishToTelegramJob::class);
    }

    // ------------------------------------------------------ the token stays put

    public function test_the_bot_token_never_reaches_logs_errors_or_responses(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        // Connect flow: code, webhook, refusals, success.
        $this->fakeBot(['getChatMember' => self::members([self::BOT_ID => self::botAdmin()])]);
        $codeResponse = $this->withHeaders($this->auth($this->user))->postJson('/api/social/telegram/connect-code')->getContent();
        $code = json_decode($codeResponse, true)['data']['code'];
        $this->webhook($this->channelPost("/connect {$code}"));
        $connectResponse = $this->connect($code)->getContent();
        $accounts = $this->withHeaders($this->auth($this->user))->getJson('/api/social/accounts')->getContent();

        // Publish failures of every kind, through the job.
        $account = SocialAccount::where('platform', 'telegram')->sole();
        $errors = [];
        foreach ([
            fn () => throw new ConnectionException('cURL error 7: Failed to connect for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'),
            fn () => self::error(400, 'Bad Request: something about bot'.self::TOKEN),
            fn () => self::error(403, 'Forbidden: bot was kicked from the channel chat'),
        ] as $send) {
            $account->update(['status' => SocialAccount::STATUS_CONNECTED]);
            $this->fakeBot(['sendMessage' => $send]);
            $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
            $target = $post->targets()->create(['platform' => 'telegram', 'social_account_id' => $account->id, 'status' => PostTarget::STATUS_PUBLISHING]);
            $job = new PublishToTelegramJob($target->id);
            try {
                $job->handle(app(SocialProviderManager::class));
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                $job->failed($e);
            }
            $errors[] = (string) $target->fresh()->error;
            $errors[] = (string) $account->fresh()->last_error;
        }

        $haystack = implode("\n", array_merge($logged, $errors, [$codeResponse, $connectResponse, $accounts]));
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(self::TOKEN, $haystack);
        $this->assertStringNotContainsString(explode(':', self::TOKEN)[1], $haystack);
    }

    public function test_sentry_breadcrumbs_and_spans_lose_the_token(): void
    {
        $url = 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage';
        $breadcrumb = SentryScrubber::breadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, [
            'url' => $url, 'http.request.method' => 'POST', 'http.response.status_code' => 200,
        ]));

        $this->assertSame('https://api.telegram.org/bot[redacted]/sendMessage', $breadcrumb->getMetadata()['url']);
        $this->assertSame(200, $breadcrumb->getMetadata()['http.response.status_code']);

        $event = \Sentry\Event::createTransaction();
        $span = new \Sentry\Tracing\Span();
        $span->setDescription("POST {$url}");
        $span->setData(['url' => $url]);
        $event->setSpans([$span]);
        SentryScrubber::transaction($event);
        $this->assertStringNotContainsString(self::TOKEN, (string) $span->getDescription().json_encode($span->getData()));

        // Redaction also catches a token that isn't the configured one.
        $this->assertSame('bot[redacted]/getMe', TelegramBotApi::redact('bot123:abc-DEF_ghi/getMe'));
    }

    public function test_set_webhook_registers_the_url_secret_and_update_types(): void
    {
        $this->fakeBot(['setWebhook' => self::ok(true)]);

        $this->artisan('telegram:set-webhook', ['--url' => 'https://api.viewsmax.test/api/telegram/webhook'])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => self::method($r) === 'setWebhook'
            && $r['url'] === 'https://api.viewsmax.test/api/telegram/webhook'
            && $r['secret_token'] === app(TelegramBotApi::class)->webhookSecret()
            && $r['allowed_updates'] === ['message', 'channel_post']);

        $this->artisan('telegram:set-webhook', ['--url' => 'http://insecure.test/hook'])->assertFailed();
    }
}
