<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Services\Social\Data\OAuthResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared Google OAuth 2.0 behaviour for YouTube and Google Business Profile.
 */
abstract class GoogleOAuthProvider extends AbstractSocialProvider
{
    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** A Workspace admin's reauthentication policy killed the grant; reconnecting alone won't stop it recurring. */
    public const WORKSPACE_POLICY_MESSAGE = 'Your Google Workspace session policy revoked access; ask your admin to exempt this app from the reauthentication policy, then reconnect.';

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
        // Refresh a few minutes early so a token can't expire mid-request.
        $fresh = fn (SocialAccount $a) => $a->hasValidToken()
            && ($a->token_expires_at === null || $a->token_expires_at->isAfter(now()->addMinutes(5)));
        if ($fresh($account) || empty($account->refresh_token)) {
            return $account;
        }

        return $this->refreshingSafely($account, function (SocialAccount $account) {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful()) {
                // Only a definitive rejection (invalid_grant: revoked / expired
                // refresh token) means reconnect; a 429/5xx must not brick the
                // account (the daily refresh retries tomorrow).
                if ($this->isDefinitiveAuthFailure($response->status())) {
                    $account->markNeedsReauth($this->refreshRejectedMessage($response));
                } else {
                    Log::warning('Google token refresh failed transiently', [
                        'account_id' => $account->id, 'platform' => $this->platform, 'status' => $response->status(),
                    ]);
                }

                return $account;
            }

            return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
        }, $fresh);
    }

    /**
     * Why Google rejected the refresh. `invalid_rapt` is a Workspace
     * session-control policy, not a revoked grant: the user has to involve
     * their admin, so say so.
     */
    private function refreshRejectedMessage(Response $response): string
    {
        $text = $response->json('error_subtype').' '.$response->json('error_description');

        return str_contains($text, 'invalid_rapt')
            ? self::WORKSPACE_POLICY_MESSAGE
            : 'Google token refresh was rejected — reconnect the account.';
    }

    protected function exchangeGoogleCode(string $code, string $redirectUri): OAuthResult
    {
        return $this->exchangeAuthorizationCode(self::TOKEN_URL, $code, $redirectUri);
    }
}
