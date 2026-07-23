<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Services\Social\Data\OAuthResult;
use Illuminate\Support\Facades\Http;

/**
 * Shared Google OAuth 2.0 behaviour for YouTube and Google My Business.
 */
abstract class GoogleOAuthProvider extends AbstractSocialProvider
{
    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopeString(' '),
            'state' => $state,
            // offline + consent guarantees a refresh_token on first grant.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        if ($account->hasValidToken() || empty($account->refresh_token)) {
            return $account;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $account->markNeedsReauth('Google token refresh failed.');

            return $account;
        }

        return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
    }

    protected function exchangeGoogleCode(string $code, string $redirectUri): OAuthResult
    {
        return $this->exchangeAuthorizationCode(self::TOKEN_URL, $code, $redirectUri);
    }
}
