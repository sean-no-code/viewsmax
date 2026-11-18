<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToPinterestJob;
use App\Jobs\SendReconnectEmailJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Providers\PinterestProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pinterest gets the shared provider error handling: a 401 flags the account
 * for reconnect (Postiz be325ee1); a 5xx, 429 or "Something went wrong on our
 * end" is retried by the queue (Postiz 18a312b6).
 */
class PinterestErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.pinterest.com/v5';

    private const UNAUTHORIZED = ['code' => 2, 'message' => 'Authentication failed.'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendReconnectEmailJob::class]);
        Http::preventStrayRequests(); // never reach a real API
        $this->user = User::factory()->create();
        config([
            'social.platforms.pinterest.client_id' => 'pin-client',
            'social.platforms.pinterest.client_secret' => 'pin-secret',
            'social.platforms.pinterest.sandbox' => false,
        ]);
    }

    private function account(): SocialAccount
    {
        return $this->user->socialAccounts()->create([
            'platform' => 'pinterest',
            'platform_account_id' => 'pin-user-1',
            'username' => 'creatorlab',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addDays(20),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function boards(): array
    {
        return ['items' => [['id' => 'board-1', 'name' => 'Recipes', 'privacy' => 'PUBLIC']], 'bookmark' => null];
    }

    private function imagePost(string $board = 'board-1'): SocialPost
    {
        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.png']]]);
        $post->board_id = $board;

        return $post;
    }

    private function videoPost(): SocialPost
    {
        $post = new SocialPost(['content' => 'v', 'media' => [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']]]);
        $post->board_id = 'board-1';
        $post->cover_url = 'https://cdn.example/cover.jpg';

        return $post;
    }

    /** Run the publish job for a target on $account and return the refreshed target. */
    private function runJob(SocialAccount $account, string $board = 'board-1', array $media = [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]): PostTarget
    {
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'media' => $media, 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create([
            'platform' => 'pinterest',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHING,
            'options' => ['board_id' => $board],
        ]);

        (new PublishToPinterestJob($target->id))->handle(app(SocialProviderManager::class));

        return $target->refresh();
    }

    private function assertFlaggedOnce(SocialAccount $account): void
    {
        $account->refresh();
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
        $this->assertStringContainsString('reconnect', (string) $account->last_error);
        Queue::assertPushed(SendReconnectEmailJob::class, 1);
    }

    /* ---------------- item 12: a 401 flags the account ---------------- */

    public function test_a_401_on_the_board_list_flags_the_account_and_fails_the_pin(): void
    {
        Http::fake([self::API.'/boards*' => Http::response(self::UNAUTHORIZED, 401)]);
        $account = $this->account();

        $target = $this->runJob($account);

        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('Pinterest authorization expired or was revoked — reconnect the account.', $target->error);
        $this->assertFlaggedOnce($account);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v5/pins'));
    }

    public function test_a_401_on_the_board_list_endpoint_flags_the_account(): void
    {
        Http::fake([self::API.'/boards*' => Http::response(self::UNAUTHORIZED, 401)]);
        $account = $this->account();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->user->createToken('t')->plainTextToken, 'Accept' => 'application/json'])
            ->getJson("/api/social/accounts/{$account->id}/boards")
            ->assertStatus(502);

        $this->assertFlaggedOnce($account);
    }

    public function test_a_401_on_the_direct_board_lookup_flags_the_account(): void
    {
        Http::fake([
            self::API.'/boards/board-new' => Http::response(self::UNAUTHORIZED, 401),
            self::API.'/boards*' => Http::response($this->boards()),
        ]);
        $account = $this->account();

        $target = $this->runJob($account, 'board-new');

        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertFlaggedOnce($account);
    }

    public function test_a_401_on_pin_create_flags_the_account(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response(self::UNAUTHORIZED, 401),
        ]);
        $account = $this->account();

        $result = (new PinterestProvider)->publish($account, $this->imagePost());

        $this->assertFalse($result->success);
        $this->assertSame('Pinterest authorization expired or was revoked — reconnect the account.', $result->error);
        $this->assertFlaggedOnce($account);
    }

    public function test_a_401_on_media_register_flags_the_account(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/media' => Http::response(self::UNAUTHORIZED, 401),
            'cdn.example/clip.mp4' => Http::response('VIDEO-BYTES'),
        ]);
        $account = $this->account();

        $result = (new PinterestProvider)->publish($account, $this->videoPost());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('reconnect', (string) $result->error);
        $this->assertFlaggedOnce($account);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v5/pins'));
    }

    public function test_a_second_401_does_not_send_a_second_email(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response(self::UNAUTHORIZED, 401),
        ]);
        $account = $this->account();

        (new PinterestProvider)->publish($account, $this->imagePost());
        (new PinterestProvider)->publish($account->fresh(), $this->imagePost());

        $this->assertFlaggedOnce($account);
    }

    /* ---------------- item 13: transient errors are retried ---------------- */

    /** @return array<string, array{int, array<string, mixed>|string}> */
    public static function transientResponses(): array
    {
        return [
            '503' => [503, 'Service Unavailable'],
            '500' => [500, ['code' => 0, 'message' => 'Internal error']],
            '429' => [429, ['code' => 8, 'message' => 'Rate limit exceeded']],
            'something went wrong' => [400, ['code' => 2787, 'message' => 'Sorry! Something went wrong on our end.']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transientResponses')]
    public function test_a_transient_pin_create_error_is_handed_to_the_queue(int $status, array|string $body): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response($body, $status),
        ]);
        $account = $this->account();

        try {
            (new PinterestProvider)->publish($account, $this->imagePost());
            $this->fail('Expected a TransientPublishException.');
        } catch (TransientPublishException) {
        }

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_a_transient_board_list_error_is_handed_to_the_queue(): void
    {
        Http::fake([self::API.'/boards*' => Http::response('Bad Gateway', 502)]);

        $this->expectException(TransientPublishException::class);

        (new PinterestProvider)->publish($this->account(), $this->imagePost());
    }

    public function test_a_transient_media_register_error_is_handed_to_the_queue(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/media' => Http::response(['code' => 2787, 'message' => 'Sorry! Something went wrong on our end.'], 500),
            'cdn.example/clip.mp4' => Http::response('VIDEO-BYTES'),
        ]);

        $this->expectException(TransientPublishException::class);

        (new PinterestProvider)->publish($this->account(), $this->videoPost());
    }

    public function test_the_job_rethrows_a_transient_error_and_leaves_the_target_for_the_retry(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response('Service Unavailable', 503),
        ]);
        $account = $this->account();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']], 'status' => Post::STATUS_POSTED]);
        $target = $post->targets()->create([
            'platform' => 'pinterest', 'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHING, 'options' => ['board_id' => 'board-1'],
        ]);

        try {
            (new PublishToPinterestJob($target->id))->handle(app(SocialProviderManager::class));
            $this->fail('Expected the job to rethrow for the queue.');
        } catch (TransientPublishException) {
        }

        $this->assertSame(PostTarget::STATUS_PUBLISHING, $target->fresh()->status);
        $this->assertNull($target->fresh()->error);
    }

    public function test_a_final_pin_error_is_not_retried(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response(['code' => 29, 'message' => 'Apps with Trial access may not create Pins in production'], 403),
        ]);

        $result = (new PinterestProvider)->publish($this->account(), $this->imagePost());

        $this->assertFalse($result->success);
    }

    /* ---------------- item 14: worded errors, never raw JSON ---------------- */

    public function test_a_link_blocked_as_spam_gets_a_worded_message(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response(['code' => 9, 'message' => 'We blocked this link because it may lead to spam.'], 400),
        ]);

        $result = (new PinterestProvider)->publish($this->account(), $this->imagePost());

        $this->assertFalse($result->success);
        $this->assertSame('Pinterest blocked the link on this Pin because it may lead to spam. Check the link, or contact Pinterest about your website.', $result->error);
    }

    public function test_an_unmapped_pin_error_shows_pinterests_sentence_not_its_json(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response(['code' => 29, 'message' => 'Apps with Trial access may not create Pins in production'], 403),
        ]);

        $result = (new PinterestProvider)->publish($this->account(), $this->imagePost());

        $this->assertSame('Pinterest could not create the Pin: Apps with Trial access may not create Pins in production.', $result->error);
        $this->assertStringNotContainsString('{', (string) $result->error);
    }

    public function test_a_pin_error_without_a_message_names_the_http_status(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/pins' => Http::response('<html>Forbidden</html>', 403),
        ]);

        $result = (new PinterestProvider)->publish($this->account(), $this->imagePost());

        $this->assertSame('Pinterest could not create the Pin (HTTP 403).', $result->error);
    }

    public function test_a_board_list_error_is_worded(): void
    {
        Http::fake([self::API.'/boards*' => Http::response(['code' => 3, 'message' => 'Not permitted'], 403)]);

        $target = $this->runJob($this->account());

        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertSame('Pinterest could not load your boards: Not permitted.', $target->error);
    }

    public function test_a_media_register_error_is_worded(): void
    {
        Http::fake([
            self::API.'/boards*' => Http::response($this->boards()),
            self::API.'/media' => Http::response(['code' => 3, 'message' => 'Video uploads are not enabled for this app'], 403),
            'cdn.example/clip.mp4' => Http::response('VIDEO-BYTES'),
        ]);

        $result = (new PinterestProvider)->publish($this->account(), $this->videoPost());

        $this->assertSame('Pinterest video upload could not start: Video uploads are not enabled for this app.', $result->error);
    }
}
