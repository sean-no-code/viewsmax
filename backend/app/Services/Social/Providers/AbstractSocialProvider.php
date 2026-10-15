<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\SocialConnectException;
use App\Support\SafeHttp;
use App\Support\UnsafeUrlException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

abstract class AbstractSocialProvider implements SocialProviderInterface
{
    /**
     * Platform key, e.g. "facebook". Subclasses must set this.
     */
    protected string $platform;

    public function key(): string
    {
        return $this->platform;
    }

    public function usesOAuth(): bool
    {
        return true;
    }

    /**
     * Read this platform's config block.
     */
    protected function config(?string $key = null, $default = null)
    {
        $base = config("social.platforms.{$this->platform}", []);

        return $key === null ? $base : data_get($base, $key, $default);
    }

    /**
     * User-facing platform name ("X", "LinkedIn") for error messages.
     */
    protected function label(): string
    {
        return (string) ($this->config('label') ?: ucfirst($this->platform));
    }

    protected function clientId(): string
    {
        $id = $this->config('client_id');
        if (empty($id)) {
            throw new RuntimeException(ucfirst($this->platform).' client_id is not configured.');
        }

        return $id;
    }

    protected function clientSecret(): string
    {
        $secret = $this->config('client_secret');
        if (empty($secret)) {
            throw new RuntimeException(ucfirst($this->platform).' client_secret is not configured.');
        }

        return $secret;
    }

    /**
     * Default: credential-based connection is unsupported (OAuth providers).
     */
    public function connectWithCredentials(User $user, array $credentials): Collection
    {
        throw new RuntimeException(ucfirst($this->platform).' does not support credential-based connection.');
    }

    /**
     * Default: no refresh capability. OAuth providers override this.
     */
    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        return $account;
    }

    /**
     * Current follower/subscriber count for this account, or null when the
     * platform / API plan doesn't expose it. Consumed by audience:refresh.
     * Default: unsupported.
     */
    public function fetchFollowerCount(SocialAccount $account): ?int
    {
        return null;
    }

    /**
     * Engagement metrics for the given remote post ids, keyed by id. Only ids
     * the platform returns data for appear. Consumed by posts:refresh-metrics.
     * Default: unsupported (empty).
     *
     * @param  array<int, string>  $remotePostIds
     * @return array<string, array{likes:int, comments:int, shares:int, views:int}>
     */
    public function fetchPostMetrics(SocialAccount $account, array $remotePostIds): array
    {
        return [];
    }

    /**
     * Serialize token refreshes per account and re-read state inside the
     * lock. Several publish jobs can hit an expired token at once; with
     * single-use rotating refresh tokens (X) the losers would burn a stale
     * token, get invalid_grant, and wrongly brick the account. Inside the
     * lock: if another worker already refreshed, reuse its result.
     *
     * @param  callable(SocialAccount): SocialAccount  $refresh
     * @param  (callable(SocialAccount): bool)|null  $isFresh  "no refresh needed
     *         anymore" check re-run inside the lock; defaults to hasValidToken()
     *         (early-refreshing providers pass their own window check)
     */
    protected function refreshingSafely(SocialAccount $account, callable $refresh, ?callable $isFresh = null): SocialAccount
    {
        $isFresh ??= fn (SocialAccount $a) => $a->hasValidToken();
        $lock = \Illuminate\Support\Facades\Cache::lock('social:token-refresh:'.$account->id, 25);

        try {
            $lock->block(15);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Couldn't obtain the lock — return current DB state rather than
            // racing; the holder is refreshing right now.
            return $account->fresh() ?? $account;
        }

        try {
            $fresh = $account->fresh() ?? $account;
            if ($isFresh($fresh)) {
                return $fresh; // another worker already refreshed
            }

            return $refresh($fresh);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a refresh-endpoint failure definitively means the grant is dead
     * (user must reconnect) as opposed to a transient hiccup (rate limit,
     * upstream outage, network) that must NOT flip the account to
     * needs_reauth — bricking a healthy account over a 503 is exactly how
     * "why do I keep reconnecting" happens.
     */
    protected function isDefinitiveAuthFailure(int $status): bool
    {
        return in_array($status, [400, 401, 403], true);
    }

    /**
     * Standard OAuth2 authorization-code → token exchange against $tokenUrl.
     */
    protected function exchangeAuthorizationCode(string $tokenUrl, string $code, string $redirectUri, array $extra = []): OAuthResult
    {
        $response = Http::asForm()->post($tokenUrl, array_merge([
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ], $extra));

        if (! $response->successful()) {
            $this->fail('token exchange', $response->status(), $response->body());
        }

        return OAuthResult::fromArray($response->json());
    }

    /**
     * Standard OAuth2 refresh_token → token exchange.
     */
    protected function refreshWithToken(string $tokenUrl, string $refreshToken, array $extra = []): OAuthResult
    {
        $response = Http::asForm()->post($tokenUrl, array_merge([
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], $extra));

        if (! $response->successful()) {
            $this->fail('token refresh', $response->status(), $response->body());
        }

        return OAuthResult::fromArray($response->json());
    }

    /**
     * Create or update a SocialAccount for this user/platform/account id.
     */
    protected function storeAccount(User $user, array $attributes): SocialAccount
    {
        $platformAccountId = $attributes['platform_account_id'] ?? null;

        // A reconnect that omits refresh_token (LinkedIn, Google without a
        // fresh consent) must not wipe the stored one — that forces a reconnect
        // at the next expiry. Leave the column alone, like applyTokens() does.
        if (empty($attributes['refresh_token'])) {
            unset($attributes['refresh_token']);
        }

        $account = SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform,
                'platform_account_id' => $platformAccountId,
            ],
            array_merge([
                'status' => SocialAccount::STATUS_CONNECTED,
                'last_error' => null,
                'last_synced_at' => now(),
            ], $attributes)
        );

        return $account;
    }

    /**
     * Persist refreshed tokens onto an account.
     */
    protected function applyTokens(SocialAccount $account, OAuthResult $tokens): SocialAccount
    {
        $account->update([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?: $account->refresh_token,
            'token_expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scopes ?: $account->scopes,
            'status' => SocialAccount::STATUS_CONNECTED,
            'last_error' => null,
        ]);

        return $account->refresh();
    }

    /**
     * Fail a connect when the token response is missing a scope that publishing
     * needs, so the user re-authorizes immediately instead of discovering the
     * gap at publish time. Lenient on
     * purpose: an empty $granted (provider reported no scopes) passes, and
     * $required should only list what publishing really uses — optional extras
     * (X follows.write, LinkedIn email) must not be in it.
     *
     * Token responses carry scopes space-separated (X) or comma-separated
     * (LinkedIn); OAuthResult::fromArray already splits both.
     *
     * @param  array<int, string>  $granted  OAuthResult::$scopes
     * @param  array<int, string>  $required
     *
     * @throws SocialConnectException
     */
    protected function assertGrantedScopes(array $granted, array $required, string $hint = ''): void
    {
        if ($granted === []) {
            return;
        }

        $missing = array_values(array_diff($required, $granted));
        if ($missing === []) {
            return;
        }

        throw new SocialConnectException(sprintf(
            'Not enough permissions were granted for %s: missing %s. %s',
            $this->label(),
            implode(', ', $missing),
            $hint !== '' ? $hint : 'Reconnect and tick every box.'
        ));
    }

    /**
     * Publish-time 401 handler: when a provider call says the token
     * is dead, flag the account for reconnect right away instead of leaving a
     * failed target behind an account that still shows "Connected". Returns
     * true when the account was flagged, so the caller returns a failure with
     * the same message:
     *
     *     if ($this->failReauthIfUnauthorized($account, $response, 'publish')) {
     *         return PublishResult::failure($account->fresh()->last_error);
     *     }
     *
     * $alsoWhen lets a provider treat other bodies as revocation (e.g. a 403
     * whose body says the token was revoked, Bluesky's ExpiredToken error).
     *
     * @param  (callable(Response): bool)|null  $alsoWhen
     */
    protected function failReauthIfUnauthorized(SocialAccount $account, Response $response, string $action, ?callable $alsoWhen = null): bool
    {
        if ($response->status() !== 401 && ! ($alsoWhen !== null && $alsoWhen($response))) {
            return false;
        }

        Log::warning("[{$this->platform}] {$action} rejected the token; account needs reconnect", [
            'account_id' => $account->id,
            'status' => $response->status(),
            'body' => $this->bodyExcerpt($response),
        ]);

        $account->markNeedsReauth($this->label().' authorization expired or was revoked — reconnect the account.');

        return true;
    }

    /**
     * Whether an HTTP status means "try again later" rather than "this post is
     * wrong": rate limits and upstream errors.
     */
    public static function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Ask the queue to retry on a rate limit or upstream error. Call
     * it on a failed response before building a PublishResult::failure; it is
     * a no-op for every other status. The job must let the exception through
     * ahead of its Throwable catch, the way PublishToXJob does:
     *
     *     } catch (TransientPublishException $e) {
     *         throw $e; // queue retries with $tries / $backoff
     *     } catch (\Throwable $e) {
     *         ...mark the target failed...
     *     }
     *
     * @throws TransientPublishException
     */
    protected function throwIfTransient(Response $response, string $action): void
    {
        $status = $response->status();
        if (! static::isTransientStatus($status)) {
            return;
        }

        Log::warning("[{$this->platform}] {$action} failed transiently; queue will retry", [
            'status' => $status,
            'body' => $this->bodyExcerpt($response),
        ]);

        throw new TransientPublishException($status === 429
            ? "{$this->label()} rate limit hit during {$action} — retrying automatically."
            : "{$this->label()} returned a temporary error during {$action} (HTTP {$status}) — retrying automatically.");
    }

    /**
     * Short, token-free response body for logs.
     */
    protected function bodyExcerpt(Response $response, int $max = 300): string
    {
        $body = preg_replace(
            ['/("?(?:access_token|refresh_token|accessJwt|refreshJwt|code)"?\s*[:=]\s*"?)[^"&\s,}]+/i', '/Bearer\s+\S+/i'],
            ['$1[redacted]', 'Bearer [redacted]'],
            (string) $response->body()
        ) ?? '';

        return mb_strimwidth($body, 0, $max, '…');
    }

    /**
     * Extract media URLs from a post by type.
     *
     * @return array<int, array{url:string,type:string,mime:?string,alt:?string}>
     */
    protected function mediaItems(SocialPost $post, ?string $onlyType = null): array
    {
        $items = collect($post->media ?? [])
            ->filter(fn ($m) => ! empty($m['url']))
            ->map(fn ($m) => [
                'url' => $m['url'],
                'type' => $m['type'] ?? 'image',
                'mime' => $m['mime'] ?? null,
                'alt' => $m['alt'] ?? null,
            ]);

        if ($onlyType) {
            $items = $items->where('type', $onlyType);
        }

        return $items->values()->all();
    }

    protected function firstImageUrl(SocialPost $post): ?string
    {
        return $this->mediaItems($post, 'image')[0]['url'] ?? null;
    }

    protected function firstVideoUrl(SocialPost $post): ?string
    {
        return $this->mediaItems($post, 'video')[0]['url'] ?? null;
    }

    /**
     * Download one of a post's media files to re-upload it to the platform.
     * Media URLs come from users (create_post accepts any URL), so this goes
     * through SafeHttp: public addresses only, capped size. Null when refused
     * or unreachable; the reason is logged.
     *
     * @return array{body: string, content_type: ?string, url: string}|null
     */
    protected function fetchMedia(string $url, string $type = 'image'): ?array
    {
        // Caps match direct uploads (PostMediaDirectUploadController), with room for upload_media images.
        $maxBytes = $type === 'video' ? 512 * 1024 * 1024 : 20 * 1024 * 1024;

        try {
            return SafeHttp::get($url, $maxBytes, $type === 'video' ? 600 : 60);
        } catch (UnsafeUrlException $e) {
            Log::warning("[{$this->platform}] media fetch refused", ['url' => $url, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Standard buildscope query helper.
     */
    protected function scopeString(string $separator = ' '): string
    {
        return implode($separator, (array) $this->config('scopes', []));
    }

    protected function fail(string $action, int $status, string $body): void
    {
        Log::error("Social provider {$this->platform} {$action} failed", [
            'status' => $status,
            'body' => $body,
        ]);

        throw new RuntimeException(ucfirst($this->platform)." {$action} failed (HTTP {$status}): ".$body);
    }
}
