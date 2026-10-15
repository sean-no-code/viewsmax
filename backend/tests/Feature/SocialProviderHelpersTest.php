<?php

namespace Tests\Feature;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\Providers\AbstractSocialProvider;
use App\Services\Social\SocialConnectException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The shared provider helpers every platform builds on: a reconnect must never
 * wipe a stored refresh token, a 401 during publish flags the account for
 * reconnect, rate limits / 5xx ask the queue to retry, and a connect that was
 * granted too few scopes fails up front with a message naming the missing ones.
 */
class SocialProviderHelpersTest extends TestCase
{
    use RefreshDatabase;

    private AbstractSocialProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.stub' => ['label' => 'Stub']]);

        $this->provider = new class extends AbstractSocialProvider
        {
            protected string $platform = 'stub';

            public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
            {
                return '';
            }

            public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
            {
                return collect();
            }

            public function publish(SocialAccount $account, SocialPost $post): PublishResult
            {
                return PublishResult::failure('unused');
            }

            public function store(User $user, array $attributes): SocialAccount
            {
                return $this->storeAccount($user, $attributes);
            }

            public function reauthIf(SocialAccount $account, Response $response, ?callable $alsoWhen = null): bool
            {
                return $this->failReauthIfUnauthorized($account, $response, 'publish', $alsoWhen);
            }

            public function transient(Response $response): void
            {
                $this->throwIfTransient($response, 'publish');
            }

            public function scopes(array $granted, array $required, string $hint = ''): void
            {
                $this->assertGrantedScopes($granted, $required, $hint);
            }
        };
    }

    private function response(int $status, string $body = ''): Response
    {
        return new Response(new PsrResponse($status, [], $body));
    }

    private function account(): SocialAccount
    {
        return User::factory()->create()->socialAccounts()->create([
            'platform' => 'stub',
            'platform_account_id' => 's-1',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /* ---------- storeAccount keeps the refresh token ---------- */

    public function test_reconnect_without_a_refresh_token_keeps_the_stored_one(): void
    {
        $user = User::factory()->create();
        $base = ['platform_account_id' => 's-1', 'access_token' => 'first'];
        $this->provider->store($user, $base + ['refresh_token' => 'keep-me']);

        $this->provider->store($user, ['platform_account_id' => 's-1', 'access_token' => 'second', 'refresh_token' => null]);
        $this->assertSame('keep-me', SocialAccount::sole()->refresh_token);

        $this->provider->store($user, ['platform_account_id' => 's-1', 'access_token' => 'third', 'refresh_token' => '']);
        $this->assertSame('keep-me', SocialAccount::sole()->refresh_token);
        $this->assertSame('third', SocialAccount::sole()->access_token);

        $this->provider->store($user, ['platform_account_id' => 's-1', 'access_token' => 'fourth', 'refresh_token' => 'rotated']);
        $this->assertSame('rotated', SocialAccount::sole()->refresh_token);
    }

    /* ---------- 401 during publish flags reconnect ---------- */

    public function test_a_401_marks_the_account_needs_reauth_with_the_provider_reason_logged(): void
    {
        Log::spy();
        $account = $this->account();

        $flagged = $this->provider->reauthIf($account, $this->response(401, '{"title":"Unauthorized","access_token":"secret-value"}'));

        $this->assertTrue($flagged);
        $account->refresh();
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
        $this->assertSame('Stub authorization expired or was revoked — reconnect the account.', $account->last_error);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return str_contains($message, 'publish rejected the token')
                && $context['status'] === 401
                && str_contains($context['body'], 'Unauthorized')
                && ! str_contains($context['body'], 'secret-value');
        })->once();
    }

    public function test_other_statuses_leave_the_account_connected_unless_the_provider_check_matches(): void
    {
        $account = $this->account();

        $this->assertFalse($this->provider->reauthIf($account, $this->response(403, '{"error":"forbidden"}')));
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);

        $revoked = fn (Response $r) => $r->status() === 403 && str_contains($r->body(), 'revoked');
        $this->assertTrue($this->provider->reauthIf($account, $this->response(403, '{"error":"token revoked"}'), $revoked));
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    /* ---------- 429 / 5xx ask the queue to retry ---------- */

    public function test_rate_limit_and_server_errors_throw_transient_with_a_friendly_message(): void
    {
        try {
            $this->provider->transient($this->response(429, 'slow down'));
            $this->fail('429 should be transient');
        } catch (TransientPublishException $e) {
            $this->assertSame('Stub rate limit hit during publish — retrying automatically.', $e->getMessage());
        }

        try {
            $this->provider->transient($this->response(503, 'upstream'));
            $this->fail('503 should be transient');
        } catch (TransientPublishException $e) {
            $this->assertSame('Stub returned a temporary error during publish (HTTP 503) — retrying automatically.', $e->getMessage());
        }

        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->provider->transient($this->response($status, 'nope'));
        }
        $this->assertTrue(AbstractSocialProvider::isTransientStatus(500));
        $this->assertTrue(AbstractSocialProvider::isTransientStatus(429));
        $this->assertFalse(AbstractSocialProvider::isTransientStatus(422));
    }

    /* ---------- granted scopes checked at connect ---------- */

    public function test_missing_scopes_fail_the_connect_naming_them(): void
    {
        $this->expectException(SocialConnectException::class);
        $this->expectExceptionMessage('Not enough permissions were granted for Stub: missing tweet.write, media.write. Reconnect and tick every box.');

        $this->provider->scopes(['tweet.read', 'users.read'], ['tweet.read', 'tweet.write', 'users.read', 'media.write']);
    }

    public function test_full_or_unreported_scopes_pass(): void
    {
        $this->provider->scopes(['a', 'b', 'extra'], ['a', 'b']);
        $this->provider->scopes([], ['a', 'b']); // provider reported nothing — can't judge, don't block

        $this->assertTrue(true);
    }

    public function test_a_custom_hint_replaces_the_default(): void
    {
        $this->expectExceptionMessage('missing w_member_social. Approve posting on your behalf.');

        $this->provider->scopes(['openid'], ['openid', 'w_member_social'], 'Approve posting on your behalf.');
    }
}
