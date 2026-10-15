<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Jobs\PublishToInstagramJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\MetaGraphErrors;
use App\Services\Social\Providers\InstagramProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * InstagramProvider::publish against a faked Graph API: container status
 * reasons reach the user, the polling budget fits the job timeout and
 * transient errors are handed to the queue.
 */
class InstagramPublishTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPH = 'https://graph.instagram.com/v25.0';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function account(): SocialAccount
    {
        return User::factory()->create()->socialAccounts()->create([
            'platform' => 'instagram',
            'platform_account_id' => 'ig-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDays(30),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /** @param  array<int, array{type: string, url: string}>  $media */
    private function socialPost(array $media, string $caption = 'A caption'): SocialPost
    {
        return new SocialPost(['content' => $caption, 'media' => $media]);
    }

    private function reel(): SocialPost
    {
        return $this->socialPost([['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']]);
    }

    /** Compact view of every request so a sequence can be asserted in one go. */
    private function sent(): array
    {
        return Http::recorded()->map(function (array $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $path = str_replace(self::GRAPH.'/', '', strtok($request->url(), '?'));
            $data = $request->method() === 'GET' ? [] : collect($request->data())->except('access_token')->all();

            return [$request->method(), $path, $data];
        })->all();
    }

    /* ---------------- container status ---------------- */

    public function test_container_error_status_is_mapped_to_a_readable_reason(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response([
                'status_code' => 'ERROR',
                'status' => 'Error: The video file you selected is in a format that we don\'t support. Error code: 2207026',
                'id' => 'container-1',
            ]),
        ]);
        $account = $this->account();

        $result = (new InstagramProvider)->publish($account, $this->reel());

        $this->assertFalse($result->success);
        $this->assertSame('Unsupported video format', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'fields=status_code%2Cstatus'));
    }

    /** @return array<string, array{int, string}> */
    public static function videoSubcodes(): array
    {
        return [
            '2207053 video limits' => [2207053, 'exceeds Instagram\'s limits (300MB, 1920px width, 25Mbps bitrate)'],
            '2207085 processing' => [2207085, 'check the video format, duration and resolution'],
        ];
    }

    #[DataProvider('videoSubcodes')]
    public function test_container_video_limit_and_processing_subcodes_reach_the_user(int $subcode, string $expected): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'ERROR', 'status' => "Error: Media upload has failed with error code {$subcode}"]),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->reel());

        $this->assertStringContainsString($expected, (string) $result->error);
    }

    public function test_container_checkpoint_status_flags_the_account_for_reconnect(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response([
                'status_code' => 'EXPIRED',
                'status' => 'Error: You cannot access the app till you log in to www.instagram.com and follow the instructions given.',
            ]),
        ]);
        $account = $this->account();

        $result = (new InstagramProvider)->publish($account, $this->reel());

        $this->assertSame(MetaGraphErrors::MESSAGE_CHECKPOINT, $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_container_retryable_status_is_handed_to_the_queue(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'ERROR', 'status' => 'Error: Media processing failed. Error code: 2207082']),
        ]);

        $this->expectException(TransientPublishException::class);
        $this->expectExceptionMessage('set the original video volume to 0');

        (new InstagramProvider)->publish($this->account(), $this->reel());
    }

    public function test_container_error_without_a_known_code_shows_metas_own_reason(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'ERROR', 'status' => 'Error: Something specific happened.']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->reel());

        $this->assertSame('Meta rejected the post: Error: Something specific happened.', $result->error);
    }

    public function test_container_status_call_failure_goes_through_the_classifier(): void
    {
        // The token died while the video was processing.
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190]], 400),
        ]);
        $account = $this->account();

        $result = (new InstagramProvider)->publish($account, $this->reel());

        $this->assertSame(MetaGraphErrors::MESSAGE_REAUTH, $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    /* ---------------- polling budget ---------------- */

    public function test_polling_waits_eight_minutes_in_thirty_second_steps_then_gives_up(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->reel());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('did not complete in time', (string) $result->error);

        $polls = Http::recorded(fn (Request $r) => $r->method() === 'GET')->count();
        $this->assertSame(InstagramProvider::CONTAINER_POLL_ATTEMPTS, $polls);
        $this->assertSame(16, $polls);
        Sleep::assertSleptTimes($polls - 1);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 30, $polls - 1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));
    }

    public function test_polling_budget_fits_the_job_timeout(): void
    {
        $budget = InstagramProvider::CONTAINER_POLL_ATTEMPTS * InstagramProvider::CONTAINER_POLL_INTERVAL_SECONDS;

        $this->assertGreaterThanOrEqual(8 * 60, $budget);
        $this->assertLessThan((new PublishToInstagramJob(1))->timeout, $budget);
    }

    public function test_a_reel_publishes_once_the_container_is_finished(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['id' => 'media-9']),
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->reel());

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('media-9', $result->remotePostId);
        Sleep::assertSleptTimes(2);
    }

    /* ---------------- transient errors ---------------- */

    public function test_a_5xx_on_container_creation_is_handed_to_the_queue(): void
    {
        Http::fake(['*/media' => Http::response('Bad Gateway', 502)]);

        $this->expectException(TransientPublishException::class);

        (new InstagramProvider)->publish($this->account(), $this->reel());
    }

    public function test_an_unknown_error_on_media_publish_is_handed_to_the_queue(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['error' => ['message' => 'An unknown error occurred', 'type' => 'OAuthException', 'code' => 1]], 500),
            '*/media' => Http::response(['id' => 'container-1']),
        ]);

        $this->expectException(TransientPublishException::class);

        (new InstagramProvider)->publish($this->account(), $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));
    }

    public function test_media_publish_rejections_are_readable_and_keep_the_account_connected(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 9, 'error_subcode' => 2207042]], 400),
            '*/media' => Http::response(['id' => 'container-1']),
        ]);
        $account = $this->account();

        $result = (new InstagramProvider)->publish($account, $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));

        $this->assertSame('You have reached the maximum of 25 posts per day, allowed for your account', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    /* ---------------- comments ---------------- */

    public function test_comment_errors_are_classified_but_never_thrown(): void
    {
        // Comment jobs run once (a retry would double-post), so even a 5xx is a reported failure.
        Http::fake(['*/comments' => Http::response('Bad Gateway', 502)]);
        $account = $this->account();

        $result = (new InstagramProvider)->comment($account, 'media-1', 'nice');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('HTTP 502', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_comment_with_a_dead_token_flags_the_account(): void
    {
        Http::fake(['*/comments' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'type' => 'OAuthException', 'code' => 190]], 400)]);
        $account = $this->account();

        $result = (new InstagramProvider)->comment($account, 'media-1', 'nice');

        $this->assertSame(MetaGraphErrors::MESSAGE_REAUTH, $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }
}
