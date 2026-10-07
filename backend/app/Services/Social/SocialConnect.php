<?php

namespace App\Services\Social;

use App\Exceptions\AccountAlreadyConnectedException;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The social OAuth connect flow, shared by the SPA's JSON endpoints
 * (SocialAccountController) and the API host's browser pages used mid
 * agent-OAuth (/register/setup). State lives in the cache for 15 minutes and
 * carries the redirect URI, the X PKCE verifier and the "follow us" choice.
 */
class SocialConnect
{
    public function __construct(protected SocialProviderManager $manager, protected FollowUs $followUs) {}

    /**
     * Build the provider authorization URL and stash the matching state.
     *
     * @return array{authorization_url: string, state: string}
     */
    public function authorizationUrl(User $user, string $platform, string $redirectUri, bool $followUs = false): array
    {
        $provider = $this->manager->for($platform);
        $state = Str::random(40);
        $options = [];
        $stateData = [
            'user_id' => $user->id,
            'platform' => $platform,
            'redirect_uri' => $redirectUri,
        ];

        // X requires PKCE: keep the verifier server-side, send the challenge.
        if ($platform === 'x') {
            $verifier = Str::random(64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $options['code_challenge'] = $challenge;
            $stateData['code_verifier'] = $verifier;
        }

        // Ask for the follow permission now and remember the choice for
        // complete(). Skipped when we have no account to follow on this platform.
        if ($followUs && $this->followUs->handle($platform)) {
            $options['follow_us'] = true;
            $stateData['follow_us'] = true;
        }

        Cache::put($this->stateKey($state), $stateData, now()->addMinutes(15));

        return [
            'authorization_url' => $provider->getAuthorizationUrl($redirectUri, $state, $options),
            'state' => $state,
        ];
    }

    /**
     * Exchange the provider's code for tokens and store the account(s).
     *
     * @return Collection<int, \App\Models\SocialAccount>
     *
     * @throws SocialConnectException with a message safe to show the user
     */
    public function complete(User $user, string $platform, string $code, ?string $state, ?string $redirectUri = null): Collection
    {
        $redirectUri ??= config('social.default_redirect_uri');
        $options = [];
        $followUs = false;

        if (! empty($state)) {
            $stateData = Cache::pull($this->stateKey($state));

            if (! $stateData || ($stateData['user_id'] ?? null) !== $user->id || ($stateData['platform'] ?? null) !== $platform) {
                throw new SocialConnectException('Invalid or expired OAuth state.');
            }

            $redirectUri = $stateData['redirect_uri'] ?? $redirectUri;
            if (! empty($stateData['code_verifier'])) {
                $options['code_verifier'] = $stateData['code_verifier'];
            }
            $followUs = ! empty($stateData['follow_us']);
        }

        try {
            $accounts = $this->manager->for($platform)->connectFromCode($user, $code, $redirectUri, $options);
        } catch (AccountAlreadyConnectedException $e) {
            throw new SocialConnectException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new SocialConnectException('Failed to connect '.$platform.': '.$e->getMessage(), 0, $e);
        }

        // OAuth can succeed yet yield no usable account (e.g. an Instagram login
        // with no Business account linked to a Page). Report that as a failure.
        if ($accounts->isEmpty()) {
            throw new SocialConnectException($this->noAccountsMessage($platform));
        }

        if ($followUs) {
            $this->followUs->followFrom($accounts);
        }

        return $accounts;
    }

    /**
     * Connect a non-OAuth platform (e.g. Bluesky) with direct credentials.
     *
     * @return Collection<int, \App\Models\SocialAccount>
     *
     * @throws SocialConnectException
     */
    public function connectWithCredentials(User $user, string $platform, array $data, bool $followUs = false): Collection
    {
        try {
            $accounts = $this->manager->for($platform)->connectWithCredentials($user, $data);
        } catch (AccountAlreadyConnectedException $e) {
            throw new SocialConnectException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new SocialConnectException('Failed to connect '.$platform.': '.$e->getMessage(), 0, $e);
        }

        if ($followUs) {
            $this->followUs->followFrom($accounts);
        }

        return $accounts;
    }

    /** Human-friendly explanation when OAuth succeeds but no account is usable. */
    public function noAccountsMessage(string $platform): string
    {
        return match ($platform) {
            'instagram' => 'Could not connect Instagram. Make sure you are logging in with an Instagram Business or Creator account.',
            'facebook' => 'No Facebook Page was found. You need to manage at least one Facebook Page and grant access to it.',
            default => 'No '.ucfirst($platform).' account could be connected. Check that you granted the requested permissions.',
        };
    }

    protected function stateKey(string $state): string
    {
        return 'social_oauth_state:'.$state;
    }
}
