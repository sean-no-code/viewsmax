<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToFacebookJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\FacebookProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Facebook Page publishing through the Post/PostTarget pipeline. The provider
 * (FacebookProvider) already speaks the Graph API; this covers the job bridge.
 */
class FacebookPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function connectPage(): SocialAccount
    {
        return $this->user->socialAccounts()->create([
            'platform' => 'facebook',
            'platform_account_id' => 'page-1',
            'name' => 'My Page',
            'access_token' => 'page-token',
            // Facebook page tokens are long-lived; no expiry recorded.
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function makeTarget(string $caption, array $media = [], ?int $accountId = null): PostTarget
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => $caption,
            'media' => $media,
            'status' => Post::STATUS_POSTED,
        ]);

        return $post->targets()->create([
            'platform' => 'facebook',
            'status' => PostTarget::STATUS_PENDING,
            'social_account_id' => $accountId,
        ]);
    }

    private function runJob(PostTarget $target): void
    {
        (new PublishToFacebookJob($target->id))->handle(app(SocialProviderManager::class));
    }

    /**
     * Fake every Graph call with a 400 whose `error` is whatever $error holds
     * when the request is made. Repeated Http::fake() calls stack (the first
     * stub wins), so table-driven tests need one stub that reads a variable.
     *
     * @param  array<string, mixed>|null  $error  by reference
     */
    private function fakeGraphError(?array &$error): void
    {
        Http::fake(['graph.facebook.com/*' => function () use (&$error) {
            return Http::response(['error' => $error], 400);
        }]);
    }

    public function test_text_post_publishes_to_the_page_feed(): void
    {
        Http::fake([
            'graph.facebook.com/*/page-1/feed' => Http::response(['id' => 'page-1_777']),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('Hello Facebook', [], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('page-1_777', $target->platform_post_id);
        $this->assertStringContainsString('facebook.com', (string) data_get($target->meta, 'url'));
    }

    public function test_image_post_publishes_a_photo(): void
    {
        Http::fake([
            'graph.facebook.com/*/page-1/photos' => Http::response(['id' => '888', 'post_id' => 'page-1_888']),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('With pic', [
            ['type' => 'image', 'url' => 'https://cdn.example/a.jpg'],
        ], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status, (string) $target->error);
        $this->assertSame('page-1_888', $target->platform_post_id);
    }

    public function test_no_connected_page_fails_with_clear_note(): void
    {
        Http::fake();
        $target = $this->makeTarget('No page');

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('facebook', (string) $target->error);
        Http::assertNothingSent();
    }

    public function test_graph_error_marks_target_failed(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'expired token']], 400),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('boom', [], $account->id);

        $this->runJob($target);

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertNotEmpty($target->error);
    }

    /* ---------------- reauth ---------------- */

    public function test_token_and_page_role_errors_flag_the_page_for_reconnect(): void
    {
        $cases = [
            ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463],
            ['message' => 'REVOKED_ACCESS_TOKEN', 'code' => 190],
            ['message' => 'Unpublished posts must be posted to a page as the page itself', 'code' => 100],
            ['message' => '(#100) Page publishing authorization is required', 'code' => 100, 'error_subcode' => 1404078],
            ['message' => 'The permission must be granted before impersonating a user\'s page', 'code' => 100],
            ['message' => 'Access token expired', 'code' => 490],
            // A plain 190 is a dead token (generic classifier).
            ['message' => 'Invalid OAuth access token - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190],
        ];

        $error = null;
        $this->fakeGraphError($error);

        foreach ($cases as $error) {
            $account = $this->connectPage();
            $target = $this->makeTarget('boom', [], $account->id);

            $this->runJob($target);

            $target->refresh();
            $account->refresh();
            $this->assertSame(PostTarget::STATUS_FAILED, $target->status, json_encode($error));
            $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status, json_encode($error));
            $this->assertStringContainsStringIgnoringCase('reconnect', (string) $target->error, json_encode($error));
            $this->assertSame($target->error, $account->last_error);
            $this->assertStringNotContainsString('{', (string) $target->error);

            $account->delete();
        }
    }

    public function test_a_page_flagged_for_reconnect_is_not_published_to_again(): void
    {
        Http::fake();
        $account = $this->connectPage();
        $account->markNeedsReauth('dead');
        $target = $this->makeTarget('later', [], $account->id);

        $this->runJob($target);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->refresh()->status);
        Http::assertNothingSent();
    }

    /* ---------------- retry ---------------- */

    public function test_temporary_block_is_handed_to_the_queue_for_a_retry(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Temporarily blocked for policies violations', 'code' => 368, 'error_subcode' => 1390008]], 400),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('fast', [], $account->id);

        try {
            $this->runJob($target);
            $this->fail('expected TransientPublishException');
        } catch (TransientPublishException $e) {
            $this->assertStringContainsString('retried', $e->getMessage());
        }

        // Not failed: the queue's $tries/$backoff retry it; the Page stays connected.
        $this->assertSame(PostTarget::STATUS_PENDING, $target->refresh()->status);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->refresh()->status);
    }

    public function test_html_outage_page_is_retried_even_on_a_4xx(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response('<html><body><h1>Sorry, something went wrong.</h1></body></html>', 400, ['Content-Type' => 'text/html']),
        ]);
        $account = $this->connectPage();
        $target = $this->makeTarget('outage', [], $account->id);

        $this->expectException(TransientPublishException::class);

        $this->runJob($target);
    }

    /* ---------------- readable rejections ---------------- */

    public function test_known_graph_rejections_fail_with_a_readable_message_not_the_raw_json(): void
    {
        $cases = [
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 1366046], '4 MB'],
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 1346003], 'abusive'],
            [['message' => 'Invalid parameter', 'code' => 368, 'error_subcode' => 1404006], 'security check'],
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2069019], 'invalid'],
            [['message' => 'Invalid parameter', 'code' => 368, 'error_subcode' => 1404102], 'Community Standards'],
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 1366051], 'already posted'],
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 1609008], 'facebook.com links'],
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2061006], 'URL'],
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 1349125], 'content format'],
            [['message' => 'Invalid parameter', 'code' => 368, 'error_subcode' => 1404112], 'few days'],
            [['message' => '(#100) Name parameter too long', 'code' => 100], 'too long'],
            [['message' => 'Service temporarily unavailable', 'code' => 2, 'error_subcode' => 1363047], 'temporarily unavailable'],
            [['message' => 'Service temporarily unavailable', 'code' => 2, 'error_subcode' => 1609010], 'temporarily unavailable'],
            [['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 4854002], 'Confirm your identity'],
            [['message' => '(#100) No permission to publish the video', 'code' => 100], 'permission to publish the video'],
            [['message' => 'Error validating access token: You cannot access the app till you log in', 'code' => 190, 'error_subcode' => 459], 'security check'],
            [['message' => 'Error validating access token: The user has not authorized application', 'code' => 190, 'error_subcode' => 492], 'no longer has a role on this Page'],
            [['message' => 'Unsupported get request. Object with ID \'1\' does not exist', 'code' => 100, 'error_subcode' => 33], 'no longer exists'],
            [['message' => '(#200) Requires pages_manage_posts permission', 'code' => 200], 'missing permissions'],
        ];

        $error = null;
        $this->fakeGraphError($error);

        foreach ($cases as [$case, $expected]) {
            $error = $case + ['fbtrace_id' => 'AbCdEf'];
            $account = $this->connectPage();
            $target = $this->makeTarget('boom', [], $account->id);

            $this->runJob($target);

            $target->refresh();
            $this->assertSame(PostTarget::STATUS_FAILED, $target->status, json_encode($error));
            $this->assertStringContainsString($expected, (string) $target->error, json_encode($error));
            $this->assertStringNotContainsString('{', (string) $target->error, json_encode($error));
            // These are final rejections, not dead tokens: the Page stays connected.
            $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->refresh()->status, json_encode($error));

            $account->delete();
        }
    }

    public function test_subcode_33_never_matches_330_or_a_trace_id_containing_33(): void
    {
        $pageGone = FacebookProvider::ERRORS[33][1];
        $cases = [
            ['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 330, 'fbtrace_id' => 'AbCdEf'],
            ['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2207033, 'fbtrace_id' => 'AbCdEf'],
            ['message' => 'Invalid parameter', 'code' => 100, 'fbtrace_id' => 'Ab33Cd'],
            ['message' => 'Invalid parameter', 'code' => 100, 'fbtrace_id' => 'A459Z'],
        ];

        $error = null;
        $this->fakeGraphError($error);

        foreach ($cases as $error) {
            $account = $this->connectPage();
            $target = $this->makeTarget('boom', [], $account->id);

            $this->runJob($target);

            $target->refresh();
            $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
            $this->assertNotSame($pageGone, $target->error, json_encode($error));
            $this->assertStringNotContainsString('security check', (string) $target->error, json_encode($error));
            $this->assertSame('Meta rejected the post: Invalid parameter', $target->error, json_encode($error));

            $account->delete();
        }
    }
}
