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
 * reasons reach the user, the polling budget fits the job timeout, transient
 * errors are handed to the queue, and 2–20 media become a carousel.
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
            '*/container-1?*' => Http::response(['status_code' => 'FINISHED']),
        ]);

        $this->expectException(TransientPublishException::class);

        (new InstagramProvider)->publish($this->account(), $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));
    }

    public function test_media_publish_rejections_are_readable_and_keep_the_account_connected(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 9, 'error_subcode' => 2207042]], 400),
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'FINISHED']),
        ]);
        $account = $this->account();

        $result = (new InstagramProvider)->publish($account, $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));

        $this->assertSame('You have reached the maximum of 25 posts per day, allowed for your account', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    /* ---------------- 2207052 media fetch failed is retried (Postiz b95e1e2c) ---------------- */

    public function test_media_fetch_failed_on_container_creation_is_handed_to_the_queue(): void
    {
        Http::fake(['*/media' => Http::response(['error' => [
            'message' => 'The media could not be fetched from this uri', 'type' => 'OAuthException', 'code' => 9004, 'error_subcode' => 2207052,
        ]], 400)]);
        $account = $this->account();

        try {
            (new InstagramProvider)->publish($account, $this->reel());
            $this->fail('Expected a TransientPublishException.');
        } catch (TransientPublishException $e) {
            $this->assertStringContainsString('Media fetch failed', $e->getMessage());
        }
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_media_fetch_failed_in_the_container_status_is_handed_to_the_queue(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'ERROR', 'status' => 'Error: Media download has failed. Error code: 2207052']),
        ]);

        $this->expectException(TransientPublishException::class);

        (new InstagramProvider)->publish($this->account(), $this->reel());
    }

    /* ---------------- bare "ERROR" status text (Postiz 38918c2f) ---------------- */

    /** @return array<string, array{string, string}> */
    public static function bareStatusTexts(): array
    {
        return [
            'ERROR repeated' => ['ERROR', 'ERROR'],
            'EXPIRED repeated' => ['EXPIRED', 'EXPIRED'],
            'lower case' => ['ERROR', 'error'],
            'empty' => ['ERROR', ''],
        ];
    }

    #[DataProvider('bareStatusTexts')]
    public function test_a_status_text_equal_to_the_status_code_shows_the_fallback_message(string $code, string $text): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => $code, 'status' => $text]),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->reel());

        $this->assertFalse($result->success);
        $this->assertSame('Meta rejected the post: Instagram could not process the media', $result->error);
    }

    /* ---------------- 2207051 on media_publish (Postiz ae0a642b) ---------------- */

    private const BLOCKED = ['error' => ['message' => 'Action is blocked', 'type' => 'OAuthException', 'code' => 4, 'error_subcode' => 2207051]];

    private function blockedPublish(string $containerStatus, array $newestMedia = ['data' => [['id' => 'media-77']]]): array
    {
        Http::fake([
            '*/media_publish' => Http::response(self::BLOCKED, 400),
            '*/ig-1/media?*' => Http::response($newestMedia),
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::sequence()
                ->push(['status_code' => 'FINISHED'])
                ->push(['status_code' => $containerStatus]),
        ]);
        $account = $this->account();

        return [(new InstagramProvider)->publish($account, $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']])), $account];
    }

    public function test_2207051_with_a_published_container_is_recorded_as_published(): void
    {
        [$result, $account] = $this->blockedPublish('PUBLISHED');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('media-77', $result->remotePostId);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
        $this->assertSame([
            ['POST', 'ig-1/media', ['caption' => 'A caption', 'image_url' => 'https://cdn.example/a.jpg']],
            ['GET', 'container-1', []],
            ['POST', 'ig-1/media_publish', ['creation_id' => 'container-1']],
            ['GET', 'container-1', []],
            ['GET', 'ig-1/media', []],
        ], $this->sent());
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'container-1') && str_contains($r->url(), 'fields=status_code&'));
    }

    /** @return array<string, array{string}> */
    public static function unpublishedContainerStatuses(): array
    {
        return ['FINISHED' => ['FINISHED'], 'ERROR' => ['ERROR'], 'EXPIRED' => ['EXPIRED'], 'IN_PROGRESS' => ['IN_PROGRESS']];
    }

    #[DataProvider('unpublishedContainerStatuses')]
    public function test_2207051_with_any_other_container_status_stays_failed(string $status): void
    {
        [$result] = $this->blockedPublish($status);

        $this->assertFalse($result->success);
        $this->assertSame('Instagram blocked your request', $result->error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ig-1/media?'));
    }

    public function test_2207051_with_a_published_container_but_no_media_id_stays_failed(): void
    {
        [$result] = $this->blockedPublish('PUBLISHED', ['data' => []]);

        $this->assertFalse($result->success);
        $this->assertSame('Instagram blocked your request', $result->error);
    }

    public function test_other_media_publish_errors_never_probe_the_container(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['error' => ['message' => 'spam', 'type' => 'OAuthException', 'code' => 4, 'error_subcode' => 2207001]], 400),
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::sequence()->push(['status_code' => 'FINISHED'])->push(['status_code' => 'PUBLISHED']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));

        $this->assertFalse($result->success);
        $this->assertSame('Instagram detected that your post is spam, please try again with different content', $result->error);
        $this->assertCount(1, collect($this->sent())->where(1, 'container-1'));
    }

    /* ---------------- single image ---------------- */

    public function test_a_single_image_waits_for_its_container_before_publishing(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['id' => 'media-1']),
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame([
            ['POST', 'ig-1/media', ['caption' => 'A caption', 'image_url' => 'https://cdn.example/a.jpg']],
            ['GET', 'container-1', []],
            ['GET', 'container-1', []],
            ['POST', 'ig-1/media_publish', ['creation_id' => 'container-1']],
        ], $this->sent());
    }

    public function test_media_not_ready_on_publish_is_handed_to_the_queue(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['error' => ['message' => 'Media ID is not available', 'type' => 'OAuthException', 'code' => 9007, 'error_subcode' => 2207027]], 400),
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'FINISHED']),
        ]);

        $this->expectException(TransientPublishException::class);

        (new InstagramProvider)->publish($this->account(), $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));
    }

    /* ---------------- carousel ---------------- */

    public function test_single_media_still_publishes_one_container_with_the_caption(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['id' => 'media-1']),
            '*/media' => Http::response(['id' => 'container-1']),
            '*/container-1?*' => Http::response(['status_code' => 'FINISHED']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame([
            ['POST', 'ig-1/media', ['caption' => 'A caption', 'image_url' => 'https://cdn.example/a.jpg']],
            ['GET', 'container-1', []],
            ['POST', 'ig-1/media_publish', ['creation_id' => 'container-1']],
        ], $this->sent());
    }

    public function test_two_to_twenty_media_publish_as_a_carousel(): void
    {
        Http::fake([
            '*/media_publish' => Http::response(['id' => 'media-1']),
            '*/media' => Http::sequence()->push(['id' => 'c1'])->push(['id' => 'c2'])->push(['id' => 'c3'])->push(['id' => 'car-1']),
            '*' => Http::response(['status_code' => 'FINISHED']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost([
            ['type' => 'image', 'url' => 'https://cdn.example/1.jpg'],
            ['type' => 'video', 'url' => 'https://cdn.example/2.mp4'],
            ['type' => 'image', 'url' => 'https://cdn.example/3.jpg'],
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('media-1', $result->remotePostId);
        $this->assertSame([
            // children: no caption, flagged as carousel items, VIDEO for the clip
            ['POST', 'ig-1/media', ['image_url' => 'https://cdn.example/1.jpg', 'is_carousel_item' => 'true']],
            ['POST', 'ig-1/media', ['video_url' => 'https://cdn.example/2.mp4', 'media_type' => 'VIDEO', 'is_carousel_item' => 'true']],
            ['POST', 'ig-1/media', ['image_url' => 'https://cdn.example/3.jpg', 'is_carousel_item' => 'true']],
            // every child is checked before the carousel is built
            ['GET', 'c1', []],
            ['GET', 'c2', []],
            ['GET', 'c3', []],
            ['POST', 'ig-1/media', ['caption' => 'A caption', 'media_type' => 'CAROUSEL', 'children' => 'c1,c2,c3']],
            ['GET', 'car-1', []],
            ['POST', 'ig-1/media_publish', ['creation_id' => 'car-1']],
        ], $this->sent());
    }

    public function test_a_failed_carousel_child_stops_before_the_carousel_container(): void
    {
        Http::fake([
            '*/media' => Http::sequence()->push(['id' => 'c1'])->push(['id' => 'c2']),
            '*/c2?*' => Http::response(['status_code' => 'ERROR', 'status' => 'Error: Image too large. Error code: 2207004']),
            '*' => Http::response(['status_code' => 'FINISHED']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost([
            ['type' => 'image', 'url' => 'https://cdn.example/1.jpg'],
            ['type' => 'image', 'url' => 'https://cdn.example/2.jpg'],
        ]));

        $this->assertSame('Image is too large', $result->error);
        Http::assertNotSent(fn (Request $r) => ($r['media_type'] ?? null) === 'CAROUSEL');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));
    }

    public function test_the_poll_budget_is_shared_across_carousel_children(): void
    {
        Http::fake([
            '*/media' => Http::sequence()->push(['id' => 'c1'])->push(['id' => 'c2']),
            '*' => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost([
            ['type' => 'video', 'url' => 'https://cdn.example/1.mp4'],
            ['type' => 'video', 'url' => 'https://cdn.example/2.mp4'],
        ]));

        $this->assertStringContainsString('did not complete in time', (string) $result->error);
        $this->assertSame(InstagramProvider::CONTAINER_POLL_ATTEMPTS, Http::recorded(fn (Request $r) => $r->method() === 'GET')->count());
    }

    public function test_more_than_twenty_media_fail_before_any_graph_call(): void
    {
        Http::fake();
        $media = array_map(fn (int $i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.jpg"], range(1, 21));

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost($media));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('at most 20', (string) $result->error);
        Http::assertNothingSent();
    }

    /**
     * Meta's content publishing guide: "Carousels are limited to 20 images,
     * videos, or a mix of the two". 21 containers are checked, more than the
     * 16-answer budget, which only "still processing" answers spend.
     */
    public function test_a_twenty_item_carousel_publishes_within_the_poll_budget(): void
    {
        $ids = array_map(fn (int $i) => "c{$i}", range(1, 20));
        $sequence = Http::sequence();
        foreach ([...$ids, 'carousel-1'] as $id) {
            $sequence->push(['id' => $id]);
        }
        Http::fake([
            '*/media_publish' => Http::response(['id' => 'media-20']),
            '*/media' => $sequence,
            // c1 takes two polls; every other container is ready at once.
            '*/c1?*' => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
            '*' => Http::response(['status_code' => 'FINISHED']),
        ]);
        $media = array_map(fn (int $i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.jpg"], range(1, 20));

        $result = (new InstagramProvider)->publish($this->account(), $this->socialPost($media));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('media-20', $result->remotePostId);
        $this->assertSame(22, Http::recorded(fn (Request $r) => $r->method() === 'GET')->count());
        Http::assertSent(fn (Request $r) => str_ends_with(strtok($r->url(), '?'), '/ig-1/media')
            && ($r->data()['media_type'] ?? null) === 'CAROUSEL'
            && $r->data()['children'] === implode(',', $ids));
        Sleep::assertSleptTimes(1);
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

    public function test_a_comment_instagram_did_not_add_says_the_post_itself_was_published(): void
    {
        Http::fake(['*/comments' => Http::response(['error' => [
            'message' => 'Instagram Comment Not Added', 'type' => 'OAuthException', 'code' => 100, 'error_subcode' => 1772107,
        ]], 400)]);
        $account = $this->account();

        $result = (new InstagramProvider)->comment($account, 'media-1', 'nice');

        $this->assertFalse($result->success);
        $this->assertSame('Instagram did not add the comment. The post itself was published. Try again with different comment text.', $result->error);
        $this->assertStringNotContainsStringIgnoringCase('retry', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
        $this->assertSame(\App\Services\Social\Data\MetaGraphOutcome::FAIL, InstagramProvider::ERRORS[1772107][0]);
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
