<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Token lifecycle: connected accounts must STAY connected.
 *
 * - An expired ACCESS token is normal (X's last 2 hours) — as long as a
 *   refresh path exists the account is healthy and the UI must not demand a
 *   reconnect.
 * - Only a DEFINITIVE auth failure (400/401/403) may mark needs_reauth; a
 *   transient failure (429/5xx/network) must leave the account connected.
 * - Concurrent publishes must not race the (single-use) refresh token.
 * - social:refresh-tokens keeps soon-expiring tokens alive proactively —
 *   Meta-family tokens can only be refreshed BEFORE they expire.
 */
class SocialTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake(); // a retried TikTok refresh pauses between attempts
        $this->user = User::factory()->create();
    }

    private function account(string $platform, array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => $platform,
            'platform_account_id' => $platform.'-1',
            'name' => 'Tester',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->subHour(), // access token expired
            'status' => SocialAccount::STATUS_CONNECTED,
        ], $overrides));
    }

    /* ---------- token_valid semantics (the UI's "Reconnect" trigger) ---------- */

    public function test_expired_access_token_with_refresh_path_still_reports_valid(): void
    {
        $token = $this->user->createToken('t')->plainTextToken;
        $this->account('x'); // expired 1h ago, refresh_token present

        $data = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/social/accounts')->json('data');

        $this->assertTrue($data[0]['token_valid'], 'An expired access token with a refresh token must NOT demand a reconnect');
    }

    public function test_needs_reauth_and_dead_end_accounts_report_invalid(): void
    {
        $token = $this->user->createToken('t')->plainTextToken;
        $this->account('x', ['platform_account_id' => 'x-r', 'status' => SocialAccount::STATUS_NEEDS_REAUTH]);
        $this->account('threads', ['platform_account_id' => 'th-d', 'refresh_token' => null]); // expired, no refresh path

        $data = collect($this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/social/accounts')->json('data'))->keyBy('platform_account_id');

        $this->assertFalse($data['x-r']['token_valid']);
        $this->assertFalse($data['th-d']['token_valid']);
    }

    /* ---------- transient failures must not brick the account ---------- */

    public function test_transient_x_refresh_failure_keeps_account_connected(): void
    {
        Http::fake(['api.twitter.com/2/oauth2/token' => Http::response(['error' => 'overloaded'], 503)]);
        $account = $this->account('x');

        app(SocialProviderManager::class)->for('x')->ensureFreshToken($account);

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_definitive_x_refresh_failure_marks_needs_reauth(): void
    {
        Http::fake(['api.twitter.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $account = $this->account('x');

        app(SocialProviderManager::class)->for('x')->ensureFreshToken($account);

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    public function test_transient_linkedin_refresh_failure_keeps_account_connected(): void
    {
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response('bad gateway', 502)]);
        $account = $this->account('linkedin');

        app(SocialProviderManager::class)->for('linkedin')->ensureFreshToken($account);

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    public function test_transient_threads_refresh_failure_keeps_account_connected(): void
    {
        Http::fake(['graph.threads.net/refresh_access_token*' => Http::response(['error' => 'oops'], 500)]);
        $account = $this->account('threads', ['refresh_token' => null]);

        app(SocialProviderManager::class)->for('threads')->ensureFreshToken($account);

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }

    /* ---------- refresh race (single-use rotating tokens) ---------- */

    public function test_stale_instance_reuses_a_concurrent_refresh_instead_of_rerefreshing(): void
    {
        Http::fake(); // any refresh call would be recorded
        $account = $this->account('x');
        $stale = SocialAccount::find($account->id); // what a parallel job would hold

        // Another worker refreshed meanwhile: DB now holds a fresh token.
        $account->forceFill([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'token_expires_at' => now()->addHours(2),
        ])->save();

        $result = app(SocialProviderManager::class)->for('x')->ensureFreshToken($stale);

        Http::assertNothingSent();
        $this->assertSame('fresh-access', $result->access_token);
    }

    /* ---------- TikTok: renew early, and judge a refresh by its body ---------- */

    private const TIKTOK_TOKEN_URL = 'open.tiktokapis.com/v2/oauth/token/';

    private function tiktok(array $overrides = []): SocialAccount
    {
        config(['social.platforms.tiktok.client_id' => 'id', 'social.platforms.tiktok.client_secret' => 'secret']);

        return $this->account('tiktok', $overrides);
    }

    private function tiktokTokenCalls(): int
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), self::TIKTOK_TOKEN_URL))->count();
    }

    public function test_tiktok_renews_a_token_in_its_last_hour(): void
    {
        // Our stored expiry runs slightly behind TikTok's own clock, so a token
        // used in its final second is rejected. Renew well before that.
        Http::fake([self::TIKTOK_TOKEN_URL => Http::response(['access_token' => 'renewed', 'refresh_token' => 'rotated', 'expires_in' => 86400])]);
        $closing = $this->tiktok(['platform_account_id' => 'tt-closing', 'token_expires_at' => now()->addMinutes(30)]);
        $roomy = $this->tiktok(['platform_account_id' => 'tt-roomy', 'token_expires_at' => now()->addHours(2)]);

        $provider = app(SocialProviderManager::class)->for('tiktok');
        $provider->ensureFreshToken($closing);
        $provider->ensureFreshToken($roomy);

        $this->assertSame('renewed', $closing->fresh()->access_token);
        $this->assertSame('rotated', $closing->fresh()->refresh_token);
        $this->assertSame('access', $roomy->fresh()->access_token);
        $this->assertSame(1, $this->tiktokTokenCalls());
    }

    public function test_tiktok_refresh_error_with_http_200_never_overwrites_the_stored_token(): void
    {
        // TikTok answers a failed refresh with a 2xx and an error body.
        Http::fake([self::TIKTOK_TOKEN_URL => Http::response(['error' => 'invalid_request', 'error_description' => 'malformed', 'log_id' => 'L1'], 200)]);
        $account = $this->tiktok();

        app(SocialProviderManager::class)->for('tiktok')->ensureFreshToken($account);

        $account->refresh();
        $this->assertSame('access', $account->access_token);
        $this->assertSame('refresh', $account->refresh_token);
        $this->assertNotNull($account->token_expires_at);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status);
    }

    public static function tiktokInvalidGrantStatuses(): array
    {
        return ['200' => [200], '400' => [400], '401' => [401]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tiktokInvalidGrantStatuses')]
    public function test_tiktok_invalid_grant_flags_the_account_whatever_the_status(int $status): void
    {
        Http::fake([self::TIKTOK_TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.'], $status)]);
        $account = $this->tiktok();

        app(SocialProviderManager::class)->for('tiktok')->ensureFreshToken($account);

        $account->refresh();
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
        $this->assertSame('access', $account->access_token, 'the stored token is left as it was');
    }

    public function test_tiktok_invalid_client_does_not_flag_the_account(): void
    {
        // Our own credentials being wrong is not the user's grant dying: it
        // must not ask every TikTok user to reconnect.
        Http::fake([self::TIKTOK_TOKEN_URL => Http::response(['error' => 'invalid_client', 'error_description' => 'Client key or secret is incorrect.'], 401)]);
        $account = $this->tiktok();

        app(SocialProviderManager::class)->for('tiktok')->ensureFreshToken($account);

        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
        $this->assertSame(1, $this->tiktokTokenCalls(), 'a real answer from TikTok is not retried');
    }

    public function test_tiktok_refresh_retries_a_5xx_then_saves_the_new_token(): void
    {
        Http::fake([self::TIKTOK_TOKEN_URL => Http::sequence()
            ->push(['error' => 'server_error'], 503)
            ->push(['access_token' => 'renewed', 'expires_in' => 86400])]);
        $account = $this->tiktok();

        app(SocialProviderManager::class)->for('tiktok')->ensureFreshToken($account);

        $this->assertSame('renewed', $account->fresh()->access_token);
        $this->assertSame('refresh', $account->fresh()->refresh_token, 'no new refresh token was sent, so the old one stays');
        $this->assertSame(2, $this->tiktokTokenCalls());
    }

    public function test_tiktok_renews_a_rejected_token_that_is_still_valid_by_the_clock(): void
    {
        Http::fake([self::TIKTOK_TOKEN_URL => Http::response(['access_token' => 'renewed', 'expires_in' => 86400])]);
        $account = $this->tiktok(['token_expires_at' => now()->addHours(5)]);

        $fresh = app(SocialProviderManager::class)->for('tiktok')->refreshRejectedToken($account, 'access');

        $this->assertSame('renewed', $fresh->access_token);
        $this->assertSame(1, $this->tiktokTokenCalls());
    }

    public function test_tiktok_rejected_token_reuses_a_refresh_another_worker_already_made(): void
    {
        Http::fake();
        $account = $this->tiktok(['token_expires_at' => now()->addHours(5)]);
        $stale = SocialAccount::find($account->id);
        $account->forceFill(['access_token' => 'theirs', 'token_expires_at' => now()->addDay()])->save();

        $fresh = app(SocialProviderManager::class)->for('tiktok')->refreshRejectedToken($stale, 'access');

        Http::assertNothingSent();
        $this->assertSame('theirs', $fresh->access_token);
    }

    /* ---------- proactive refresh keeps Meta-family tokens alive ---------- */

    public function test_threads_refreshes_early_while_the_token_is_still_valid(): void
    {
        Http::fake([
            'graph.threads.net/refresh_access_token*' => Http::response([
                'access_token' => 'renewed', 'expires_in' => 60 * 60 * 24 * 60,
            ]),
        ]);
        // Valid but expiring within the early-refresh window.
        $account = $this->account('threads', ['refresh_token' => null, 'token_expires_at' => now()->addDays(3)]);

        app(SocialProviderManager::class)->for('threads')->ensureFreshToken($account);

        $this->assertSame('renewed', $account->fresh()->access_token);
    }

    public function test_refresh_tokens_command_renews_soon_expiring_accounts_only(): void
    {
        $calls = [];
        Http::fake([
            'api.twitter.com/2/oauth2/token' => function () use (&$calls) {
                $calls[] = 'x';

                return Http::response(['access_token' => 'new', 'refresh_token' => 'new-r', 'expires_in' => 7200]);
            },
        ]);

        $soon = $this->account('x', ['platform_account_id' => 'x-soon']); // expired → due
        $far = $this->account('x', ['platform_account_id' => 'x-far', 'token_expires_at' => now()->addDays(60)]);
        $dead = $this->account('x', ['platform_account_id' => 'x-dead', 'status' => SocialAccount::STATUS_NEEDS_REAUTH]);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        $this->assertCount(1, $calls);
        $this->assertSame('new', $soon->fresh()->access_token);
        $this->assertSame('access', $far->fresh()->access_token);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $dead->fresh()->status);
    }

    public function test_refresh_tokens_command_reports_an_unexpected_refresh_failure(): void
    {
        // A refresh that never succeeds is terminal — once the token expires it
        // cannot be refreshed at all and the user must reconnect. So the command
        // must not swallow the cause: report it and exit non-zero.
        \Illuminate\Support\Facades\Exceptions::fake();
        $this->account('x', ['platform_account_id' => 'x-boom']);

        $provider = \Mockery::mock(\App\Services\Social\Contracts\SocialProviderInterface::class);
        $provider->shouldReceive('ensureFreshToken')->andThrow(new \RuntimeException('refresh endpoint exploded'));
        $this->mock(SocialProviderManager::class, function ($mock) use ($provider) {
            $mock->shouldReceive('supports')->andReturn(true);
            $mock->shouldReceive('for')->andReturn($provider);
        });

        $this->artisan('social:refresh-tokens')->assertExitCode(1);

        \Illuminate\Support\Facades\Exceptions::assertReported(
            fn (\RuntimeException $e) => $e->getMessage() === 'refresh endpoint exploded'
        );
    }
}
