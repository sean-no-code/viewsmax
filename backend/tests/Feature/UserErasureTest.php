<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Channel;
use App\Models\Connection;
use App\Models\Offer;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SavedOutlier;
use App\Models\SocialAccount;
use App\Models\SocialHandleClaim;
use App\Models\User;
use App\Models\YouTubeQuotaSpend;
use App\Services\Social\GoogleGrantRevoker;
use App\Services\Social\HandleClaimedException;
use App\Services\Social\HandleClaims;
use App\Services\StripeService;
use App\Services\UserErasure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * App\Services\UserErasure: a GDPR erasure revokes every grant, deletes
 * everything the user owns, and leaves a tombstone row that can't be logged
 * into or matched by email. Handle claims survive so the same channel can't
 * be used to sign up again.
 */
class UserErasureTest extends TestCase
{
    use RefreshDatabase;

    private const KLAVIYO_DELETE_URL = 'https://a.klaviyo.com/api/data-privacy-deletion-jobs';

    private const KIT_UNSUBSCRIBE_URL = 'https://api.convertkit.com/v3/unsubscribe';

    private MockInterface $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('media');
        config([
            'filesystems.media_disk' => 'media',
            'services.stripe.secret' => 'sk_test',
            'services.klaviyo.api_key' => 'klaviyo-key',
            'services.klaviyo.api_url' => 'https://a.klaviyo.com',
            'services.kit.api_secret' => 'kit-secret',
        ]);
        $this->stripe = Mockery::mock(StripeService::class);
        $this->app->instance(StripeService::class, $this->stripe);
    }

    /** Every remote the erasure talks to, answering with one status. (A second Http::fake can't override the first.) */
    private function fakeRemotes(int $status = 200): void
    {
        Http::fake([
            GoogleGrantRevoker::REVOKE_URL => Http::response('', $status),
            self::KLAVIYO_DELETE_URL => Http::response('', $status === 200 ? 202 : $status),
            self::KIT_UNSUBSCRIBE_URL => Http::response(['subscriber' => []], $status),
        ]);
    }

    /** A user with a row in every store erasure must reach. Platform ids are per email so two can coexist. */
    private function fullUser(string $email = 'erase.me@example.com'): User
    {
        $suffix = substr(md5($email), 0, 6);
        $user = User::factory()->withPlan('active')->create([
            'email' => $email,
            'name' => 'Erase Me',
            'last_login_ip' => '203.0.113.9',
            'privacy_consent_at' => now(),
            'privacy_consent_ip' => '203.0.113.9',
            'marketing_consented_at' => now(),
            'stripe_customer_id' => 'cus_123',
            'youtube_access_token' => 'yt-access',
            'youtube_refresh_token' => 'yt-refresh',
        ]);

        // YouTube in all four stores (one grant), plus a TikTok account (no remote revoke exists).
        Channel::create([
            'user_id' => $user->id, 'youtube_channel_id' => "UC{$suffix}", 'channel_name' => 'Chan',
            'youtube_access_token' => 'yt-access', 'youtube_refresh_token' => 'yt-refresh', 'youtube_token_expires_at' => now()->addHour(),
        ]);
        Connection::create([
            'user_id' => $user->id, 'provider' => 'youtube', 'account_name' => 'Chan', 'account_id' => "UC{$suffix}",
            'access_token' => 'yt-access', 'refresh_token' => 'yt-refresh', 'token_expires_at' => now()->addHour(),
        ]);
        foreach ([['youtube', "UC{$suffix}", 'yt-access', 'yt-refresh'], ['tiktok', "tt{$suffix}", 'tt-access', 'tt-refresh']] as [$platform, $id, $access, $refresh]) {
            SocialAccount::create([
                'user_id' => $user->id, 'platform' => $platform, 'platform_account_id' => $id, 'name' => $platform,
                'access_token' => $access, 'refresh_token' => $refresh, 'token_expires_at' => now()->addHour(),
                'status' => SocialAccount::STATUS_CONNECTED,
            ]);
            HandleClaims::claim($user, $platform, $id, '@'.$platform);
        }

        $post = $user->posts()->create([
            'caption' => 'hi', 'status' => Post::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay(),
            'media' => [['type' => 'video', 'path' => "posts/{$user->id}/clip.mp4", 'disk' => 'media', 'url' => 'https://cdn.test/clip.mp4']],
        ]);
        $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);
        Storage::disk('media')->put("posts/{$user->id}/clip.mp4", 'video');
        Storage::disk('public')->put("thumbnails/{$user->id}/a.png", 'png');

        Offer::create(['user_id' => $user->id, 'name' => 'Offer', 'offer_url' => 'https://example.com']);
        Brand::create(['user_id' => $user->id, 'name' => 'Brand']);

        // Agent access: Sanctum login token + vmx_ key, and a Passport grant (Claude/ChatGPT).
        $user->createToken('login');
        $user->createToken('mcp');
        DB::table('oauth_access_tokens')->insert(['id' => "at-{$suffix}", 'user_id' => $user->id, 'client_id' => 'client', 'scopes' => '[]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour()]);
        DB::table('oauth_refresh_tokens')->insert(['id' => "rt-{$suffix}", 'access_token_id' => "at-{$suffix}", 'revoked' => false, 'expires_at' => now()->addMonth()]);
        DB::table('oauth_auth_codes')->insert(['id' => "ac-{$suffix}", 'user_id' => $user->id, 'client_id' => 'client', 'scopes' => '[]', 'revoked' => false, 'expires_at' => now()->addMinute()]);
        DB::table('mcp_tool_invocations')->insert(['user_id' => $user->id, 'tool' => 'list_posts', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => "sess-{$suffix}", 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 't', 'created_at' => now()]);

        $user->deposit(10);
        YouTubeQuotaSpend::create(['resource' => 'search_list', 'endpoint' => 'search', 'method' => 'GET', 'units' => 100, 'user_id' => $user->id, 'spent_at' => now(), 'pt_date' => now()->toDateString()]);
        SavedOutlier::create(['user_id' => $user->id, 'platform' => 'youtube', 'video_id' => 'v1', 'snapshot' => []]);

        return $user->fresh();
    }

    private function expectStripeCleanup(): void
    {
        $this->stripe->shouldReceive('cancelSubscription')
            ->once()->with(Mockery::type('string'), Mockery::type('string'), true)->andReturn(['status' => 'canceled']);
        $this->stripe->shouldReceive('deleteCustomer')->once()->with('cus_123')->andReturn(true);
    }

    public function test_erase_revokes_deletes_and_tombstones(): void
    {
        $this->fakeRemotes();
        $user = $this->fullUser();
        $bystander = $this->fullUser('stay@example.com');
        $id = $user->id;
        $this->expectStripeCleanup();

        $result = app(UserErasure::class)->erase($user);

        // Remote grants: the one Google grant revoked once; TikTok reported as local only.
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->url() === GoogleGrantRevoker::REVOKE_URL && $r['token'] === 'yt-refresh');
        Http::assertSent(fn ($r) => $r->url() === self::KLAVIYO_DELETE_URL && str_contains($r->body(), 'erase.me@example.com'));
        Http::assertSent(fn ($r) => $r->url() === self::KIT_UNSUBSCRIBE_URL && $r['email'] === 'erase.me@example.com');
        $this->assertSame(['tiktok'], $result['local_only_platforms']);
        $this->assertSame('ok', $result['steps']['stripe']);
        $this->assertSame('ok', $result['steps']['klaviyo']);
        $this->assertSame('ok', $result['steps']['kit']);

        // Agent access.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $id)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $id)->count());
        $this->assertSame(['rt-'.substr(md5('stay@example.com'), 0, 6)], DB::table('oauth_refresh_tokens')->pluck('id')->all(), 'only the bystander\'s refresh token remains');
        $this->assertSame(0, DB::table('oauth_auth_codes')->where('user_id', $id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'erase.me@example.com')->count());

        // Owned rows, on both connections.
        foreach (['channels', 'connections', 'social_accounts', 'posts', 'tracking_events', 'brands', 'user_plans', 'mcp_tool_invocations', 'wallets'] as $table) {
            $column = $table === 'wallets' ? 'holder_id' : 'user_id';
            $this->assertSame(0, DB::table($table)->where($column, $id)->count(), "{$table} still has rows");
        }
        $this->assertSame(0, DB::table('post_targets')->count() - DB::table('post_targets')->whereIn('post_id', $bystander->posts()->pluck('id'))->count());
        $this->assertSame(0, SavedOutlier::where('user_id', $id)->count());
        $this->assertFalse(Storage::disk('media')->exists("posts/{$id}/clip.mp4"));
        $this->assertFalse(Storage::disk('public')->exists("thumbnails/{$id}/a.png"));

        // Kept: the handle claims (still blocking) and the quota ledger (no longer attributable).
        $this->assertSame(2, SocialHandleClaim::where('user_id', $id)->count());
        $this->assertSame(1, YouTubeQuotaSpend::whereNull('user_id')->count());
        $this->assertSame(0, YouTubeQuotaSpend::where('user_id', $id)->count());

        // Tombstone.
        $tomb = User::withTrashed()->find($id);
        $this->assertTrue($tomb->trashed());
        $this->assertSame("erased+{$id}@erased.invalid", $tomb->email);
        $this->assertSame('Erased user', $tomb->name);
        foreach (['last_login_ip', 'privacy_consent_at', 'privacy_consent_ip', 'marketing_consented_at', 'stripe_customer_id', 'youtube_access_token', 'youtube_refresh_token', 'email_verified_at', 'remember_token'] as $field) {
            $this->assertNull($tomb->{$field}, "{$field} not scrubbed");
        }
        $this->assertFalse(User::withTrashed()->byEmail('erase.me@example.com')->exists());
        $this->assertSame($tomb->id, $id);

        // The bystander is untouched.
        $this->assertSame(1, $bystander->posts()->count());
        $this->assertSame(2, $bystander->socialAccounts()->count());
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $bystander->id)->count());
        $this->assertNotNull(User::find($bystander->id));
    }

    public function test_remote_failures_are_reported_and_local_deletion_still_happens(): void
    {
        $this->fakeRemotes(500);
        $user = $this->fullUser();
        $id = $user->id;
        $this->stripe->shouldReceive('cancelSubscription')->once()->andThrow(new \Exception('Stripe down'));
        $this->stripe->shouldReceive('deleteCustomer')->never();

        $result = app(UserErasure::class)->erase($user);

        $this->assertSame('failed', $result['steps']['stripe']);
        $this->assertSame('failed', $result['steps']['klaviyo']);
        $this->assertSame('failed', $result['steps']['kit']);
        $this->assertSame(0, $result['google_revoked']);
        $this->assertNotEmpty($result['failures']);

        $this->assertSame(0, DB::table('social_accounts')->where('user_id', $id)->count());
        $this->assertSame(0, DB::table('posts')->where('user_id', $id)->count());
        $this->assertTrue(User::withTrashed()->find($id)->trashed());
    }

    public function test_remote_services_without_keys_are_skipped(): void
    {
        $this->fakeRemotes();
        config(['services.stripe.secret' => null, 'services.klaviyo.api_key' => null, 'services.kit.api_secret' => null]);
        $user = $this->fullUser();
        $this->stripe->shouldReceive('cancelSubscription')->never();
        $this->stripe->shouldReceive('deleteCustomer')->never();

        $result = app(UserErasure::class)->erase($user);

        $this->assertSame('skipped', $result['steps']['stripe']);
        $this->assertSame('skipped', $result['steps']['klaviyo']);
        $this->assertSame('skipped', $result['steps']['kit']);
        Http::assertNotSent(fn ($r) => $r->url() === self::KLAVIYO_DELETE_URL);
        $this->assertTrue(User::withTrashed()->find($user->id)->trashed());
    }

    public function test_plan_is_read_only_and_names_every_user_table(): void
    {
        $this->fakeRemotes();
        $user = $this->fullUser();
        $before = [DB::table('posts')->count(), DB::table('social_accounts')->count(), DB::table('personal_access_tokens')->count()];

        $plan = app(UserErasure::class)->plan($user);

        $this->assertSame($before, [DB::table('posts')->count(), DB::table('social_accounts')->count(), DB::table('personal_access_tokens')->count()]);
        Http::assertNothingSent();
        $this->assertFalse($user->fresh()->trashed());

        foreach (['posts', 'social_accounts', 'channels', 'connections', 'tracking_events', 'brands', 'user_plans', 'mcp_tool_invocations', 'thumbnails', 'library_components'] as $table) {
            $this->assertArrayHasKey($table, $plan['tables'], "{$table} missing from the erasure plan");
        }
        $this->assertArrayNotHasKey('social_handle_claims', $plan['tables']);
        $this->assertArrayNotHasKey('youtube_quota_spends', $plan['tables']);
        $this->assertSame(1, $plan['tables']['posts']);
        $this->assertSame(['tiktok'], $plan['local_only_platforms']);
        $this->assertSame('cus_123', $plan['stripe_customer_id']);
        $this->assertCount(1, $plan['stripe_subscriptions']);
    }

    public function test_the_erased_users_handles_stay_claimed_with_a_deleted_account_message(): void
    {
        $this->fakeRemotes();
        $user = $this->fullUser();
        $this->expectStripeCleanup();
        app(UserErasure::class)->erase($user);

        $other = User::factory()->create();
        try {
            HandleClaims::claim($other, 'tiktok', 'tt'.substr(md5('erase.me@example.com'), 0, 6), '@tiktok');
            $this->fail('A claim held by an erased account must still block');
        } catch (HandleClaimedException $e) {
            $this->assertStringContainsString('has since been deleted', $e->getMessage());
            $this->assertStringNotContainsString('@erased.invalid', $e->getMessage());
            $this->assertStringNotContainsString('Sign in', $e->getMessage());
        }
        $this->assertSame($user->id, SocialHandleClaim::where('platform', 'tiktok')->sole()->user_id);
    }
}
