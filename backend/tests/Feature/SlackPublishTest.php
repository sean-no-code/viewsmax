<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToSlackJob;
use App\Mcp\Tools\CreatePost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\CaptionRules;
use App\Services\Social\MediaRules;
use App\Services\Social\PostPublishDispatcher;
use App\Services\Social\Providers\SlackProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Slack publishing: OAuth v2 with the incoming-webhook scope picks the channel
 * on Slack's consent screen, the bot token posts there. Slack answers most
 * failures with HTTP 200 and ok:false, so every case below branches on the
 * error string.
 */
class SlackPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'social.platforms.slack.client_id' => 'slack-client',
            'social.platforms.slack.client_secret' => 'slack-secret',
        ]);
    }

    private function account(array $overrides = [], ?User $user = null): SocialAccount
    {
        $team = $overrides['team_id'] ?? 'T111';
        $channel = $overrides['channel_id'] ?? 'C222';
        unset($overrides['team_id'], $overrides['channel_id']);

        return ($user ?? $this->user)->socialAccounts()->create(array_merge([
            'platform' => 'slack',
            'platform_account_id' => "{$team}:{$channel}",
            'name' => '#general · Acme',
            'access_token' => 'xoxb-bot-token',
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['team_id' => $team, 'team_name' => 'Acme', 'channel_id' => $channel, 'channel_name' => 'general'],
        ], $overrides));
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function oauthResponse(array $overrides = []): array
    {
        return array_merge([
            'ok' => true,
            'access_token' => 'xoxb-new-token',
            'token_type' => 'bot',
            'scope' => 'incoming-webhook,chat:write,chat:write.public,files:write',
            'bot_user_id' => 'U0BOT',
            'app_id' => 'A0APP',
            'team' => ['id' => 'T111', 'name' => 'Acme'],
            'enterprise' => null,
            'is_enterprise_install' => false,
            'authed_user' => ['id' => 'U0HUMAN'],
            'incoming_webhook' => [
                'channel' => '#launches',
                'channel_id' => 'C999',
                'configuration_url' => 'https://acme.slack.com/services/B1',
                'url' => 'https://hooks.slack.com/services/T111/B1/secret',
            ],
        ], $overrides);
    }

    private function provider(): SlackProvider
    {
        return app(SocialProviderManager::class)->for('slack');
    }

    private function permalinkFake(): array
    {
        return ['slack.com/api/chat.getPermalink*' => Http::response(['ok' => true, 'channel' => 'C222', 'permalink' => 'https://acme.slack.com/archives/C222/p1700000000000100'])];
    }

    private function target(?SocialAccount $account = null, array $postAttrs = []): PostTarget
    {
        $post = Post::create(array_merge([
            'user_id' => $this->user->id,
            'caption' => 'From the composer',
            'status' => Post::STATUS_POSTED,
        ], $postAttrs));

        return $post->targets()->create([
            'platform' => 'slack',
            'social_account_id' => $account?->id,
            'status' => PostTarget::STATUS_PUBLISHING,
        ]);
    }

    private function runJob(PostTarget $target): void
    {
        (new PublishToSlackJob($target->id))->handle(app(SocialProviderManager::class));
    }

    // ---- connect ----------------------------------------------------------

    public function test_authorization_url_asks_for_the_bot_scopes_and_carries_the_state(): void
    {
        $url = $this->provider()->getAuthorizationUrl('https://app.example/oauth/callback', 'state-1');

        $this->assertStringStartsWith('https://slack.com/oauth/v2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('slack-client', $query['client_id']);
        $this->assertSame('incoming-webhook,chat:write,chat:write.public,files:write', $query['scope']);
        $this->assertSame('https://app.example/oauth/callback', $query['redirect_uri']);
        $this->assertSame('state-1', $query['state']);
        $this->assertArrayNotHasKey('user_scope', $query);
    }

    public function test_connect_stores_the_workspace_and_the_channel_picked_on_slacks_screen(): void
    {
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response($this->oauthResponse())]);

        $account = $this->provider()->connectFromCode($this->user, 'code-1', 'https://app.example/oauth/callback')->first();

        $this->assertSame('T111:C999', $account->platform_account_id);
        $this->assertSame('#launches · Acme', $account->name);
        $this->assertSame('xoxb-new-token', $account->access_token);
        $this->assertNull($account->refresh_token);
        $this->assertNull($account->token_expires_at);
        $this->assertSame('https://app.slack.com/client/T111/C999', $account->profile_url);
        $this->assertSame('T111', $account->meta('team_id'));
        $this->assertSame('C999', $account->meta('channel_id'));
        $this->assertSame('launches', $account->meta('channel_name'));
        $this->assertSame('U0BOT', $account->meta('bot_user_id'));
        // The webhook URL is a secret nothing uses, so it is not kept anywhere.
        $this->assertStringNotContainsString('hooks.slack.com', json_encode($account->fresh()->getAttributes()));
        $this->assertStringNotContainsString('hooks.slack.com', json_encode($account->fresh()->metadata));

        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/oauth.v2.access'
            && $req->hasHeader('Authorization', 'Basic '.base64_encode('slack-client:slack-secret'))
            && $req['code'] === 'code-1'
            && $req['redirect_uri'] === 'https://app.example/oauth/callback'
            && ! isset($req['client_secret']));
    }

    public function test_connecting_through_the_endpoints_adds_one_account_per_channel(): void
    {
        Http::fake(['slack.com/api/oauth.v2.access' => Http::sequence()
            ->push($this->oauthResponse())
            ->push($this->oauthResponse(['incoming_webhook' => ['channel' => '#random', 'channel_id' => 'C777', 'url' => 'https://hooks.slack.com/x']]))]);

        foreach (['code-a', 'code-b'] as $code) {
            $state = $this->withHeaders($this->auth($this->user))
                ->getJson('/api/social/slack/auth-url?redirect_uri='.urlencode('https://app.example/oauth/callback'))
                ->assertOk()->json('data.state');

            $this->withHeaders($this->auth($this->user))
                ->postJson('/api/social/slack/exchange', ['code' => $code, 'state' => $state])
                ->assertOk();
        }

        $this->assertSame(['T111:C777', 'T111:C999'], $this->user->socialAccounts()->where('platform', 'slack')
            ->orderBy('platform_account_id')->pluck('platform_account_id')->all());
    }

    public function test_a_refused_exchange_is_reported_from_slacks_error_string_despite_http_200(): void
    {
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response(['ok' => false, 'error' => 'invalid_code'], 200)]);

        $state = $this->withHeaders($this->auth($this->user))
            ->getJson('/api/social/slack/auth-url?redirect_uri='.urlencode('https://app.example/oauth/callback'))
            ->json('data.state');
        $response = $this->withHeaders($this->auth($this->user))
            ->postJson('/api/social/slack/exchange', ['code' => 'stale', 'state' => $state]);

        $response->assertStatus(422);
        $this->assertSame(0, $this->user->socialAccounts()->count());

        // The reason comes from Slack's error string, not the HTTP status.
        $this->expectExceptionMessage('The Slack approval expired before it was used. Connect again.');
        $this->provider()->connectFromCode($this->user, 'stale', 'https://app.example/oauth/callback');
    }

    public function test_an_app_with_token_rotation_on_is_refused_at_connect(): void
    {
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response($this->oauthResponse(['refresh_token' => 'xoxe-1', 'expires_in' => 43200]))]);

        $this->expectExceptionMessage('token rotation');
        try {
            $this->provider()->connectFromCode($this->user, 'code-1', 'https://app.example/oauth/callback');
        } finally {
            $this->assertSame(0, $this->user->socialAccounts()->count());
        }
    }

    public function test_an_install_without_a_channel_is_refused(): void
    {
        $response = $this->oauthResponse(['is_enterprise_install' => true, 'team' => null, 'enterprise' => ['id' => 'E1', 'name' => 'Org']]);
        unset($response['incoming_webhook']);
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response($response)]);

        $this->expectExceptionMessage('which channel');
        $this->provider()->connectFromCode($this->user, 'code-1', 'https://app.example/oauth/callback');
    }

    public function test_an_install_missing_a_needed_scope_is_refused(): void
    {
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response($this->oauthResponse(['scope' => 'incoming-webhook,chat:write']))]);

        $this->expectExceptionMessage('files:write');
        $this->provider()->connectFromCode($this->user, 'code-1', 'https://app.example/oauth/callback');
    }

    public function test_connecting_another_channel_keeps_the_workspaces_channels_on_the_new_token(): void
    {
        $old = $this->account(['access_token' => 'xoxb-old']);
        $otherWorkspace = $this->account(['team_id' => 'T999', 'channel_id' => 'C1', 'access_token' => 'xoxb-elsewhere']);
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response($this->oauthResponse())]);

        $this->provider()->connectFromCode($this->user, 'code-1', 'https://app.example/oauth/callback');

        $this->assertSame('xoxb-new-token', $old->fresh()->access_token);
        $this->assertSame('xoxb-elsewhere', $otherWorkspace->fresh()->access_token);
    }

    public function test_bot_tokens_do_not_expire_so_nothing_is_refreshed(): void
    {
        Http::fake();
        $account = $this->account();

        $this->assertSame($account->id, $this->provider()->ensureFreshToken($account)->id);
        Http::assertNothingSent();
    }

    // ---- text -------------------------------------------------------------

    public function test_text_post_goes_to_the_stored_channel_and_returns_the_message_permalink(): void
    {
        Http::fake($this->permalinkFake() + [
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C222', 'ts' => '1700000000.000100', 'message' => ['text' => 'Hello']]),
        ]);

        $result = $this->provider()->publish($this->account(), new SocialPost(['content' => "  Hello team  \n"]));

        $this->assertTrue($result->success);
        $this->assertSame('1700000000.000100', $result->remotePostId);
        $this->assertSame('https://acme.slack.com/archives/C222/p1700000000000100', $result->remotePostUrl);
        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/chat.postMessage'
            && $req->hasHeader('Authorization', 'Bearer xoxb-bot-token')
            && $req['channel'] === 'C222'
            && $req['text'] === 'Hello team');
        Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'https://slack.com/api/chat.getPermalink')
            && $req['channel'] === 'C222' && $req['message_ts'] === '1700000000.000100');
    }

    public function test_a_caption_cannot_trigger_mass_mentions_or_forge_links(): void
    {
        Http::fake($this->permalinkFake() + [
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C222', 'ts' => '1.2']),
        ]);

        $caption = "<!channel> <!here> <!everyone> ping <@U123> & see <https://evil.example|bank.com> 1 < 2 > 0 &amp;";
        $this->provider()->publish($this->account(), new SocialPost(['content' => $caption]));

        $expected = '&lt;!channel&gt; &lt;!here&gt; &lt;!everyone&gt; ping &lt;@U123&gt; &amp; see &lt;https://evil.example|bank.com&gt; 1 &lt; 2 &gt; 0 &amp;amp;';
        Http::assertSent(function (Request $req) use ($expected) {
            if ($req->url() !== 'https://slack.com/api/chat.postMessage') {
                return false;
            }
            $this->assertSame($expected, $req['text']);
            $this->assertStringNotContainsString('<', $req['text']);
            $this->assertStringNotContainsString('>', $req['text']);
            // No parsing switches that would turn plain "@channel" into a mention.
            $this->assertArrayNotHasKey('link_names', $req->data());
            $this->assertArrayNotHasKey('parse', $req->data());

            return true;
        });
    }

    public function test_escaping_only_touches_slacks_three_control_characters(): void
    {
        $this->assertSame('Café — "quotes" \'n\' émoji 🎉 *bold* _it_ @channel', SlackProvider::escapeText('Café — "quotes" \'n\' émoji 🎉 *bold* _it_ @channel'));
        $this->assertSame('&amp;lt;', SlackProvider::escapeText('&lt;'));
    }

    public function test_an_empty_post_fails_without_calling_slack(): void
    {
        Http::fake();

        $result = $this->provider()->publish($this->account(), new SocialPost(['content' => '   ']));

        $this->assertFalse($result->success);
        $this->assertSame('Slack needs a caption or media to post.', $result->error);
        Http::assertNothingSent();
    }

    // ---- files ------------------------------------------------------------

    public function test_images_and_video_go_through_the_external_upload_flow_in_one_message(): void
    {
        Http::fake($this->permalinkFake() + [
            'cdn.example/photo.jpg' => Http::response('JPEGBYTES'),
            'cdn.example/clip.mp4' => Http::response('MP4BYTES!!'),
            'slack.com/api/files.getUploadURLExternal' => Http::sequence()
                ->push(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/one', 'file_id' => 'F1'])
                ->push(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/two', 'file_id' => 'F2']),
            'files.slack.com/upload/v1/*' => Http::response('OK - 9'),
            'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true, 'files' => [[
                'id' => 'F1', 'title' => 'photo.jpg', 'permalink' => 'https://acme.slack.com/files/U0BOT/F1/photo.jpg',
                'shares' => ['public' => ['C222' => [['ts' => '1700000000.000200']]]],
            ], ['id' => 'F2']]]),
        ]);

        $result = $this->provider()->publish($this->account(), new SocialPost([
            'content' => 'Launch day <!here>',
            'media' => [
                ['type' => 'image', 'url' => 'https://cdn.example/photo.jpg', 'alt' => 'A rocket'],
                ['type' => 'video', 'url' => 'https://cdn.example/clip.mp4'],
            ],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('1700000000.000200', $result->remotePostId);
        $this->assertSame('https://acme.slack.com/archives/C222/p1700000000000100', $result->remotePostUrl);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/files.getUploadURLExternal'
            && $req['filename'] === 'photo.jpg' && (int) $req['length'] === 9 && $req['alt_txt'] === 'A rocket');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/files.getUploadURLExternal'
            && $req['filename'] === 'clip.mp4' && (int) $req['length'] === 10 && ! isset($req['alt_txt']));
        Http::assertSent(fn (Request $req) => $req->url() === 'https://files.slack.com/upload/v1/one' && $req->body() === 'JPEGBYTES');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://files.slack.com/upload/v1/two' && $req->body() === 'MP4BYTES!!');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/files.completeUploadExternal'
            && $req['channel_id'] === 'C222'
            && $req['initial_comment'] === 'Launch day &lt;!here&gt;'
            && $req['files'] === [['id' => 'F1', 'title' => 'photo.jpg'], ['id' => 'F2', 'title' => 'clip.mp4']]);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'files.upload?') || str_ends_with($req->url(), 'files.upload'));
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'chat.postMessage'));
    }

    public function test_when_the_share_is_not_in_the_response_yet_the_file_stands_in(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('IMG'),
            'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/one', 'file_id' => 'F1']),
            'files.slack.com/*' => Http::response('OK'),
            'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true, 'files' => [[
                'id' => 'F1', 'permalink' => 'https://acme.slack.com/files/U0BOT/F1/image.jpg', 'shares' => [],
            ]]]),
        ]);

        $result = $this->provider()->publish($this->account(), new SocialPost([
            'content' => '',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/no-extension']],
        ]));

        $this->assertTrue($result->success);
        $this->assertSame('F1', $result->remotePostId);
        $this->assertSame('https://acme.slack.com/files/U0BOT/F1/image.jpg', $result->remotePostUrl);
        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/files.getUploadURLExternal' && $req['filename'] === 'image.jpg');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/files.completeUploadExternal' && ! isset($req['initial_comment']));
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'chat.getPermalink'));
    }

    public function test_media_that_cannot_be_downloaded_fails_before_anything_reaches_slack(): void
    {
        Http::fake(['cdn.example/*' => Http::response('nope', 404), 'slack.com/*' => Http::response(['ok' => true])]);

        $result = $this->provider()->publish($this->account(), new SocialPost([
            'content' => 'Hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/gone.jpg']],
        ]));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('could not be downloaded', $result->error);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'slack.com'));
    }

    public function test_a_file_type_the_workspace_blocks_is_reported_as_refused_content(): void
    {
        Http::fake([
            'cdn.example/*' => Http::response('IMG'),
            'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => false, 'error' => 'file_uploads_except_images_disabled']),
        ]);

        $account = $this->account();
        $result = $this->provider()->publish($account, new SocialPost([
            'content' => 'Hi', 'media' => [['type' => 'video', 'url' => 'https://cdn.example/a.mp4']],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame("Slack refused the post's content (file_uploads_except_images_disabled).", $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    // ---- errors -----------------------------------------------------------

    public function test_a_channel_the_bot_is_not_in_says_to_invite_the_app_and_never_tries_another_channel(): void
    {
        Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel'], 200)]);

        $account = $this->account();
        $result = $this->provider()->publish($account, new SocialPost(['content' => 'Hi']));

        $this->assertFalse($result->success);
        $this->assertStringContainsString("can't post in #general", $result->error);
        $this->assertStringContainsString('/invite', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_a_revoked_token_flags_the_account_for_reconnect(): void
    {
        // Http::fake keeps the first stub for a URL, so one stub answers from $error.
        $error = null;
        Http::fake(['slack.com/api/chat.postMessage' => function () use (&$error) {
            return Http::response(['ok' => false, 'error' => $error], 200);
        }]);
        foreach (['token_revoked', 'invalid_auth', 'account_inactive', 'missing_scope'] as $i => $error) {
            $account = $this->account(['channel_id' => 'C'.$i]);

            $result = $this->provider()->publish($account, new SocialPost(['content' => 'Hi']));

            $this->assertFalse($result->success);
            $this->assertMatchesRegularExpression('/reconnect the Slack account/i', $result->error, $error);
            $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status, $error);
        }
    }

    public function test_workspace_permission_and_archived_channel_errors_read_distinctly(): void
    {
        $cases = [
            'restricted_action' => "workspace settings don't let the app post",
            'is_archived' => 'is archived',
            'msg_too_long' => 'too long for Slack',
            'something_new' => 'Slack refused the post (something_new).',
        ];
        $error = null;
        Http::fake(['slack.com/api/chat.postMessage' => function () use (&$error) {
            return Http::response(['ok' => false, 'error' => $error], 200);
        }]);
        foreach ($cases as $error => $expected) {

            $result = $this->provider()->publish($this->account(['channel_id' => 'C'.$error]), new SocialPost(['content' => 'Hi']));

            $this->assertStringContainsString($expected, $result->error, $error);
        }
    }

    public function test_an_internal_error_is_not_retried_because_the_post_may_have_gone_through(): void
    {
        Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'internal_error'], 200)]);

        $result = $this->provider()->publish($this->account(), new SocialPost(['content' => 'Hi']));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('may have gone through', $result->error);
    }

    public function test_a_rate_limit_is_retried_after_slacks_retry_after(): void
    {
        Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '17'])]);

        try {
            $this->provider()->publish($this->account(), new SocialPost(['content' => 'Hi']));
            $this->fail('Expected a TransientPublishException for the queue to retry.');
        } catch (TransientPublishException $e) {
            $this->assertSame(17, $e->retryAfter);
            $this->assertStringContainsString('rate limit', $e->getMessage());
        }
    }

    public function test_the_channel_posting_limit_at_http_200_is_retried_too(): void
    {
        Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'rate_limited'], 200)]);

        $this->expectException(TransientPublishException::class);
        $this->provider()->publish($this->account(), new SocialPost(['content' => 'Hi']));
    }

    public function test_job_lets_a_rate_limit_through_for_the_queue_and_fails_the_target_once_retries_are_spent(): void
    {
        Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '5'])]);
        $target = $this->target($this->account());

        try {
            $this->runJob($target);
            $this->fail('Expected the rate limit to reach the queue.');
        } catch (TransientPublishException $e) {
            $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->fresh()->status);
        }

        (new PublishToSlackJob($target->id))->failed($e);
        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringContainsString('rate limit', $target->fresh()->error);
    }

    // ---- job + composer -----------------------------------------------------

    public function test_job_publishes_the_target_and_stores_the_permalink(): void
    {
        Http::fake($this->permalinkFake() + [
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C222', 'ts' => '1700000000.000100']),
        ]);
        $target = $this->target($this->account());

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status);
        $this->assertSame('1700000000.000100', $target->platform_post_id);
        $this->assertSame('https://acme.slack.com/archives/C222/p1700000000000100', $target->meta['url']);
    }

    public function test_job_with_no_channel_chosen_and_several_connected_fails_without_posting(): void
    {
        Http::fake();
        $this->account();
        $this->account(['channel_id' => 'C333']);
        $target = $this->target(null);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertStringContainsString('Choose which channel', $target->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_job_with_no_channel_chosen_uses_the_only_connected_one(): void
    {
        Http::fake($this->permalinkFake() + [
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C222', 'ts' => '1.1']),
        ]);
        $this->account();
        $target = $this->target(null);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status);
    }

    public function test_job_marks_the_target_failed_when_the_token_is_dead(): void
    {
        Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'token_revoked'])]);
        $account = $this->account();
        $target = $this->target($account);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->fresh()->status);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_publishing_by_platform_with_several_channels_connected_is_refused(): void
    {
        Queue::fake();
        $first = $this->account();
        $this->account(['channel_id' => 'C333']);

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Hi', 'platforms' => ['slack'], 'status' => Post::STATUS_POSTED,
        ])->assertStatus(422)->assertJsonPath('message', 'Several Slack channels are connected. Choose which Slack channel to post to before publishing or scheduling.');

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Hi', 'targets' => [['platform' => 'slack', 'social_account_id' => $first->id]], 'status' => Post::STATUS_POSTED,
        ])->assertCreated();

        // A draft may still be saved without a channel.
        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Hi', 'platforms' => ['slack'], 'status' => Post::STATUS_DRAFT,
        ])->assertCreated();
    }

    public function test_publishing_an_empty_slack_post_is_refused(): void
    {
        Queue::fake();
        $account = $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => '', 'targets' => [['platform' => 'slack', 'social_account_id' => $account->id]], 'status' => Post::STATUS_POSTED,
        ])->assertStatus(422)->assertJsonPath('message', 'Slack needs a caption or media before publishing or scheduling.');
    }

    public function test_caption_and_media_limits(): void
    {
        $this->assertSame(4000, CaptionRules::limit('slack'));
        $this->assertNull(CaptionRules::captionError('slack', str_repeat('a', 4000)));
        $this->assertNotNull(CaptionRules::captionError('slack', str_repeat('a', 4001)));

        $eleven = array_fill(0, 11, ['type' => 'image', 'url' => 'https://cdn.example/a.jpg']);
        $this->assertSame('Slack allows at most 10 media items per post.', MediaRules::error('slack', $eleven));
        $this->assertNull(MediaRules::error('slack', [['type' => 'image', 'url' => 'https://x/a.jpg'], ['type' => 'video', 'url' => 'https://x/b.mp4']]));
    }

    public function test_dispatcher_routes_slack_targets_to_the_slack_job(): void
    {
        Queue::fake();
        $target = $this->target($this->account());

        $this->assertTrue(PostPublishDispatcher::dispatchTarget($target));
        Queue::assertPushed(PublishToSlackJob::class, fn ($job) => $job->postTargetId === $target->id);
    }

    public function test_slack_is_offered_to_the_ai_only_while_it_has_app_keys(): void
    {
        $this->assertContains('slack', CreatePost::availablePlatforms($this->user));
        $this->assertStringContainsString('Slack account is one channel', CreatePost::slackRule());

        config(['social.platforms.slack.client_id' => null]);
        $this->assertNotContains('slack', CreatePost::availablePlatforms($this->user));
        $this->assertSame('', CreatePost::slackRule());
    }

    // ---- disconnect ---------------------------------------------------------

    public function test_disconnecting_a_channel_keeps_the_token_while_the_workspace_has_another(): void
    {
        Http::fake(['slack.com/api/auth.revoke' => Http::response(['ok' => true, 'revoked' => true])]);
        $first = $this->account();
        $second = $this->account(['channel_id' => 'C333']);

        $this->withHeaders($this->auth($this->user))->deleteJson("/api/social/accounts/{$first->id}")->assertOk();

        $this->assertNull($first->fresh());
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'auth.revoke'));

        $this->withHeaders($this->auth($this->user))->deleteJson("/api/social/accounts/{$second->id}")->assertOk();

        $this->assertNull($second->fresh());
        Http::assertSent(fn (Request $req) => $req->url() === 'https://slack.com/api/auth.revoke'
            && $req->hasHeader('Authorization', 'Bearer xoxb-bot-token'));
    }

    public function test_another_users_channel_in_the_same_workspace_keeps_the_token_alive(): void
    {
        Http::fake();
        $mine = $this->account();
        $this->account(['channel_id' => 'C555'], User::factory()->create());

        $this->withHeaders($this->auth($this->user))->deleteJson("/api/social/accounts/{$mine->id}")->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_failed_revoke_never_blocks_the_disconnect(): void
    {
        Http::fake(['slack.com/api/auth.revoke' => Http::response('upstream down', 503)]);
        $account = $this->account();

        $this->withHeaders($this->auth($this->user))->deleteJson("/api/social/accounts/{$account->id}")->assertOk();

        $this->assertNull($account->fresh());
    }

    public function test_the_ai_disconnect_tool_removes_every_channel_and_revokes_once(): void
    {
        Http::fake(['slack.com/api/auth.revoke' => Http::response(['ok' => true, 'revoked' => true])]);
        $this->account();
        $this->account(['channel_id' => 'C333']);
        request()->setUserResolver(fn () => $this->user);

        $result = app(\App\Mcp\Tools\DisconnectAccount::class)->handle(['platform' => 'slack']);

        $this->assertFalse($result->isError);
        $this->assertSame(0, $this->user->socialAccounts()->where('platform', 'slack')->count());
        Http::assertSentCount(1);
    }
}
