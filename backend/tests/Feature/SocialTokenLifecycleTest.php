<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
