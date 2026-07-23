<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SocialProviderInterface;
use App\Services\Social\Data\OAuthResult;
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
