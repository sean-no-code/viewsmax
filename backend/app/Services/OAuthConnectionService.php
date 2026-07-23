<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\SocialAccount;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OAuthConnectionService
{
    public const PROVIDERS = ['youtube', 'tiktok', 'instagram'];

    /**
     * Exchange an OAuth authorization code for the given provider, fetch the
     * account profile, and upsert a connections row for the user.
     */
    public function exchange(User $user, string $provider, string $code, string $redirectUri, ?string $codeVerifier = null): Connection
    {
        $profile = match ($provider) {
            'tiktok' => $this->exchangeTikTok($code, $redirectUri, $codeVerifier),
            'instagram' => $this->exchangeInstagram($code, $redirectUri),
            default => throw new Exception("Unsupported provider: {$provider}"),
        };

        $connection = Connection::updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
            [
                'account_name' => $profile['account_name'],
                'account_id' => $profile['account_id'],
                'avatar_url' => $profile['avatar_url'] ?? null,
                'access_token' => $profile['access_token'],
                'refresh_token' => $profile['refresh_token'] ?? null,
                'token_expires_at' => $profile['token_expires_at'] ?? null,
            ]
        );

        // Multi-account bridge: TikTok publishing now reads the `social_accounts`
        // store. Mirror the connection there (keyed on the TikTok account id, so a
        // different account ADDS a row) until the connect flow moves fully onto it.
        if ($provider === 'tiktok' && ! empty($profile['account_id'])) {
            SocialAccount::updateOrCreate(
                ['user_id' => $user->id, 'platform' => 'tiktok', 'platform_account_id' => $profile['account_id']],
                [
                    'name' => $profile['account_name'] ?? null,
                    'avatar_url' => $profile['avatar_url'] ?? null,
                    'access_token' => $profile['access_token'],
                    'refresh_token' => $profile['refresh_token'] ?? null,
                    'token_expires_at' => $profile['token_expires_at'] ?? null,
                    'status' => SocialAccount::STATUS_CONNECTED,
                    'scopes' => config('social.platforms.tiktok.scopes', []),
                    'last_synced_at' => now(),
                ]
            );
        }

        return $connection;
    }

    /**
     * Return a valid access token for the connection, refreshing it first if the
     * stored token is missing or about to expire. Persists the refreshed token.
     */
    public function freshAccessToken(Connection $connection): string
    {
        if ($connection->tokenIsExpired()) {
            $this->refresh($connection);
        }

        if (! $connection->access_token) {
            throw new Exception('No access token available for '.$connection->provider.' connection.');
        }

        return $connection->access_token;
    }

    /**
     * Refresh the access token for a connection using its stored refresh token.
     */
    public function refresh(Connection $connection): Connection
    {
        $refreshed = match ($connection->provider) {
            'tiktok' => $this->refreshTikTok($connection),
            default => throw new Exception("Token refresh not supported for provider: {$connection->provider}"),
        };

        $connection->forceFill([
            'access_token' => $refreshed['access_token'],
            'refresh_token' => $refreshed['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => $refreshed['token_expires_at'] ?? null,
        ])->save();

        return $connection;
    }

    /**
     * Re-fetch the connected account's display name + avatar and store them.
     * TikTok (and Instagram) avatar URLs are short-lived SIGNED urls — the one
     * captured at connect time expires within days, after which the UI falls
     * back to a brand glyph. Callers throttle this (see ConnectionController)
     * and must treat failure as non-fatal.
     */
    public function refreshProfile(Connection $connection): Connection
    {
        if ($connection->provider !== 'tiktok') {
            return $connection; // only TikTok has a refreshable profile here
        }

        $accessToken = $this->freshAccessToken($connection);

        $userResponse = Http::withToken($accessToken)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,display_name,avatar_url',
            ]);

        if (! $userResponse->successful()) {
            throw new Exception('Failed to refresh TikTok profile: '.$userResponse->body());
        }

        $info = $userResponse->json('data.user', []);

        $connection->forceFill(array_filter([
            'account_name' => $info['display_name'] ?? null,
            'avatar_url' => $info['avatar_url'] ?? null,
        ]))->save();

        // Touch even when nothing changed so the caller's staleness throttle
        // doesn't retry on every request.
        $connection->touch();

        return $connection;
    }

    /**
     * TikTok refresh token grant.
     * https://open.tiktokapis.com/v2/oauth/token/ (grant_type=refresh_token)
     */
    private function refreshTikTok(Connection $connection): array
    {
        $clientKey = config('services.tiktok.client_key');
        $clientSecret = config('services.tiktok.client_secret');

        if (! $clientKey || ! $clientSecret) {
            throw new Exception('TikTok OAuth is not configured');
        }

        if (! $connection->refresh_token) {
            throw new Exception('TikTok connection has no refresh token; please reconnect the account.');
        }

        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $clientKey,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        if (! $response->successful()) {
            Log::error('TikTok token refresh failed', ['response' => $response->body()]);
            throw new Exception('Failed to refresh TikTok access token; please reconnect the account.');
        }

        $token = $response->json();
        $accessToken = $token['access_token'] ?? null;
        if (! $accessToken) {
            throw new Exception('TikTok did not return an access token on refresh');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null,
        ];
    }

    /**
     * TikTok: exchange code, then fetch basic user info.
     * Token: https://open.tiktokapis.com/v2/oauth/token/  (scopes user.info.basic,video.list)
     */
    private function exchangeTikTok(string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        $clientKey = config('services.tiktok.client_key');
        $clientSecret = config('services.tiktok.client_secret');

        if (! $clientKey || ! $clientSecret) {
            throw new Exception('TikTok OAuth is not configured');
        }

        $tokenResponse = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', array_filter([
            'client_key' => $clientKey,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            // PKCE: TikTok requires the verifier matching the challenge sent at authorize.
            'code_verifier' => $codeVerifier,
        ]));

        if (! $tokenResponse->successful()) {
            Log::error('TikTok token exchange failed', ['response' => $tokenResponse->body()]);
            throw new Exception('Failed to exchange TikTok authorization code');
        }

        $token = $tokenResponse->json();
        $accessToken = $token['access_token'] ?? null;
        if (! $accessToken) {
            throw new Exception('TikTok did not return an access token');
        }

        $userResponse = Http::withToken($accessToken)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,display_name,avatar_url',
            ]);

        if (! $userResponse->successful()) {
            Log::error('TikTok user info failed', ['response' => $userResponse->body()]);
            throw new Exception('Failed to fetch TikTok account profile');
        }

        $info = $userResponse->json('data.user', []);

        return [
            'account_id' => $info['open_id'] ?? ($token['open_id'] ?? ''),
            'account_name' => $info['display_name'] ?? 'TikTok Account',
            'avatar_url' => $info['avatar_url'] ?? null,
            'access_token' => $accessToken,
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null,
        ];
    }

    /**
     * Instagram via Meta/Facebook Login: exchange code for a token, then resolve
     * the linked Instagram business account (falling back to the basic /me profile).
     * Scopes: instagram_basic,pages_show_list
     */
    private function exchangeInstagram(string $code, string $redirectUri): array
    {
        $clientId = config('services.meta.client_id');
        $clientSecret = config('services.meta.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new Exception('Instagram OAuth is not configured');
        }

        $tokenResponse = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $tokenResponse->successful()) {
            Log::error('Instagram token exchange failed', ['response' => $tokenResponse->body()]);
            throw new Exception('Failed to exchange Instagram authorization code');
        }

        $token = $tokenResponse->json();
        $accessToken = $token['access_token'] ?? null;
        if (! $accessToken) {
            throw new Exception('Instagram did not return an access token');
        }

        $expiresAt = isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null;

        // Try to resolve the IG business account linked to one of the user's pages.
        $pagesResponse = Http::withToken($accessToken)
            ->get('https://graph.facebook.com/v19.0/me/accounts', [
                'fields' => 'instagram_business_account{id,username,profile_picture_url}',
            ]);

        if ($pagesResponse->successful()) {
            foreach ($pagesResponse->json('data', []) as $page) {
                $ig = $page['instagram_business_account'] ?? null;
                if ($ig && ! empty($ig['id'])) {
                    return [
                        'account_id' => $ig['id'],
                        'account_name' => $ig['username'] ?? 'Instagram Account',
                        'avatar_url' => $ig['profile_picture_url'] ?? null,
                        'access_token' => $accessToken,
                        'refresh_token' => null,
                        'token_expires_at' => $expiresAt,
                    ];
                }
            }
        }

        // Fallback to the basic Facebook profile if no IG business account is linked.
        $me = Http::withToken($accessToken)
            ->get('https://graph.facebook.com/v19.0/me', ['fields' => 'id,name,picture'])
            ->json();

        return [
            'account_id' => $me['id'] ?? '',
            'account_name' => $me['name'] ?? 'Instagram Account',
            'avatar_url' => $me['picture']['data']['url'] ?? null,
            'access_token' => $accessToken,
            'refresh_token' => null,
            'token_expires_at' => $expiresAt,
        ];
    }
}
