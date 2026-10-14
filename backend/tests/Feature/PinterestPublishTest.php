<?php

namespace Tests\Feature;

use App\Jobs\PublishToPinterestJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\PostPublishDispatcher;
use App\Services\Social\Providers\PinterestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pinterest publishing: every Pin needs a board and an image or video. Image
 * Pins are one call; video Pins register an upload, upload the file, wait for
 * processing, then create the Pin with a cover image.
 */
class PinterestPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'social.platforms.pinterest.client_id' => 'pin-client',
            'social.platforms.pinterest.client_secret' => 'pin-secret',
            'social.platforms.pinterest.sandbox' => false,
        ]);
    }

    private function account(array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => 'pinterest',
            'platform_account_id' => 'pin-user-1',
            'username' => 'creatorlab',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ], $overrides));
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function boardsResponse(): array
    {
        return ['items' => [
            ['id' => 'board-1', 'name' => 'Recipes', 'privacy' => 'PUBLIC'],
            ['id' => 'board-2', 'name' => 'Travel', 'privacy' => 'PUBLIC'],
        ], 'bookmark' => null];
    }

    public function test_image_pin_sends_exactly_the_title_link_and_alt_text_the_user_typed(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-42'], 201),
        ]);

        $post = new SocialPost([
            'content' => "Dinner in ten minutes.\nFull recipe: https://example.com/other",
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/pasta.jpg']],
            'link' => 'https://example.com/pasta',
        ]);
        $post->board_id = 'board-2';
        $post->title = 'Easy pasta in 10 minutes';
        $post->alt_text = 'A bowl of pasta';

        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('pin-42', $result->remotePostId);
        $this->assertSame('https://www.pinterest.com/pin/pin-42/', $result->remotePostUrl);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.pinterest.com/v5/pins'
            && $req['board_id'] === 'board-2'
            && $req['title'] === 'Easy pasta in 10 minutes'
            && $req['description'] === "Dinner in ten minutes.\nFull recipe: https://example.com/other"
            && $req['link'] === 'https://example.com/pasta'
            && $req['alt_text'] === 'A bowl of pasta'
            && $req['media_source'] === ['source_type' => 'image_url', 'url' => 'https://cdn.example/pasta.jpg']);
    }

    public function test_nothing_is_guessed_when_title_and_link_are_left_empty(): void
    {
        // The caption has a first line and a URL, but the user typed no title
        // or link: the Pin goes out without them rather than with our guess.
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-1'], 201),
        ]);

        $post = new SocialPost([
            'content' => "First line\nSee https://example.com/page",
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
        ]);
        $post->board_id = 'board-1';

        $this->assertTrue((new PinterestProvider)->publish($this->account(), $post)->success);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && ! isset($req['title']) && ! isset($req['link']) && ! isset($req['alt_text']));
    }

    public function test_a_board_that_is_not_on_the_account_fails_without_posting(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-1'], 201),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]]);
        $post->board_id = 'someone-elses-board';

        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('board', (string) $result->error);
        Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins'));
    }

    /** A real 1x1 PNG, so the image type is detected from the bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_a_refused_image_link_is_retried_once_with_the_image_itself(): void
    {
        // Pinterest refuses some links without fetching them (seen with an
        // ngrok address: code 235 "image broken").
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'cdn.example/a.png' => Http::response(base64_decode(self::PNG)),
            'api.pinterest.com/v5/pins' => Http::sequence()
                ->push(['code' => 235, 'message' => 'Sorry, this image is broken. Please pick a different image.'], 403)
                ->push(['id' => 'pin-b64'], 201),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.png']]]);
        $post->board_id = 'board-1';
        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('pin-b64', $result->remotePostId);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && $req['media_source'] === ['source_type' => 'image_base64', 'content_type' => 'image/png', 'data' => self::PNG]);
        $this->assertCount(2, Http::recorded(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')));
    }

    public function test_an_invalid_image_link_is_also_retried_with_the_image_itself(): void
    {
        // Pinterest rejects e.g. a localhost link: code 1 on the "image url" parameter.
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'localhost:8000/*' => Http::response(base64_decode(self::PNG)),
            'api.pinterest.com/v5/pins' => Http::sequence()
                ->push(['code' => 1, 'message' => "Parameter 'image url' is not a valid URL format.", 'details' => ['param_name' => 'image url']], 400)
                ->push(['id' => 'pin-b64'], 201),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://localhost:8000/storage/a.png']]]);
        $post->board_id = 'board-1';

        $this->assertTrue((new PinterestProvider)->publish($this->account(), $post)->success);
    }

    public function test_other_pin_errors_are_not_retried(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['code' => 29, 'message' => 'Apps with Trial access may not create Pins in production'], 403),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.png']]]);
        $post->board_id = 'board-1';

        $this->assertFalse((new PinterestProvider)->publish($this->account(), $post)->success);
        $this->assertCount(1, Http::recorded(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')));
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'cdn.example'));
    }

    public function test_an_image_pinterest_cannot_take_as_a_file_is_not_retried(): void
    {
        // Pinterest only accepts JPEG and PNG as image_base64 (API spec).
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'cdn.example/a.gif' => Http::response('GIF89a'.str_repeat("\0", 20)),
            'api.pinterest.com/v5/pins' => Http::response(['code' => 235, 'message' => 'Sorry, this image is broken.'], 403),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.gif']]]);
        $post->board_id = 'board-1';

        $this->assertFalse((new PinterestProvider)->publish($this->account(), $post)->success);
        $this->assertCount(1, Http::recorded(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')));
    }

    public function test_a_refused_video_cover_link_is_retried_with_the_cover_itself(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/media/media-9' => Http::response(['media_id' => 'media-9', 'status' => 'succeeded']),
            'api.pinterest.com/v5/media' => Http::response(['media_id' => 'media-9', 'upload_url' => 'https://pinterest-media-upload.s3.amazonaws.com/', 'upload_parameters' => ['key' => 'k']], 201),
            'cdn.example/clip.mp4' => Http::response('VIDEO-BYTES'),
            'cdn.example/cover.png' => Http::response(base64_decode(self::PNG)),
            'pinterest-media-upload.s3.amazonaws.com/*' => Http::response('', 204),
            'api.pinterest.com/v5/pins' => Http::sequence()
                ->push(['code' => 235, 'message' => 'Sorry, this image is broken.'], 403)
                ->push(['id' => 'pin-video'], 201),
        ]);

        $post = new SocialPost(['content' => 'v', 'media' => [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']]]);
        $post->cover_url = 'https://cdn.example/cover.png';
        $post->board_id = 'board-1';

        $this->assertTrue((new PinterestProvider)->publish($this->account(), $post)->success);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && ($req['media_source']['cover_image_data'] ?? null) === self::PNG
            && ($req['media_source']['cover_image_content_type'] ?? null) === 'image/png'
            && ! isset($req['media_source']['cover_image_url'])
            && $req['media_source']['media_id'] === 'media-9');
        // The video itself is uploaded once, not again for the retry.
        $this->assertCount(1, Http::recorded(fn (Request $req) => str_contains($req->url(), 's3.amazonaws.com')));
    }

    private function images(int $n): array
    {
        return array_map(fn ($i) => ['type' => 'image', 'url' => "https://cdn.example/{$i}.png"], range(1, $n));
    }

    public function test_several_images_become_one_carousel_pin(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-carousel'], 201),
        ]);

        $post = new SocialPost(['content' => 'Three looks', 'media' => $this->images(3)]);
        $post->board_id = 'board-1';

        $this->assertTrue((new PinterestProvider)->publish($this->account(), $post)->success);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && $req['media_source'] === ['source_type' => 'multiple_image_urls', 'items' => [
                ['url' => 'https://cdn.example/1.png'], ['url' => 'https://cdn.example/2.png'], ['url' => 'https://cdn.example/3.png'],
            ]]);
        $this->assertCount(1, Http::recorded(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')));
    }

    public function test_a_carousel_takes_the_first_five_images(): void
    {
        // Pinterest's limit is 2-5 images per carousel (API spec), like X's 4.
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-carousel'], 201),
        ]);

        $post = new SocialPost(['content' => 'Seven', 'media' => $this->images(7)]);
        $post->board_id = 'board-1';

        $this->assertTrue((new PinterestProvider)->publish($this->account(), $post)->success);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && count($req['media_source']['items']) === 5
            && $req['media_source']['items'][4]['url'] === 'https://cdn.example/5.png');
    }

    public function test_carousel_images_of_different_shapes_get_a_clear_error(): void
    {
        // Seen on the real API; not in Pinterest's API description.
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['code' => 1, 'message' => 'Images must have the same width/height ratios.'], 400),
        ]);

        $post = new SocialPost(['content' => 'Mixed', 'media' => $this->images(2)]);
        $post->board_id = 'board-1';
        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertFalse($result->success);
        $this->assertSame('Pinterest carousel images must all have the same shape (width-to-height ratio). Crop them to match, or post one image.', $result->error);
    }

    public function test_a_refused_carousel_is_retried_with_the_images_themselves(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'cdn.example/*' => Http::response(base64_decode(self::PNG)),
            'api.pinterest.com/v5/pins' => Http::sequence()
                ->push(['code' => 235, 'message' => 'Sorry, this image is broken.'], 403)
                ->push(['id' => 'pin-carousel'], 201),
        ]);

        $post = new SocialPost(['content' => 'Two', 'media' => $this->images(2)]);
        $post->board_id = 'board-1';

        $this->assertTrue((new PinterestProvider)->publish($this->account(), $post)->success);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && $req['media_source'] === ['source_type' => 'multiple_image_base64', 'items' => [
                ['content_type' => 'image/png', 'data' => self::PNG], ['content_type' => 'image/png', 'data' => self::PNG],
            ]]);
    }

    public function test_pin_without_media_fails_without_calling_pinterest(): void
    {
        Http::fake();

        $result = (new PinterestProvider)->publish($this->account(), new SocialPost(['content' => 'text only']));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('image or a video', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_a_board_just_created_and_not_yet_listed_is_still_accepted(): void
    {
        // Pinterest's board list takes minutes to include a new board (seen on
        // the real API), so a board missing from the list is read directly and
        // accepted when this account owns it.
        Http::fake([
            'api.pinterest.com/v5/boards/board-new' => Http::response(['id' => 'board-new', 'name' => 'Brand new', 'owner' => ['username' => 'creatorlab']]),
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-new'], 201),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]]);
        $post->board_id = 'board-new';

        $this->assertTrue((new PinterestProvider)->publish($this->account(), $post)->success);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins') && $req['board_id'] === 'board-new');
    }

    public function test_a_board_owned_by_someone_else_is_refused_even_when_readable(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards/board-other' => Http::response(['id' => 'board-other', 'owner' => ['username' => 'someoneelse']]),
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'x'], 201),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]]);
        $post->board_id = 'board-other';

        $this->assertFalse((new PinterestProvider)->publish($this->account(), $post)->success);
        Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins'));
    }

    public function test_pin_without_a_chosen_board_fails_without_calling_pinterest(): void
    {
        Http::fake();

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]]);
        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Choose a Pinterest board', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_video_pin_uploads_waits_for_processing_then_pins_with_a_cover(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/media/media-9' => Http::response(['media_id' => 'media-9', 'status' => 'succeeded']),
            'api.pinterest.com/v5/media' => Http::response([
                'media_id' => 'media-9',
                'upload_url' => 'https://pinterest-media-upload.s3.amazonaws.com/',
                'upload_parameters' => ['key' => 'uploads/abc', 'policy' => 'p'],
            ], 201),
            'cdn.example/clip.mp4' => Http::response('VIDEO-BYTES'),
            'pinterest-media-upload.s3.amazonaws.com/*' => Http::response('', 204),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-video'], 201),
        ]);

        $post = new SocialPost([
            'content' => 'Watch this',
            'media' => [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']],
        ]);
        $post->cover_url = 'https://cdn.example/cover.jpg';
        $post->board_id = 'board-1';

        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'https://pinterest-media-upload.s3.amazonaws.com')
            && $req->isMultipart());
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && $req['media_source'] === [
                'source_type' => 'video_id',
                'media_id' => 'media-9',
                'cover_image_url' => 'https://cdn.example/cover.jpg',
            ]);
    }

    public function test_video_pin_without_any_cover_image_fails_before_uploading(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
        ]);

        // An image elsewhere in the post is not used as the cover: the cover
        // must be chosen on purpose.
        $post = new SocialPost(['content' => 'x', 'media' => [
            ['type' => 'video', 'url' => 'https://cdn.example/clip.mp4'],
            ['type' => 'image', 'url' => 'https://cdn.example/other.jpg'],
        ]]);
        $post->board_id = 'board-1';
        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('cover image', (string) $result->error);
        Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/v5/media'));
    }

    public function test_sandbox_flag_sends_calls_to_the_sandbox_host(): void
    {
        config(['social.platforms.pinterest.sandbox' => true]);
        Http::fake([
            'api-sandbox.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api-sandbox.pinterest.com/v5/pins' => Http::response(['id' => 'pin-sb'], 201),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]]);
        $post->board_id = 'board-1';
        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'api.pinterest.com'));
    }

    public function test_api_base_setting_points_every_call_at_another_server(): void
    {
        // Used to run the real code against a local mock of Pinterest's
        // official API spec (see tests/Contract/README.md).
        config(['social.platforms.pinterest.api_base' => 'http://127.0.0.1:4010']);
        Http::fake([
            '127.0.0.1:4010/boards*' => Http::response($this->boardsResponse()),
            '127.0.0.1:4010/pins' => Http::response(['id' => 'pin-mock'], 201),
        ]);

        $post = new SocialPost(['content' => 'hi', 'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]]);
        $post->board_id = 'board-1';
        $result = (new PinterestProvider)->publish($this->account(), $post);

        $this->assertTrue($result->success);
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'pinterest.com'));
    }

    public function test_listing_boards_does_not_save_them(): void
    {
        Http::fake(['api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse())]);
        $account = $this->account();

        (new PinterestProvider)->boards($account);

        $this->assertNull($account->fresh()->meta('boards'));
    }

    public function test_boards_repeated_across_pages_are_listed_once(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1', 'name' => 'Recipes']], 'bookmark' => 'next']),
        ]);

        $boards = (new PinterestProvider)->boards($this->account());

        $this->assertSame(['board-1'], array_column($boards, 'id'));
    }

    public function test_expired_token_refreshes_with_basic_auth(): void
    {
        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::response([
                'access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 2592000,
            ]),
        ]);

        $account = (new PinterestProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->subMinute()]));

        $this->assertSame('new-token', $account->access_token);
        $this->assertSame('new-refresh', $account->refresh_token);
        Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Basic '.base64_encode('pin-client:pin-secret'))
            && $req['grant_type'] === 'refresh_token'
            && $req['refresh_token'] === 'refresh');
    }

    /**
     * Pinterest's refresh token only stays alive if it's used within 60 days,
     * and the docs say to refresh the 30-day access token before it expires.
     * The daily social:refresh-tokens run must therefore refresh early.
     */
    public function test_token_close_to_expiry_is_refreshed_early(): void
    {
        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::response([
                'access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 2592000,
            ]),
        ]);

        $account = (new PinterestProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->addDays(3)]));

        $this->assertSame('new-token', $account->access_token);
        $this->assertSame('new-refresh', $account->refresh_token);
    }

    public function test_token_with_plenty_of_time_left_is_not_refreshed(): void
    {
        Http::fake();

        $account = (new PinterestProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->addDays(20)]));

        $this->assertSame('token', $account->access_token);
        Http::assertNothingSent();
    }

    public function test_failed_early_refresh_keeps_a_still_valid_account_connected(): void
    {
        Http::fake(['api.pinterest.com/v5/oauth/token' => Http::response(['message' => 'invalid'], 401)]);

        $account = (new PinterestProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->addDays(3)]));

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_caption_limit_is_pinterests_800_character_description(): void
    {
        $this->assertSame(800, \App\Services\Social\CaptionRules::limit('pinterest'));
        $this->assertSame(800, \App\Mcp\Tools\CreatePost::PLATFORM_CHAR_LIMITS['pinterest']);
    }

    public function test_rejected_refresh_marks_the_account_for_reconnect(): void
    {
        Http::fake(['api.pinterest.com/v5/oauth/token' => Http::response(['message' => 'invalid'], 401)]);

        $account = (new PinterestProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->subMinute()]));

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_connect_stores_the_account_but_not_its_boards(): void
    {
        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::response([
                'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 2592000,
                'scope' => 'boards:read,pins:write,user_accounts:read',
            ]),
            'api.pinterest.com/v5/user_account' => Http::response([
                'id' => '987', 'username' => 'creatorlab', 'business_name' => 'Creator Lab',
                'profile_image' => 'https://i.pinimg.com/a.jpg', 'account_type' => 'BUSINESS',
            ]),
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
        ]);

        $accounts = (new PinterestProvider)->connectFromCode($this->user, 'code-1', 'https://app.example/social/callback');

        $account = $accounts->first();
        $this->assertSame('987', $account->platform_account_id);
        $this->assertSame('creatorlab', $account->username);
        $this->assertSame('Creator Lab', $account->name);
        $this->assertSame('https://www.pinterest.com/creatorlab/', $account->profile_url);
        // Pinterest's developer guidelines forbid storing data read from the
        // API, so boards are always fetched live and never saved.
        $this->assertNull($account->fresh()->meta('boards'));
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/oauth/token')
            && $req['continuous_refresh'] === 'true'
            && $req['code'] === 'code-1');
    }

    public function test_job_publishes_the_target_with_the_board_option(): void
    {
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-77'], 201),
        ]);

        $account = $this->account();
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'From the composer',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'pinterest',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHING,
            'options' => ['board_id' => 'board-2', 'title' => 'Custom title'],
        ]);

        (new PublishToPinterestJob($target->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status);
        $this->assertSame('pin-77', $target->platform_post_id);
        $this->assertSame('https://www.pinterest.com/pin/pin-77/', $target->meta['url']);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && $req['board_id'] === 'board-2'
            && $req['title'] === 'Custom title');
    }

    public function test_job_uses_the_board_chosen_for_its_own_account(): void
    {
        // Two Pinterest accounts in one post: each target pins to the board
        // picked for that account (boards belong to one account).
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse()),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-78'], 201),
        ]);

        $first = $this->account();
        $second = $this->account(['platform_account_id' => 'pin-user-2']);
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'Two accounts',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
            'status' => Post::STATUS_POSTED,
        ]);
        $options = ['board_ids' => [(string) $first->id => 'board-1', (string) $second->id => 'board-2'], 'alt_text' => 'Alt'];
        $target = $post->targets()->create([
            'platform' => 'pinterest',
            'social_account_id' => $second->id,
            'status' => PostTarget::STATUS_PUBLISHING,
            'options' => $options,
        ]);

        (new PublishToPinterestJob($target->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v5/pins')
            && $req['board_id'] === 'board-2' && $req['alt_text'] === 'Alt');
    }

    public function test_publishing_without_a_board_for_each_pinterest_account_is_refused(): void
    {
        Queue::fake();
        $first = $this->account();
        $second = $this->account(['platform_account_id' => 'pin-user-2']);

        $payload = [
            'caption' => 'Pin',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
            'targets' => [
                ['platform' => 'pinterest', 'social_account_id' => $first->id],
                ['platform' => 'pinterest', 'social_account_id' => $second->id],
            ],
            'status' => Post::STATUS_POSTED,
            'options' => ['pinterest' => ['board_ids' => [(string) $first->id => 'board-1']]],
        ];

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $payload)
            ->assertStatus(422)->assertJsonPath('message', 'Choose a Pinterest board for every Pinterest account before publishing or scheduling.');

        $payload['options']['pinterest']['board_ids'][(string) $second->id] = 'board-2';
        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $payload)->assertCreated();
    }

    public function test_editing_only_the_options_saves_them_on_the_existing_targets(): void
    {
        // The AI's update_post may send just { options } to add the board to
        // a saved draft. The board must be stored, not only checked.
        Queue::fake();
        $account = $this->account();
        $id = $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Draft',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
            'targets' => [['platform' => 'pinterest', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_DRAFT,
        ])->assertCreated()->json('id');

        $this->withHeaders($this->auth($this->user))->putJson("/api/posts/{$id}", [
            'status' => Post::STATUS_POSTED,
            'options' => ['pinterest' => ['board_ids' => [(string) $account->id => 'board-2'], 'title' => 'T']],
        ])->assertOk();

        $target = PostTarget::where('post_id', $id)->firstOrFail();
        $this->assertSame('board-2', $target->options['board_ids'][(string) $account->id]);
        $this->assertSame('T', $target->options['title']);
        Queue::assertPushed(PublishToPinterestJob::class);
    }

    public function test_a_draft_may_be_saved_before_a_board_is_chosen(): void
    {
        $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Draft',
            'platforms' => ['pinterest'],
            'status' => Post::STATUS_DRAFT,
        ])->assertCreated();
    }

    public function test_pinterest_title_link_and_alt_text_limits_are_enforced(): void
    {
        $this->account();
        $base = [
            'caption' => 'Pin',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
            'platforms' => ['pinterest'],
            'status' => Post::STATUS_DRAFT,
        ];

        foreach ([
            ['title' => str_repeat('a', 101)],
            ['link' => 'not a link'],
            ['alt_text' => str_repeat('a', 501)],
        ] as $bad) {
            $this->withHeaders($this->auth($this->user))
                ->postJson('/api/posts', $base + ['options' => ['pinterest' => $bad + ['board_id' => 'board-1']]])
                ->assertStatus(422);
        }
    }

    public function test_dispatcher_routes_pinterest_targets_to_the_pinterest_job(): void
    {
        Queue::fake();

        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $post->targets()->create(['platform' => 'pinterest', 'status' => PostTarget::STATUS_PENDING]);

        PostPublishDispatcher::dispatch($post);

        Queue::assertPushed(PublishToPinterestJob::class);
    }

    public function test_publishing_to_pinterest_without_media_is_refused(): void
    {
        $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'no media',
            'platforms' => ['pinterest'],
            'status' => Post::STATUS_POSTED,
        ])->assertStatus(422)->assertJsonPath('message', 'Pinterest needs an image or a video before publishing or scheduling.');
    }

    public function test_ai_media_rule_requires_an_image_or_video_for_pinterest(): void
    {
        $rule = fn (array $media) => \App\Mcp\Tools\CreatePost::videoRuleError(Post::STATUS_POSTED, ['pinterest'], $media);

        $this->assertStringContainsString('Pinterest requires an image or a video', (string) $rule([]));
        $this->assertNull($rule([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']]));
        $this->assertNull(\App\Mcp\Tools\CreatePost::videoRuleError(Post::STATUS_DRAFT, ['pinterest'], []));
    }

    public function test_boards_endpoint_lists_a_pinterest_accounts_boards(): void
    {
        $account = $this->account();
        Http::fake(['api.pinterest.com/v5/boards*' => Http::response($this->boardsResponse())]);

        $this->withHeaders($this->auth($this->user))->getJson("/api/social/accounts/{$account->id}/boards")
            ->assertOk()
            ->assertJsonPath('data.0.id', 'board-1')
            ->assertJsonPath('data.1.name', 'Travel');
    }

    public function test_connect_asks_for_permission_to_create_boards(): void
    {
        $this->assertContains('boards:write', config('social.platforms.pinterest.scopes'));
    }

    public function test_create_board_sends_a_public_board_and_returns_it(): void
    {
        Http::fake(['api.pinterest.com/v5/boards' => Http::response(['id' => '777', 'name' => 'Weeknight dinners', 'privacy' => 'PUBLIC'], 201)]);

        $board = (new PinterestProvider)->createBoard($this->account(), 'Weeknight dinners', 'Quick meals');

        $this->assertSame(['id' => '777', 'name' => 'Weeknight dinners', 'privacy' => 'PUBLIC'], $board);
        Http::assertSent(fn (Request $req) => $req->method() === 'POST'
            && $req->url() === 'https://api.pinterest.com/v5/boards'
            && $req['name'] === 'Weeknight dinners'
            && $req['description'] === 'Quick meals'
            && $req['privacy'] === 'PUBLIC');
    }

    public function test_board_endpoint_creates_a_board_on_the_users_pinterest_account(): void
    {
        $account = $this->account();
        Http::fake(['api.pinterest.com/v5/boards' => Http::response(['id' => '777', 'name' => 'Weeknight dinners', 'privacy' => 'PUBLIC'], 201)]);

        $this->withHeaders($this->auth($this->user))->postJson("/api/social/accounts/{$account->id}/boards", ['name' => 'Weeknight dinners'])
            ->assertCreated()
            ->assertJsonPath('data.id', '777')
            ->assertJsonPath('data.name', 'Weeknight dinners');
    }

    public function test_board_endpoint_passes_pinterests_refusal_back_to_the_user(): void
    {
        $account = $this->account();
        Http::fake(['api.pinterest.com/v5/boards' => Http::response(['code' => 58, 'message' => 'You already have a board with this name.'], 409)]);

        $this->withHeaders($this->auth($this->user))->postJson("/api/social/accounts/{$account->id}/boards", ['name' => 'Recipes'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pinterest refused the board: You already have a board with this name.');
    }

    public function test_board_endpoint_needs_a_name(): void
    {
        $account = $this->account();
        Http::fake();

        $this->withHeaders($this->auth($this->user))->postJson("/api/social/accounts/{$account->id}/boards", ['name' => ''])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_board_endpoint_refuses_someone_elses_account(): void
    {
        $account = $this->account();
        Http::fake();

        $this->withHeaders($this->auth(User::factory()->create()))->postJson("/api/social/accounts/{$account->id}/boards", ['name' => 'X'])
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_boards_endpoint_refuses_someone_elses_account(): void
    {
        $other = User::factory()->create();
        $account = $this->account();

        $this->withHeaders($this->auth($other))->getJson("/api/social/accounts/{$account->id}/boards")->assertNotFound();
    }
}
