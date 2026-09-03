<?php

namespace Tests\Feature;

use App\Events\SubscriptionStarted;
use App\Listeners\SyncKitOnSubscription;
use App\Models\User;
use App\Services\KitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Kit tagging is driven by Stripe subscription state, not registration:
 *  - registration no longer touches Kit;
 *  - a started subscription applies the converted tag (consented users) and always
 *    removes the abandoned-cart tag (SyncKitOnSubscription).
 */
class KitNewsletterSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // resolveTagId() caches per tag for a day; flush so ids don't bleed across tests.
        Cache::flush();

        config([
            'services.kit.api_key' => 'test-kit-key',
            'services.kit.api_secret' => 'test-kit-secret',
            'services.kit.tag' => 'viewsmax: new subscriber',
            'services.kit.abandoned_cart_tag' => 'viewsmax: abandoned cart',
            // Integration is production-only by default; force on so tests exercise it.
            'services.kit.enabled' => true,
        ]);
    }

    /** Registration must no longer touch Kit — the trigger moved to subscription-created. */
    public function test_registration_does_not_subscribe_to_kit(): void
    {
        Mail::fake();
        Http::fake();

        $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'marketing_consent' => true,
        ])->assertStatus(201);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'convertkit.com'));
    }

    /** Consented subscriber: converted tag applied + abandoned tag removed. */
    public function test_subscription_started_tags_converted_and_removes_abandoned_when_consented(): void
    {
        $user = $this->makeUser(consented: true);

        $kit = \Mockery::mock(KitService::class);
        $kit->shouldReceive('removeTag')->once()->with('jane@example.com', 'viewsmax: abandoned cart')->andReturn(true);
        $kit->shouldReceive('subscribe')->once()->with('jane@example.com', 'Jane Doe')->andReturn(true);

        (new SyncKitOnSubscription($kit))->handle(new SubscriptionStarted($user));
    }

    /** Non-consenter who subscribes: abandoned tag still removed, but no converted tag. */
    public function test_subscription_started_removes_abandoned_but_skips_converted_when_not_consented(): void
    {
        $user = $this->makeUser(consented: false);

        $kit = \Mockery::mock(KitService::class);
        $kit->shouldReceive('removeTag')->once()->with('jane@example.com', 'viewsmax: abandoned cart')->andReturn(true);
        $kit->shouldReceive('subscribe')->never();

        (new SyncKitOnSubscription($kit))->handle(new SubscriptionStarted($user));
    }

    /** Disabled (e.g. non-prod) → nothing hits Kit at all. */
    public function test_subscription_started_skipped_when_disabled(): void
    {
        config(['services.kit.enabled' => false]);
        $user = $this->makeUser(consented: true);

        $kit = \Mockery::mock(KitService::class);
        $kit->shouldReceive('removeTag')->never();
        $kit->shouldReceive('subscribe')->never();

        (new SyncKitOnSubscription($kit))->handle(new SubscriptionStarted($user));
    }

    /** KitService::subscribe applies the tag it is given, not just the default. */
    public function test_kitservice_subscribe_applies_given_tag(): void
    {
        Http::fake([
            'api.convertkit.com/v3/tags/555/subscribe' => Http::response(['subscription' => ['id' => 1]]),
            'api.convertkit.com/v3/tags?*' => Http::response(['tags' => [['id' => 555, 'name' => 'viewsmax: abandoned cart']]]),
        ]);

        $ok = app(KitService::class)->subscribe('lead@example.com', 'Lead', 'viewsmax: abandoned cart');

        $this->assertTrue($ok);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/555/subscribe')
            && $r['email'] === 'lead@example.com'
            && $r['first_name'] === 'Lead'
            && $r['api_key'] === 'test-kit-key');
    }

    /** KitService::removeTag posts to the tag's unsubscribe endpoint using the api_secret. */
    public function test_kitservice_remove_tag(): void
    {
        Http::fake([
            'api.convertkit.com/v3/tags/555/unsubscribe' => Http::response(['subscriber' => ['id' => 1]]),
            'api.convertkit.com/v3/tags?*' => Http::response(['tags' => [['id' => 555, 'name' => 'viewsmax: abandoned cart']]]),
        ]);

        $ok = app(KitService::class)->removeTag('lead@example.com', 'viewsmax: abandoned cart');

        $this->assertTrue($ok);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/tags/555/unsubscribe')
            && $r['api_secret'] === 'test-kit-secret'
            && $r['email'] === 'lead@example.com');
    }

    private function makeUser(bool $consented): User
    {
        $user = new User(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $user->id = 1;
        $user->marketing_consented_at = $consented ? now() : null;

        return $user;
    }
}
