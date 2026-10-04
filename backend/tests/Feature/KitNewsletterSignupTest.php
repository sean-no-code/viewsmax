<?php

namespace Tests\Feature;

use App\Services\KitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
