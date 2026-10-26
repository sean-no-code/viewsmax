<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * social:refresh-tokens keeps every refreshable provider alive while
 * the user is idle — X, LinkedIn, TikTok and YouTube included, not just the
 * Meta family — through each provider's own ensureFreshToken(). Accounts
 * flagged needs_reauth are skipped, a transient refresh failure leaves the
 * account connected, and only a definitive rejection flags it.
 */
class RefreshSocialTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URLS = [
        'x' => 'api.twitter.com/2/oauth2/token',
        'linkedin' => 'www.linkedin.com/oauth/v2/accessToken',
        'tiktok' => 'open.tiktokapis.com/v2/oauth/token/',
        'youtube' => 'oauth2.googleapis.com/token',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        foreach (array_keys(self::TOKEN_URLS) as $platform) {
            config(["social.platforms.{$platform}.client_id" => 'id', "social.platforms.{$platform}.client_secret" => 'secret']);
        }
    }

    private function account(string $platform, array $overrides = []): SocialAccount
    {
        return $this->user->socialAccounts()->create(array_merge([
            'platform' => $platform,
            'platform_account_id' => $platform.'-'.uniqid(),
            'name' => 'Tester',
            'access_token' => 'old-access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->subHour(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ], $overrides));
    }

    /** Fake every token endpoint to hand out a renewed token (or the given response). */
    private function fakeTokenEndpoints(array $responses = []): void
    {
        $fakes = [];
        foreach (self::TOKEN_URLS as $platform => $url) {
            $fakes[$url] = $responses[$platform] ?? Http::response([
                'access_token' => "new-{$platform}", 'refresh_token' => 'rotated', 'expires_in' => 7200, 'scope' => 'a b',
            ]);
        }
        Http::fake($fakes);
    }

    private function refreshCallsFor(string $platform): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), self::TOKEN_URLS[$platform]))->count();
    }

    public function test_every_refreshable_provider_is_renewed_before_the_user_publishes(): void
    {
        $this->fakeTokenEndpoints();
        $accounts = collect(array_keys(self::TOKEN_URLS))->mapWithKeys(fn ($p) => [$p => $this->account($p)]);

        $this->artisan('social:refresh-tokens')
            ->expectsOutputToContain('4 refreshed, 0 now need reconnect, 0 errored')
            ->assertExitCode(0);

        foreach ($accounts as $platform => $account) {
            $account->refresh();
            $this->assertSame("new-{$platform}", $account->access_token, $platform);
            $this->assertSame('rotated', $account->refresh_token, $platform);
            $this->assertTrue($account->token_expires_at->isFuture(), $platform);
            $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status, $platform);
            $this->assertSame(1, $this->refreshCallsFor($platform), $platform);
        }

        // Each provider used its own refresh grant.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), self::TOKEN_URLS['linkedin']) && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), self::TOKEN_URLS['tiktok']) && $r['client_key'] === 'id' && $r['grant_type'] === 'refresh_token');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), self::TOKEN_URLS['youtube']) && $r['grant_type'] === 'refresh_token');
    }

    public function test_accounts_that_need_reconnect_or_are_not_due_are_left_alone(): void
    {
        $this->fakeTokenEndpoints();
        $dead = $this->account('x', ['status' => SocialAccount::STATUS_NEEDS_REAUTH]);
        $deadLinkedIn = $this->account('linkedin', ['status' => SocialAccount::STATUS_NEEDS_REAUTH]);
        $far = $this->account('linkedin', ['token_expires_at' => now()->addDays(45)]);
        $noRefreshPath = $this->account('x', ['refresh_token' => null]);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $dead->fresh()->status);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $deadLinkedIn->fresh()->status);
        $this->assertSame('old-access', $far->fresh()->access_token);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $noRefreshPath->fresh()->status);
    }

    public function test_a_transient_refresh_failure_keeps_the_account_connected_for_the_next_run(): void
    {
        $this->fakeTokenEndpoints([
            'x' => Http::response(['error' => 'overloaded'], 503),
            'linkedin' => Http::response('bad gateway', 502),
            'tiktok' => Http::response(['error' => 'rate_limit_exceeded'], 429),
            'youtube' => Http::response(['error' => 'internal_failure'], 500),
        ]);
        $accounts = collect(array_keys(self::TOKEN_URLS))->map(fn ($p) => $this->account($p));

        $this->artisan('social:refresh-tokens')
            ->expectsOutputToContain('0 refreshed, 0 now need reconnect, 0 errored')
            ->assertExitCode(0);

        foreach ($accounts as $account) {
            $account->refresh();
            $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status, $account->platform);
            $this->assertSame('old-access', $account->access_token, $account->platform);
            $this->assertSame('refresh', $account->refresh_token, $account->platform);
        }
    }

    public function test_a_definitive_rejection_flags_the_account_for_reconnect(): void
    {
        $this->fakeTokenEndpoints([
            'linkedin' => Http::response(['error' => 'invalid_grant'], 400),
            'tiktok' => Http::response(['error' => 'invalid_grant'], 401),
            'youtube' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $healthy = $this->account('x');
        $rejected = collect(['linkedin', 'tiktok', 'youtube'])->map(fn ($p) => $this->account($p));

        $this->artisan('social:refresh-tokens')
            ->expectsOutputToContain('1 refreshed, 3 now need reconnect, 0 errored')
            ->assertExitCode(0);

        $this->assertSame('new-x', $healthy->fresh()->access_token);
        foreach ($rejected as $account) {
            $account->refresh();
            $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status, $account->platform);
            $this->assertStringContainsString('reconnect the account', (string) $account->last_error, $account->platform);
        }
    }

    public function test_one_provider_blowing_up_does_not_stop_the_others(): void
    {
        Exceptions::fake();
        $this->fakeTokenEndpoints([
            'tiktok' => fn () => throw new ConnectionException('cURL error 28: timed out'),
        ]);
        $tiktok = $this->account('tiktok');
        $x = $this->account('x');

        // The other accounts are still refreshed, but the blow-up is reported
        // and the command exits non-zero so the scheduler's onFailure fires.
        $this->artisan('social:refresh-tokens')
            ->expectsOutputToContain('1 refreshed, 0 now need reconnect, 1 errored')
            ->assertExitCode(1);

        Exceptions::assertReported(fn (ConnectionException $e) => str_contains($e->getMessage(), 'timed out'));
        $this->assertSame('new-x', $x->fresh()->access_token);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $tiktok->fresh()->status);
    }

    public function test_tokens_of_users_without_access_are_left_alone(): void
    {
        $this->fakeTokenEndpoints();
        $this->user->forceFill(['promo_expires_at' => now()->subDay()])->save();
        $account = $this->account('linkedin');

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        $this->assertSame('old-access', $account->refresh()->access_token);
        $this->assertSame(0, $this->refreshCallsFor('linkedin'));
    }
}
