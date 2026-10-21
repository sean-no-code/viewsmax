<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\FacebookProvider;
use App\Services\Social\SocialConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Facebook Login hands back a token even when the user unticks a permission
 * on the consent screen. Storing the Pages anyway meant every later post
 * failed with a raw (#200)/190 body, so the connect checks /me/permissions
 * and fails with the permissions to re-tick.
 */
class FacebookConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.facebook.client_id' => 'id', 'social.platforms.facebook.client_secret' => 'secret']);
    }

    /** @param array<int, array{permission: string, status: string}> $permissions */
    private function fakeGraph(array $permissions): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'user-token'], 200),
            'graph.facebook.com/*/me/permissions*' => Http::response(['data' => $permissions], 200),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => 'page-1', 'name' => 'My Page', 'access_token' => 'page-token', 'category' => 'Brand'],
            ]], 200),
        ]);
    }

    public function test_connect_stores_the_pages_when_every_permission_is_granted(): void
    {
        $this->fakeGraph([
            ['permission' => 'public_profile', 'status' => 'granted'],
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'granted'],
            ['permission' => 'pages_read_engagement', 'status' => 'granted'],
        ]);

        (new FacebookProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $account = SocialAccount::sole();
        $this->assertSame('facebook', $account->platform);
        $this->assertSame('page-1', $account->platform_account_id);
        $this->assertSame('page-token', $account->access_token);
        $this->assertSame('connected', $account->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/permissions')
            && $request['access_token'] === 'user-token');
    }

    public function test_declined_publish_permission_fails_the_connect_naming_it(): void
    {
        $this->fakeGraph([
            ['permission' => 'public_profile', 'status' => 'granted'],
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'declined'],
            // pages_read_engagement absent entirely
        ]);

        try {
            (new FacebookProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');
            $this->fail('expected the connect to fail');
        } catch (SocialConnectException $e) {
            $this->assertStringContainsString('pages_manage_posts', $e->getMessage());
            $this->assertStringContainsString('pages_read_engagement', $e->getMessage());
            $this->assertStringNotContainsString('pages_show_list', $e->getMessage());
            $this->assertStringContainsStringIgnoringCase('reconnect', $e->getMessage());
        }

        // Nothing half-connected may be stored, and the Pages are never fetched.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/accounts'));
        $this->assertSame(0, SocialAccount::count());
    }

    private const ALL_GRANTED = [
        ['permission' => 'public_profile', 'status' => 'granted'],
        ['permission' => 'pages_show_list', 'status' => 'granted'],
        ['permission' => 'pages_manage_posts', 'status' => 'granted'],
        ['permission' => 'pages_read_engagement', 'status' => 'granted'],
    ];

    /** @param array<int, array<string, mixed>> $pages */
    private function fakeGraphWithPages(array $pages): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'user-token'], 200),
            'graph.facebook.com/*/me/permissions*' => Http::response(['data' => self::ALL_GRANTED], 200),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => $pages], 200),
        ]);
    }

    public function test_a_page_listed_without_a_page_token_is_skipped_and_the_others_are_stored(): void
    {
        // A Page reached through a business the user has no role on is listed
        // without access_token; it used to be stored with a null token and
        // look connected while every publish failed.
        $this->fakeGraphWithPages([
            ['id' => 'page-1', 'name' => 'Managed', 'access_token' => 'page-token'],
            ['id' => 'page-2', 'name' => 'Business only'],
            ['id' => 'page-3', 'name' => 'Empty token', 'access_token' => ''],
        ]);

        $accounts = (new FacebookProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $this->assertSame(['page-1'], $accounts->pluck('platform_account_id')->all());
        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('page-token', SocialAccount::sole()->access_token);
    }

    public function test_connect_fails_with_the_page_role_message_when_no_page_has_a_token(): void
    {
        $this->fakeGraphWithPages([
            ['id' => 'page-2', 'name' => 'Business only'],
        ]);

        try {
            (new FacebookProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');
            $this->fail('expected the connect to fail');
        } catch (SocialConnectException $e) {
            $this->assertSame(FacebookProvider::MESSAGE_NO_PAGE_TOKEN, $e->getMessage());
            $this->assertStringContainsString('no permission to manage this page', $e->getMessage());
        }

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_user_with_no_pages_connects_nothing_without_an_error(): void
    {
        $this->fakeGraphWithPages([]);

        $accounts = (new FacebookProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');

        $this->assertTrue($accounts->isEmpty());
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_permission_lookup_failure_fails_the_connect(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'user-token'], 200),
            'graph.facebook.com/*/me/permissions*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 400),
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        $this->expectException(\RuntimeException::class);

        (new FacebookProvider)->connectFromCode(User::factory()->create(), 'code', 'https://app/cb');
    }
}
