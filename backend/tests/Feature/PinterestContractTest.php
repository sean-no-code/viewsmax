<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Providers\PinterestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Runs the real PinterestProvider — no faked responses — against a local mock
 * server generated from Pinterest's official OpenAPI spec
 * (github.com/pinterest/api-description). The mock rejects any request that
 * breaks the spec (wrong field, over-long title, missing auth), so a pass
 * means every request we send is one Pinterest documents as valid.
 *
 * Skipped unless PINTEREST_CONTRACT_URL is set. To run:
 *
 *   curl -sL https://raw.githubusercontent.com/pinterest/api-description/main/v5/openapi.json -o /tmp/pinterest.json
 *   npx @stoplight/prism-cli@5.14.2 mock /tmp/pinterest.json -p 4010 > /tmp/prism.log 2>&1 &
 *   PINTEREST_CONTRACT_URL=http://127.0.0.1:4010 PINTEREST_CONTRACT_LOG=/tmp/prism.log \
 *     php artisan test --filter=PinterestContractTest
 *
 * PINTEREST_CONTRACT_LOG is the mock's log: each test fails if the mock
 * logged a violation in one of our requests. (Not --errors: that also
 * checks the mock's own sample answers, and some of Pinterest's samples
 * break Pinterest's own schema, e.g. non-numeric example ids.)
 */
class PinterestContractTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private int $logOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) env('PINTEREST_CONTRACT_URL', '');
        if ($this->base === '') {
            $this->markTestSkipped('Set PINTEREST_CONTRACT_URL to a mock of the Pinterest spec to run.');
        }

        config([
            'social.platforms.pinterest.client_id' => 'contract-client',
            'social.platforms.pinterest.client_secret' => 'contract-secret',
            'social.platforms.pinterest.api_base' => $this->base,
        ]);

        $log = env('PINTEREST_CONTRACT_LOG');
        $this->logOffset = $log && is_file($log) ? filesize($log) : 0;
    }

    protected function tearDown(): void
    {
        $log = env('PINTEREST_CONTRACT_LOG');
        if ($this->base !== '' && $log && is_file($log)) {
            $new = (string) file_get_contents($log, false, null, $this->logOffset);
            preg_match_all('/Violation: (request\.\S+ .*)/', $new, $m);
            $this->assertSame([], $m[1], 'The mock logged spec violations in our requests.');
        }

        parent::tearDown();
    }

    private function account(User $user): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'pinterest',
            'platform_account_id' => '2783136121146311751',
            'username' => 'creatorlab',
            'access_token' => 'pina_contract',
            'refresh_token' => 'pinr_contract',
            'token_expires_at' => now()->addDays(30),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_connect_flow_requests_match_the_spec(): void
    {
        $accounts = (new PinterestProvider)->connectFromCode(User::factory()->create(), 'code-123', 'https://app.example/social/callback');

        $account = $accounts->first();
        $this->assertNotEmpty($account->access_token);
        $this->assertNotEmpty((new PinterestProvider)->boards($account), 'No boards came back from the spec example.');
    }

    public function test_token_refresh_request_matches_the_spec(): void
    {
        $account = $this->account(User::factory()->create());
        $account->update(['token_expires_at' => now()->addDay()]);

        $refreshed = (new PinterestProvider)->ensureFreshToken($account);

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $refreshed->status);
        $this->assertNotSame('pina_contract', $refreshed->access_token);
    }

    public function test_image_pin_request_matches_the_spec(): void
    {
        $account = $this->account(User::factory()->create());
        $boardId = (new PinterestProvider)->boards($account)[0]['id'];

        // Every field the composer can send, at its maximum length.
        $post = new SocialPost([
            'content' => str_repeat('d', 800),
            'media' => [['type' => 'image', 'url' => 'https://i.pinimg.com/564x/example.jpg']],
            'link' => 'https://example.com/page',
        ]);
        $post->board_id = $boardId;
        $post->title = str_repeat('t', 100);
        $post->alt_text = str_repeat('a', 500);

        $result = (new PinterestProvider)->publish($account, $post);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertNotEmpty($result->remotePostId);
    }

    public function test_create_board_request_matches_the_spec(): void
    {
        $board = (new PinterestProvider)->createBoard($this->account(User::factory()->create()), 'Weeknight dinners', 'Quick meals');

        $this->assertNotEmpty($board['id']);
    }

    public function test_image_and_cover_sent_as_data_match_the_spec(): void
    {
        // The fallback when Pinterest refuses an image link: the image itself.
        $account = $this->account(User::factory()->create());
        $api = Http::withToken($account->access_token)->acceptJson();
        $boardId = (new PinterestProvider)->boards($account)[0]['id'];
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $image = $api->post($this->base.'/pins', [
            'board_id' => $boardId,
            'media_source' => ['source_type' => 'image_base64', 'content_type' => 'image/png', 'data' => $png],
        ]);
        $this->assertTrue($image->successful(), $image->body());

        $video = $api->post($this->base.'/pins', [
            'board_id' => $boardId,
            'media_source' => ['source_type' => 'video_id', 'media_id' => '12345', 'cover_image_data' => $png, 'cover_image_content_type' => 'image/png'],
        ]);
        $this->assertTrue($video->successful(), $video->body());
    }

    public function test_video_steps_match_the_spec(): void
    {
        // The upload itself goes to Amazon S3, not Pinterest, so it can't be
        // mocked from the spec. The Pinterest calls around it are checked here.
        $account = $this->account(User::factory()->create());
        $api = Http::withToken($account->access_token)->acceptJson();

        $register = $api->post($this->base.'/media', ['media_type' => 'video']);
        $this->assertTrue($register->successful(), $register->body());
        $this->assertNotEmpty($register->json('media_id'));
        $this->assertNotEmpty($register->json('upload_url'));

        $status = $api->get($this->base.'/media/'.$register->json('media_id'));
        $this->assertTrue($status->successful(), $status->body());
        $this->assertContains($status->json('status'), ['registered', 'processing', 'succeeded', 'failed']);

        $boardId = (new PinterestProvider)->boards($account)[0]['id'];
        $pin = $api->post($this->base.'/pins', [
            'board_id' => $boardId,
            'title' => 'Video Pin',
            'description' => 'Contract check',
            'media_source' => [
                'source_type' => 'video_id',
                'media_id' => (string) $register->json('media_id'),
                'cover_image_url' => 'https://i.pinimg.com/564x/cover.jpg',
            ],
        ]);
        $this->assertTrue($pin->successful(), $pin->body());
    }
}
