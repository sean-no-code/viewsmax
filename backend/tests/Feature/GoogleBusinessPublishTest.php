<?php

namespace Tests\Feature;

use App\Jobs\PublishToGoogleBusinessJob;
use App\Mcp\Tools\CreatePost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\CaptionRules;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\PostPublishDispatcher;
use App\Services\Social\Providers\GoogleBusinessProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Google Business Profile posting: each location is its own account, and a
 * post is an update, an event or an offer, with an optional button.
 */
class GoogleBusinessPublishTest extends TestCase
{
    use RefreshDatabase;

    private const POSTS_URL = 'https://mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'social.platforms.google_business.enabled' => true,
            'social.platforms.google_business.client_id' => 'google-client',
            'social.platforms.google_business.client_secret' => 'google-secret',
        ]);
    }

    private function account(array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => 'google_business',
            'platform_account_id' => 'locations/222',
            'name' => 'Corner Bakery',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => [
                'account_name' => 'accounts/111',
                'location_name' => 'locations/222',
                'post_parent' => 'accounts/111/locations/222',
                'language_code' => 'de',
            ],
        ], $overrides));
    }

    private function socialPost(array $options = [], array $media = []): SocialPost
    {
        $post = new SocialPost(['content' => 'Fresh bread every morning.', 'media' => $media]);
        $post->options = $options;

        return $post;
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function fakeCreated(): void
    {
        Http::fake([self::POSTS_URL => Http::response([
            'name' => 'accounts/111/locations/222/localPosts/333',
            'searchUrl' => 'https://local.google.com/place?id=1&use=posts&lpsid=333',
            'state' => 'LIVE',
        ])]);
    }

    public function test_connect_stores_one_account_per_location_that_can_take_posts(): void
    {
        Http::fake([
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::sequence()
                ->push(['accounts' => [['name' => 'accounts/111', 'accountName' => 'Bakery Group']], 'nextPageToken' => 'p2'])
                ->push(['accounts' => [['name' => 'accounts/999', 'accountName' => 'Other']]]),
            'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/111/locations*' => Http::response(['locations' => [
                [
                    'name' => 'locations/222', 'title' => 'Corner Bakery', 'languageCode' => 'de',
                    'storefrontAddress' => ['addressLines' => ['12 Main St'], 'locality' => 'Springfield'],
                    'metadata' => ['canOperateLocalPost' => true, 'mapsUri' => 'https://maps.google.com/?cid=1'],
                ],
                ['name' => 'locations/223', 'title' => 'Closed Branch', 'metadata' => ['canOperateLocalPost' => false]],
            ]]),
            'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/999/locations*' => Http::response([
                'locations' => [['name' => 'locations/222', 'title' => 'Corner Bakery']],
            ]),
        ]);

        $accounts = (new GoogleBusinessProvider)->storeLocations($this->user, new OAuthResult('tok', 'ref', 3600));

        $this->assertCount(1, $accounts);
        $account = $accounts->first();
        $this->assertSame('locations/222', $account->platform_account_id);
        $this->assertSame('Corner Bakery', $account->name);
        $this->assertSame('12 Main St, Springfield', $account->meta('address'));
        $this->assertSame('https://maps.google.com/?cid=1', $account->profile_url);
        $this->assertSame('accounts/111/locations/222', $account->meta('post_parent'));
        $this->assertSame('de', $account->meta('language_code'));
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/v1/accounts?pageToken=p2'));
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/v1/accounts/111/locations')
            && $req['readMask'] === 'name,title,languageCode,storefrontAddress,websiteUri,metadata'
            && $req->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_connect_shows_googles_reason_when_the_api_refuses(): void
    {
        // Until Google approves the project its quota is 0 and every call fails.
        Http::fake(['https://mybusinessaccountmanagement.googleapis.com/*' => Http::response([
            'error' => ['code' => 429, 'message' => "Quota exceeded for quota metric 'Requests'.", 'status' => 'RESOURCE_EXHAUSTED'],
        ], 429)]);

        $this->expectExceptionMessage("Google couldn't list Business Profile accounts: Quota exceeded for quota metric 'Requests'.");

        (new GoogleBusinessProvider)->storeLocations($this->user, new OAuthResult('tok'));
    }

    public function test_update_with_a_photo_and_a_button(): void
    {
        $this->fakeCreated();

        $result = (new GoogleBusinessProvider)->publish($this->account(), $this->socialPost(
            ['cta_type' => 'BOOK', 'cta_url' => 'https://bakery.example/book'],
            [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4'], ['type' => 'image', 'url' => 'https://cdn.example/bread.jpg']],
        ));

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('accounts/111/locations/222/localPosts/333', $result->remotePostId);
        $this->assertSame('https://local.google.com/place?id=1&use=posts&lpsid=333', $result->remotePostUrl);
        Http::assertSent(fn (Request $req) => $req->url() === self::POSTS_URL
            && $req->data() === [
                'languageCode' => 'de',
                'summary' => 'Fresh bread every morning.',
                'topicType' => 'STANDARD',
                'media' => [['mediaFormat' => 'PHOTO', 'sourceUrl' => 'https://cdn.example/bread.jpg']],
                'callToAction' => ['actionType' => 'BOOK', 'url' => 'https://bakery.example/book'],
            ]);
    }

    public function test_text_only_update_without_a_button_sends_no_media_or_button(): void
    {
        $this->fakeCreated();

        $result = (new GoogleBusinessProvider)->publish($this->account(), $this->socialPost([], [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']]));

        $this->assertTrue($result->success);
        Http::assertSent(fn (Request $req) => ! isset($req['media']) && ! isset($req['callToAction']) && ! isset($req['event']));
    }

    public function test_call_button_has_no_link(): void
    {
        $this->fakeCreated();

        (new GoogleBusinessProvider)->publish($this->account(), $this->socialPost(['cta_type' => 'CALL', 'cta_url' => 'https://ignored.example']));

        Http::assertSent(fn (Request $req) => $req['callToAction'] === ['actionType' => 'CALL']);
    }

    public function test_event_sends_the_wall_clock_times_as_typed(): void
    {
        $this->fakeCreated();

        $result = (new GoogleBusinessProvider)->publish($this->account(), $this->socialPost([
            'topic_type' => 'EVENT',
            'event_title' => 'Bread tasting',
            'event_start' => '2026-11-01T18:00',
            'event_end' => '2026-11-01T21:30',
            'cta_type' => 'SIGN_UP',
            'cta_url' => 'https://bakery.example/rsvp',
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $req) => $req['topicType'] === 'EVENT'
            && $req['event'] === [
                'title' => 'Bread tasting',
                'schedule' => [
                    'startDate' => ['year' => 2026, 'month' => 11, 'day' => 1], 'startTime' => ['hours' => 18, 'minutes' => 0],
                    'endDate' => ['year' => 2026, 'month' => 11, 'day' => 1], 'endTime' => ['hours' => 21, 'minutes' => 30],
                ],
            ]
            && $req['callToAction'] === ['actionType' => 'SIGN_UP', 'url' => 'https://bakery.example/rsvp']);
    }

    public function test_offer_sends_its_details_and_no_button(): void
    {
        $this->fakeCreated();

        $result = (new GoogleBusinessProvider)->publish($this->account(), $this->socialPost([
            'topic_type' => 'OFFER',
            'event_title' => '20% off croissants',
            'event_start' => '2026-11-01T07:00',
            'event_end' => '2026-11-07T12:00',
            'coupon_code' => 'CROISSANT20',
            'redeem_url' => 'https://bakery.example/offer',
            'terms' => '',
            'cta_type' => 'ORDER',
            'cta_url' => 'https://bakery.example/order',
        ]));

        $this->assertTrue($result->success, (string) $result->error);
        Http::assertSent(fn (Request $req) => $req['topicType'] === 'OFFER'
            && $req['event']['title'] === '20% off croissants'
            && $req['offer'] === ['couponCode' => 'CROISSANT20', 'redeemOnlineUrl' => 'https://bakery.example/offer']
            && ! isset($req['callToAction']));
    }

    public function test_event_without_its_dates_fails_without_calling_google(): void
    {
        Http::fake();

        $result = (new GoogleBusinessProvider)->publish($this->account(), $this->socialPost(['topic_type' => 'EVENT', 'event_title' => 'Tasting']));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('needs a title, a start and an end', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_googles_error_message_is_shown_when_a_post_is_rejected(): void
    {
        Http::fake([self::POSTS_URL => Http::response([
            'error' => ['code' => 400, 'message' => 'Request contains an invalid argument.', 'status' => 'INVALID_ARGUMENT'],
        ], 400)]);

        $result = (new GoogleBusinessProvider)->publish($this->account(), $this->socialPost());

        $this->assertFalse($result->success);
        $this->assertSame('Google Business Profile publish failed: Request contains an invalid argument.', $result->error);
    }

    public function test_job_publishes_the_target_with_its_options(): void
    {
        $this->fakeCreated();
        $account = $this->account();
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'From the composer',
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'google_business',
            'social_account_id' => $account->id,
            'status' => PostTarget::STATUS_PUBLISHING,
            'options' => ['cta_type' => 'LEARN_MORE', 'cta_url' => 'https://bakery.example'],
        ]);

        (new PublishToGoogleBusinessJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->status);
        $this->assertSame('accounts/111/locations/222/localPosts/333', $target->platform_post_id);
        $this->assertSame('https://local.google.com/place?id=1&use=posts&lpsid=333', $target->meta['url']);
        Http::assertSent(fn (Request $req) => $req['summary'] === 'From the composer'
            && $req['callToAction'] === ['actionType' => 'LEARN_MORE', 'url' => 'https://bakery.example']);
    }

    public function test_dispatcher_routes_google_business_targets_to_its_job(): void
    {
        Queue::fake();
        $post = Post::create(['user_id' => $this->user->id, 'caption' => 'x', 'status' => Post::STATUS_POSTED]);
        $post->targets()->create(['platform' => 'google_business', 'status' => PostTarget::STATUS_PENDING]);

        PostPublishDispatcher::dispatch($post);

        Queue::assertPushed(PublishToGoogleBusinessJob::class);
    }

    public function test_an_event_cannot_be_scheduled_without_its_title_and_times(): void
    {
        Queue::fake();
        $account = $this->account();
        $payload = [
            'caption' => 'Tasting night',
            'targets' => [['platform' => 'google_business', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'options' => ['google_business' => ['topic_type' => 'EVENT', 'event_title' => 'Tasting']],
        ];

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $payload)
            ->assertStatus(422)->assertJsonPath('message', 'The Google event needs a start before publishing or scheduling.');

        $payload['options']['google_business'] += ['event_start' => '2026-11-01T18:00', 'event_end' => '2026-11-01T21:00'];
        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $payload)->assertCreated();
    }

    public function test_a_draft_event_may_be_saved_before_its_times_are_known(): void
    {
        $account = $this->account();

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Tasting night',
            'targets' => [['platform' => 'google_business', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_DRAFT,
            'options' => ['google_business' => ['topic_type' => 'EVENT']],
        ])->assertCreated();
    }

    public function test_a_button_needs_its_link_except_call(): void
    {
        Queue::fake();
        $account = $this->account();
        $payload = fn (array $gbp) => [
            'caption' => 'Order now',
            'targets' => [['platform' => 'google_business', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_POSTED,
            'options' => ['google_business' => $gbp],
        ];

        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $payload(['cta_type' => 'ORDER']))
            ->assertStatus(422)->assertJsonPath('message', 'The Google button needs a link before publishing or scheduling.');
        $this->withHeaders($this->auth($this->user))->postJson('/api/posts', $payload(['cta_type' => 'CALL']))->assertCreated();
    }

    public function test_bad_google_options_are_refused_even_on_drafts(): void
    {
        $account = $this->account();
        $base = [
            'caption' => 'Draft',
            'targets' => [['platform' => 'google_business', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_DRAFT,
        ];

        foreach ([
            ['topic_type' => 'ALERT'],
            ['cta_type' => 'DONATE'],
            ['cta_url' => 'not a link'],
            ['event_start' => '1 November'],
            ['event_start' => '2026-11-01T18:00', 'event_end' => '2026-11-01T17:00'],
        ] as $bad) {
            $this->withHeaders($this->auth($this->user))
                ->postJson('/api/posts', $base + ['options' => ['google_business' => $bad]])
                ->assertStatus(422);
        }
    }

    public function test_event_and_offer_titles_are_limited_to_58_characters(): void
    {
        // Google's own post form caps the title at 58 characters.
        $account = $this->account();
        $post = fn (string $title) => $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Draft',
            'targets' => [['platform' => 'google_business', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_DRAFT,
            'options' => ['google_business' => ['topic_type' => 'OFFER', 'event_title' => $title]],
        ]);

        $post(str_repeat('a', 59))->assertStatus(422)
            ->assertJsonPath('message', 'A Google event or offer title can be at most 58 characters.');
        $post(str_repeat('a', 58))->assertCreated();
    }

    public function test_editing_only_the_options_saves_them_on_the_existing_targets(): void
    {
        // The AI's update_post may send just { options } to add the event
        // details to a saved draft. They must be stored, not only checked.
        Queue::fake();
        $account = $this->account();
        $id = $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Draft',
            'targets' => [['platform' => 'google_business', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_DRAFT,
        ])->assertCreated()->json('id');

        $options = ['topic_type' => 'EVENT', 'event_title' => 'Tasting', 'event_start' => '2026-11-01T18:00', 'event_end' => '2026-11-01T21:00'];
        $this->withHeaders($this->auth($this->user))->putJson("/api/posts/{$id}", [
            'status' => Post::STATUS_POSTED,
            'options' => ['google_business' => $options],
        ])->assertOk();

        $this->assertSame($options, PostTarget::where('post_id', $id)->firstOrFail()->options);
        Queue::assertPushed(PublishToGoogleBusinessJob::class);
    }

    public function test_event_times_with_seconds_are_accepted_and_stored_without_them(): void
    {
        $account = $this->account();

        $id = $this->withHeaders($this->auth($this->user))->postJson('/api/posts', [
            'caption' => 'Tasting',
            'targets' => [['platform' => 'google_business', 'social_account_id' => $account->id]],
            'status' => Post::STATUS_DRAFT,
            'options' => ['google_business' => ['topic_type' => 'EVENT', 'event_start' => '2026-11-01T18:00:00', 'event_end' => '2026-11-01 21:30']],
        ])->assertCreated()->json('id');

        $options = PostTarget::where('post_id', $id)->first()->options;
        $this->assertSame('2026-11-01T18:00', $options['event_start']);
        $this->assertSame('2026-11-01T21:30', $options['event_end']);
    }

    public function test_a_token_about_to_expire_is_refreshed_before_posting(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3599])]);

        $account = (new GoogleBusinessProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->addSeconds(30)]));

        $this->assertSame('fresh', $account->access_token);
    }

    public function test_caption_limit_is_1500_characters(): void
    {
        $this->assertSame(1500, CaptionRules::limit('google_business'));
        $this->assertSame(1500, CreatePost::PLATFORM_CHAR_LIMITS['google_business']);
    }

    public function test_an_outage_during_token_refresh_does_not_force_a_reconnect(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'backendError'], 503)]);

        $account = (new GoogleBusinessProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->subMinute()]));

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_a_revoked_grant_marks_the_account_for_reconnect(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $account = (new GoogleBusinessProvider)->ensureFreshToken($this->account(['token_expires_at' => now()->subMinute()]));

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    private function callTool(string $tool, array $arguments): \Illuminate\Testing\TestResponse
    {
        $login = $this->user->createToken('mobile-app')->plainTextToken;
        $key = $this->withHeaders(['Authorization' => 'Bearer '.$login])->postJson('/api/user/api-key/rotate')->json('data.key');

        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    public function test_the_ai_can_post_to_one_chosen_location(): void
    {
        // Two locations connected: the AI names the one to post to by its
        // social_account_id from list_connected_accounts.
        Queue::fake();
        $first = $this->account();
        $second = $this->account(['platform_account_id' => 'locations/333', 'name' => 'Corner Bakery Downtown']);

        $response = $this->callTool('create_post', [
            'social_account_ids' => [$second->id],
            'caption' => 'Only downtown',
            'status' => 'draft',
        ]);

        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $targets = PostTarget::whereHas('post', fn ($q) => $q->where('caption', 'Only downtown'))->get();
        $this->assertCount(1, $targets);
        $this->assertSame($second->id, $targets->first()->social_account_id);
        $this->assertSame('google_business', $targets->first()->platform);
        $this->assertNotSame($first->id, $targets->first()->social_account_id);
    }

    public function test_the_ai_must_choose_when_several_locations_are_connected(): void
    {
        $this->account();
        $this->account(['platform_account_id' => 'locations/333', 'name' => 'Corner Bakery Downtown']);

        $response = $this->callTool('create_post', ['platforms' => ['google_business'], 'caption' => 'Which one?']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('Corner Bakery Downtown', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, PostTarget::count());

        // With one location there is nothing to choose.
        SocialAccount::where('name', 'Corner Bakery Downtown')->delete();
        $one = $this->callTool('create_post', ['platforms' => ['google_business'], 'caption' => 'Only one']);
        $this->assertFalse((bool) $one->json('result.isError'), (string) $one->json('result.content.0.text'));
    }

    public function test_the_ai_cannot_post_to_someone_elses_account(): void
    {
        $other = User::factory()->create()->socialAccounts()->create([
            'platform' => 'google_business', 'platform_account_id' => 'locations/999',
            'access_token' => 't', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $response = $this->callTool('create_post', ['social_account_ids' => [$other->id], 'caption' => 'x']);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertSame(0, PostTarget::count());
    }

    public function test_it_stays_hidden_until_switched_on(): void
    {
        $this->assertContains('google_business', CreatePost::availablePlatforms());
        $this->assertStringContainsString('google_business: { topic_type', (new CreatePost)->schema(new \Laravel\Mcp\Server\Tools\ToolInputSchema)->toArray()['properties']['options']['description']);

        config(['social.platforms.google_business.enabled' => false]);

        $this->assertNotContains('google_business', CreatePost::availablePlatforms());
        $this->assertFalse(app(SocialProviderManager::class)->isConfigured('google_business'));
        $this->assertStringNotContainsString('google_business', (new CreatePost)->description());
    }
}
