<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Providers\LinkedInProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * LinkedInProvider publish path: escaped commentary, image upload
 * failures fail the post, images are polled until AVAILABLE before
 * the post is created, multi-image posts and transient
 * errors only ever thrown before the create call succeeds.
 */
class LinkedInProviderPublishTest extends TestCase
{
    use RefreshDatabase;

    private const POSTS = 'api.linkedin.com/rest/posts';

    private const INIT = 'api.linkedin.com/rest/images?action=initializeUpload';

    private const STATUS = 'api.linkedin.com/rest/images/*';

    private const UPLOAD = 'uploads.linkedin.test/*';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function account(): SocialAccount
    {
        return User::factory()->create()->socialAccounts()->create([
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'scopes' => ['w_member_social'],
            'metadata' => ['author_urn' => 'urn:li:person:li-1'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function provider(): LinkedInProvider
    {
        return app(LinkedInProvider::class);
    }

    private function socialPost(string $content, int $images = 0): SocialPost
    {
        return new SocialPost([
            'content' => $content,
            'media' => array_map(fn (int $i) => [
                'type' => 'image', 'url' => "https://cdn.example/{$i}.jpg", 'alt' => $i === 1 ? 'first' : null,
            ], $images > 0 ? range(1, $images) : []),
        ]);
    }

    /** initializeUpload answers for $count images, in order. */
    private function initSequence(int $count)
    {
        $seq = Http::sequence();
        for ($i = 1; $i <= $count; $i++) {
            $seq->push(['value' => ['uploadUrl' => "https://uploads.linkedin.test/{$i}", 'image' => "urn:li:image:{$i}"]]);
        }

        return $seq;
    }

    /** Stubs for the whole image pipeline; $status is the GET /rest/images answer. */
    private function fakeImagePipeline(int $count, $status, $posts = null): void
    {
        Http::fake([
            self::INIT => $this->initSequence($count),
            self::UPLOAD => Http::response('', 201),
            'cdn.example/*' => Http::response('binary', 200, ['Content-Type' => 'image/jpeg']),
            self::STATUS => $status,
            self::POSTS => $posts ?? Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']),
        ]);
    }

    private function postsSent(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/rest/posts'))->count();
    }

    /* ---------- escaped commentary ---------- */

    public function test_commentary_is_escaped_in_the_post_payload(): void
    {
        Http::fake([self::POSTS => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1'])]);

        $result = $this->provider()->publish($this->account(), $this->socialPost('Big (news) #launch @[Acme](urn:li:organization:1)'));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/rest/posts')
            && $r['commentary'] === 'Big \(news\) \#launch @[Acme](urn:li:organization:1)');
    }

    /* ---------- image upload failures and AVAILABLE polling ---------- */

    public function test_single_image_is_polled_until_available_then_attached_as_media(): void
    {
        $this->fakeImagePipeline(1, Http::sequence()
            ->push(['status' => 'PROCESSING'])
            ->push(['status' => 'AVAILABLE']));

        $result = $this->provider()->publish($this->account(), $this->socialPost('Pic', 1));

        $this->assertTrue($result->success, (string) $result->error);
        Sleep::assertSleptTimes(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/rest/posts')
            && $r['content']['media']['id'] === 'urn:li:image:1'
            && ! isset($r['content']['multiImage']));
        $this->assertSame(1, $this->postsSent());
    }

    public function test_processing_failed_fails_the_post_with_linkedins_reason_and_never_posts(): void
    {
        $this->fakeImagePipeline(1, Http::response(['status' => 'PROCESSING_FAILED', 'processingFailureReason' => 'Image dimensions exceed the limit']));

        $result = $this->provider()->publish($this->account(), $this->socialPost('Pic', 1));

        $this->assertFalse($result->success);
        $this->assertSame('LinkedIn image processing failed: Image dimensions exceed the limit', $result->error);
        $this->assertSame(0, $this->postsSent());
    }

    public function test_member_token_that_cannot_read_image_status_waits_one_grace_cycle_then_posts(): void
    {
        // w_member_social tokens are write-only on /rest/images (403 on GET):
        // wait one cycle instead of polling, then trust the upload.
        $this->fakeImagePipeline(2, Http::response(['message' => 'Not enough permissions'], 403));

        $result = $this->provider()->publish($this->account(), $this->socialPost('Pics', 2));

        $this->assertTrue($result->success, (string) $result->error);
        Sleep::assertSleptTimes(1);
        Http::assertSentCount(2 + 2 + 2 + 1 + 1); // 2 inits, 2 downloads, 2 PUTs, 1 status read, 1 post
        $this->assertSame(1, $this->postsSent());
    }

    public function test_image_stuck_in_processing_fails_after_a_bounded_number_of_attempts(): void
    {
        $this->fakeImagePipeline(1, Http::response(['status' => 'PROCESSING']));

        $result = $this->provider()->publish($this->account(), $this->socialPost('Pic', 1));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('did not finish processing', (string) $result->error);
        Sleep::assertSleptTimes(10);
        $this->assertSame(0, $this->postsSent());
    }

    public function test_binary_upload_failure_fails_the_post_instead_of_posting_text_only(): void
    {
        Http::fake([
            self::INIT => $this->initSequence(1),
            self::UPLOAD => Http::response('bad signature', 400),
            'cdn.example/*' => Http::response('binary', 200, ['Content-Type' => 'image/jpeg']),
            self::POSTS => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']),
        ]);

        $result = $this->provider()->publish($this->account(), $this->socialPost('Pic', 1));

        $this->assertFalse($result->success);
        $this->assertSame('LinkedIn image upload failed (HTTP 400): bad signature', $result->error);
        $this->assertSame(0, $this->postsSent());
    }

    /* ---------- multi-image posts ---------- */

    public function test_several_images_are_all_uploaded_and_sent_as_multi_image_content(): void
    {
        $this->fakeImagePipeline(3, Http::response(['status' => 'AVAILABLE']));

        $result = $this->provider()->publish($this->account(), $this->socialPost('Three', 3));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSentCount(3 + 3 + 3 + 3 + 1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/rest/posts')
            && ! isset($r['content']['media'])
            && $r['content']['multiImage']['images'] === [
                ['id' => 'urn:li:image:1', 'altText' => 'first'],
                ['id' => 'urn:li:image:2'],
                ['id' => 'urn:li:image:3'],
            ]);
    }

    public function test_more_than_twenty_images_is_refused_before_anything_is_uploaded(): void
    {
        Http::fake();

        $result = $this->provider()->publish($this->account(), $this->socialPost('Too many', 21));

        $this->assertFalse($result->success);
        $this->assertSame('LinkedIn allows at most 20 images per post (21 attached).', $result->error);
        Http::assertNothingSent();
    }

    /* ---------- transient errors before the create call ---------- */

    public function test_retryable_bodies_on_the_create_call_ask_the_queue_to_retry(): void
    {
        foreach ([[400, '{"message":"Unable to obtain activity"}'], [403, '{"message":"The resource is forbidden"}'], [200, 'Service Unavailable']] as [$status, $body]) {
            Http::fake([self::POSTS => Http::response($body, $status === 200 ? 502 : $status)]);

            try {
                $this->provider()->publish($this->account(), $this->socialPost('Hi'));
                $this->fail("HTTP {$status} {$body} should be transient");
            } catch (TransientPublishException $e) {
                $this->assertStringContainsString('retrying automatically', $e->getMessage());
            }
        }
    }

    public function test_a_plain_rejection_is_a_failure_not_a_retry(): void
    {
        Http::fake([self::POSTS => Http::response('{"message":"Content is a duplicate"}', 422)]);

        $result = $this->provider()->publish($this->account(), $this->socialPost('Hi'));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('duplicate', (string) $result->error);
    }

    public function test_a_server_error_after_the_post_was_created_never_throws_or_reposts(): void
    {
        $account = $this->account();
        Http::fake([
            self::POSTS => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']),
            'api.linkedin.com/rest/socialActions/*' => Http::response('Service Unavailable', 503),
        ]);

        $published = $this->provider()->publish($account, $this->socialPost('Hi'));
        $this->assertTrue($published->success, (string) $published->error);

        // The first-comment call fails transiently: that is a failed comment,
        // never a retry of the publish that already went out.
        $comment = $this->provider()->comment($account, 'urn:li:share:1', 'First (comment)');

        $this->assertFalse($comment->success);
        $this->assertSame(1, $this->postsSent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/comments') && $r['message']['text'] === 'First \(comment\)');
    }

    public function test_a_401_on_comment_flags_the_account_for_reconnect(): void
    {
        $account = $this->account();
        Http::fake(['api.linkedin.com/rest/socialActions/*' => Http::response('', 401)]);

        $comment = $this->provider()->comment($account, 'urn:li:share:1', 'Hello');

        $this->assertFalse($comment->success);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
        $this->assertSame('LinkedIn authorization expired or was revoked — reconnect the account.', $comment->error);
    }
}
