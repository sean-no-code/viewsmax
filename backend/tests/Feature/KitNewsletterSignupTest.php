<?php

namespace Tests\Feature;

use App\Services\KitService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registration adds consenting users to the Kit newsletter inline. A Kit failure
 * of any kind must never stop the account from being created.
 */
class KitNewsletterSignupTest extends TestCase
{
    use RefreshDatabase;

    private const TAGS_URL = 'api.convertkit.com/v3/tags?*';

    private const SUBSCRIBE_URL = 'api.convertkit.com/v3/tags/555/subscribe';

    private const CREATE_URL = 'api.convertkit.com/v3/tags';

    protected function setUp(): void
    {
        parent::setUp();

        // resolveTagId() caches per tag for a day; flush so ids don't bleed across tests.
        Cache::flush();
        Mail::fake();

        config([
            'services.kit.api_key' => 'test-kit-key',
            'services.kit.tag' => 'viewsmax: new subscriber',
        ]);
    }

    public function test_consenting_registration_subscribes_to_kit(): void
    {
        Http::fake([
            self::TAGS_URL => Http::response(['tags' => [['id' => 555, 'name' => 'viewsmax: new subscriber']]]),
            self::SUBSCRIBE_URL => Http::response(['subscription' => ['id' => 1]]),
        ]);

        $this->register(consent: true)->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/555/subscribe')
            && $r['email'] === 'jane@example.com'
            && $r['first_name'] === 'Jane Doe'
            && $r['api_key'] === 'test-kit-key');
    }

    public function test_agent_signup_on_the_api_host_also_gets_the_ai_agent_tag(): void
    {
        Http::fake([
            self::TAGS_URL => Http::response(['tags' => [
                ['id' => 555, 'name' => 'viewsmax: new subscriber'],
                ['id' => 556, 'name' => 'viewsmax: new subscriber ai agent'],
            ]]),
            self::SUBSCRIBE_URL => Http::response(['subscription' => ['id' => 1]]),
            'api.convertkit.com/v3/tags/556/subscribe' => Http::response(['subscription' => ['id' => 2]]),
        ]);

        $this->post('/register', [
            'name' => 'Jane Doe', 'email' => 'jane@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'marketing_consent' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'signup_source' => 'agent']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/555/subscribe') && $r['email'] === 'jane@example.com');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/556/subscribe') && $r['email'] === 'jane@example.com');
    }

    public function test_app_signup_does_not_get_the_ai_agent_tag(): void
    {
        Http::fake([
            self::TAGS_URL => Http::response(['tags' => [['id' => 555, 'name' => 'viewsmax: new subscriber']]]),
            self::SUBSCRIBE_URL => Http::response(['subscription' => ['id' => 1]]),
        ]);

        $this->register(consent: true)->assertStatus(201);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v3/tags') && str_contains((string) json_encode($r->data()), 'ai agent'));
        Http::assertSentCount(2); // one tag lookup + one subscribe
    }

    public function test_api_registration_records_the_app_signup_source(): void
    {
        Http::fake();

        $this->register(consent: false)->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'signup_source' => 'app', 'signup_client' => null]);
    }

    public function test_registration_without_consent_does_not_touch_kit(): void
    {
        Http::fake();

        $this->register(consent: false)->assertStatus(201);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'convertkit.com'));
    }

    public function test_kit_http_failure_does_not_block_registration(): void
    {
        Http::fake([
            self::TAGS_URL => Http::response(['tags' => [['id' => 555, 'name' => 'viewsmax: new subscriber']]]),
            self::SUBSCRIBE_URL => Http::response('boom', 500),
        ]);

        $this->register(consent: true)->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    public function test_kit_exception_does_not_block_registration(): void
    {
        $kit = \Mockery::mock(KitService::class);
        $kit->shouldReceive('subscribe')->once()->andThrow(new \RuntimeException('kit down'));
        $this->app->instance(KitService::class, $kit);

        $this->register(consent: true)->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    /** A cache store that can't be written (e.g. storage perms) must not lose the subscribe. */
    public function test_unwritable_cache_falls_back_to_uncached_tag_lookup(): void
    {
        // Mirror production: reads miss, writes blow up like file_put_contents on a dir we don't own.
        Cache::extend('unwritable', fn () => Cache::repository(new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('file_put_contents(storage/framework/cache/data/..): Permission denied');
            }
        }));
        config(['cache.stores.unwritable' => ['driver' => 'unwritable'], 'cache.default' => 'unwritable']);

        Http::fake([
            self::TAGS_URL => Http::response(['tags' => [['id' => 555, 'name' => 'viewsmax: new subscriber']]]),
            self::SUBSCRIBE_URL => Http::response(['subscription' => ['id' => 1]]),
        ]);

        $this->register(consent: true)->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/555/subscribe') && $r['email'] === 'jane@example.com');
        Http::assertSentCount(2); // one tag lookup + one subscribe: the failed cache write must not re-run the lookup
    }

    /**
     * The tag-creation path, exercised through the service rather than
     * registration: a tag the account doesn't have yet (how the "ai agent" tag
     * started out) is missing from GET /tags and has to be created. Kit's v3
     * create endpoint authenticates with the *secret* key — passing api_key,
     * as this used to, could only ever fail the create and skip the subscribe.
     */
    public function test_a_tag_missing_from_the_account_is_created_with_the_api_secret(): void
    {
        config(['services.kit.api_secret' => 'test-kit-secret']);

        Http::fake([
            self::TAGS_URL => Http::response(['tags' => []]),
            self::CREATE_URL => Http::response(['id' => 777, 'name' => 'viewsmax: new subscriber']),
            'api.convertkit.com/v3/tags/777/subscribe' => Http::response(['subscription' => ['id' => 1]]),
        ]);

        $this->assertTrue(app(KitService::class)->subscribe('jane@example.com', 'Jane Doe'));

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'https://'.self::CREATE_URL
            && $r['api_secret'] === 'test-kit-secret'
            && $r['tag']['name'] === 'viewsmax: new subscriber');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/777/subscribe')
            && $r['email'] === 'jane@example.com');
    }

    /** Kit answers an array when asked for several tags; one created tag must still resolve. */
    public function test_a_created_tag_returned_as_an_array_still_resolves(): void
    {
        config(['services.kit.api_secret' => 'test-kit-secret']);

        Http::fake([
            self::TAGS_URL => Http::response(['tags' => []]),
            self::CREATE_URL => Http::response([['id' => 888, 'name' => 'viewsmax: new subscriber']]),
            'api.convertkit.com/v3/tags/888/subscribe' => Http::response(['subscription' => ['id' => 1]]),
        ]);

        $this->assertTrue(app(KitService::class)->subscribe('jane@example.com'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/888/subscribe'));
    }

    /** Without the secret the create can only 401, so say what to do instead of trying. */
    public function test_a_missing_tag_without_the_api_secret_is_logged_and_not_created(): void
    {
        config(['services.kit.api_secret' => null]);
        Log::spy();

        Http::fake([self::TAGS_URL => Http::response(['tags' => []])]);

        $this->assertFalse(app(KitService::class)->subscribe('jane@example.com'));

        Http::assertSentCount(1); // the lookup only: no create that cannot work
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'KIT_API_SECRET'))
            ->once();
    }

    /** A failed lookup is reported with Kit's status and body, and creates nothing. */
    public function test_a_failed_tag_lookup_is_logged_with_the_response_body(): void
    {
        config(['services.kit.api_secret' => 'test-kit-secret']);
        Log::spy();

        Http::fake([self::TAGS_URL => Http::response('Authorization Required', 401)]);

        $this->assertFalse(app(KitService::class)->subscribe('jane@example.com'));

        Http::assertSentCount(1);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, '401')
                && str_contains($message, 'Authorization Required'))
            ->once();
    }

    public function test_missing_api_key_skips_kit(): void
    {
        config(['services.kit.api_key' => null]);
        Http::fake();

        $this->register(consent: true)->assertStatus(201);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'convertkit.com'));
    }

    private function register(bool $consent)
    {
        return $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'marketing_consent' => $consent,
        ]);
    }
}
