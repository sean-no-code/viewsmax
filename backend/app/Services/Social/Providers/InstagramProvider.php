<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Instagram publishing via the "Instagram API with Instagram Login".
 *
 * This connects directly to an Instagram professional (Business/Creator)
 * account — no Facebook Page required. The flow mirrors Threads: authorize on
 * instagram.com, exchange for a short-lived token, upgrade to a long-lived
 * (~60 day) token, then publish via the graph.instagram.com create-container →
 * publish two-step (polling video containers until processed).
 */
class InstagramProvider extends AbstractSocialProvider implements SupportsComments
{
    protected string $platform = 'instagram';

    protected function graphBase(): string
    {
        return 'https://graph.instagram.com/'.$this->config('graph_version', 'v21.0');
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopeString(','),
            'state' => $state,
            // Force the login screen so a user can connect a SECOND Instagram
            // account. Without this, Instagram silently reuses the account
            // already logged in on the device and returns the same user_id, so
            // storeAccount() just updates the existing row (one account per user)
            // instead of adding the new one.
            'force_reauth' => 'true',
        ]);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        Log::info('[Instagram] connect start', ['user_id' => $user->id]);

        // Short-lived token + the IG user id. Instagram strips a trailing
        // "#_" fragment some redirects append to the code.
        $short = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => preg_replace('/#_$/', '', $code),
        ]);

        if (! $short->successful()) {
            Log::error('[Instagram] token exchange failed', ['http_status' => $short->status(), 'body' => $short->body()]);
            $this->fail('token exchange', $short->status(), $short->body());
        }

        $shortToken = $short->json('access_token');
        $userId = $short->json('user_id');
        Log::info('[Instagram] short-lived token obtained', ['has_token' => ! empty($shortToken), 'user_id' => $userId]);

        // Upgrade to a long-lived (~60 day) token. This must NOT fall back to
        // the 1-hour short-lived token: storing it (with no expiry) made a
        // "successful" reconnect die within the hour at publish time (OAuth 190),
        // looping the user through reconnect → expired → reconnect.
        $long = Http::get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->clientSecret(),
            'access_token' => $shortToken,
        ]);

        if (! $long->successful() || ! $long->json('access_token')) {
            Log::error('[Instagram] long-lived token exchange failed', ['http_status' => $long->status(), 'body' => $long->body()]);
            $this->fail('long-lived token exchange', $long->status(), $long->body());
        }

        $accessToken = $long->json('access_token');
        // Graph always returns expires_in (~60 days); guard with a real expiry
        // anyway — a null expiry reads as "valid forever" and is never refreshed.
        $expiresIn = (int) ($long->json('expires_in') ?: 5184000);

        // Fetch the profile for display.
        $profile = Http::withToken($accessToken)
            ->get($this->graphBase().'/me', [
                'fields' => 'user_id,username,name,profile_picture_url,account_type',
            ])
            ->json();

        Log::info('[Instagram] profile fetched', [
            'username' => $profile['username'] ?? null,
            'account_type' => $profile['account_type'] ?? null,
        ]);

        $igUserId = $profile['user_id'] ?? $userId;

        $account = $this->storeAccount($user, [
            'platform_account_id' => $igUserId,
            'name' => $profile['name'] ?? ($profile['username'] ?? null),
            'username' => $profile['username'] ?? null,
            'avatar_url' => $profile['profile_picture_url'] ?? null,
            'profile_url' => isset($profile['username']) ? 'https://instagram.com/'.$profile['username'] : null,
            'access_token' => $accessToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'scopes' => $this->config('scopes', []),
            'metadata' => ['ig_user_id' => $igUserId, 'account_type' => $profile['account_type'] ?? null],
        ]);

        Log::info('[Instagram] connect finished', ['user_id' => $user->id, 'ig_user_id' => $igUserId]);

        return collect([$account]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        // Refresh proactively while the token is still valid (IG only allows
        // refreshing unexpired tokens that are ≥24h old) — waiting until it
        // has expired makes the refresh itself fail and strands the account.
        $expiresSoon = $account->token_expires_at !== null
            && $account->token_expires_at->lt(now()->addDays(7));
        if ($account->hasValidToken() && ! $expiresSoon) {
            return $account;
        }

        // Long-lived Instagram tokens are refreshed (not re-exchanged).
        $response = Http::get('https://graph.instagram.com/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $account->access_token,
        ]);

        if ($response->successful()) {
            return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
        }

        // A failed proactive refresh isn't fatal while the token still works —
        // keep publishing with it and retry the refresh next time.
        if ($account->hasValidToken()) {
            Log::warning('[Instagram] proactive token refresh failed; token still valid', [
                'account_id' => $account->id,
                'body' => $response->body(),
            ]);

            return $account;
        }

        $account->markNeedsReauth('Instagram token refresh failed.');

        return $account;
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $igUserId = $account->platform_account_id;
        $token = $account->access_token;
        $caption = (string) $post->content;
        $image = $this->firstImageUrl($post);
        $video = $this->firstVideoUrl($post);

        $log = ['account_id' => $account->id, 'ig_user_id' => $igUserId, 'media' => $video ? 'REELS' : ($image ? 'IMAGE' : 'none')];
        Log::info('[Instagram] publish start', $log + ['caption_length' => mb_strlen($caption)]);

        if (! $image && ! $video) {
            Log::warning('[Instagram] no media supplied', $log);

            return PublishResult::failure('Instagram requires at least one image or video to publish.');
        }

        // 1. Create a media container. A Reel may carry a custom cover image
        // (public URL); Instagram ignores cover_url for non-video posts.
        $containerParams = array_filter([
            'caption' => $caption,
            'image_url' => $video ? null : $image,
            'video_url' => $video,
            'media_type' => $video ? 'REELS' : null,
            'cover_url' => $video ? ($post->cover_url ?? null) : null,
            'access_token' => $token,
        ]);

        $create = Http::post($this->graphBase()."/{$igUserId}/media", $containerParams);
        Log::info('[Instagram] create container response', $log + ['http_status' => $create->status(), 'body' => $create->body()]);

        if (! $create->successful()) {
            if ($reauth = $this->reauthFailure($account, $create->json(), $log, $create->body())) {
                return $reauth;
            }

            Log::error('[Instagram] container creation failed', $log + ['response' => $create->body()]);

            return PublishResult::failure('Instagram container creation failed: '.$create->body(), $create->json() ?? []);
        }

        $creationId = $create->json('id');

        // 2. Video containers must finish processing before publishing.
        if ($video) {
            $status = $this->waitForContainer($creationId, $token);
            Log::info('[Instagram] video container processed', $log + ['creation_id' => $creationId, 'status' => $status]);
            if ($status !== 'FINISHED') {
                Log::error('[Instagram] video not publishable', $log + ['creation_id' => $creationId, 'status' => $status]);

                $reason = $status === 'ERROR'
                    ? 'Instagram could not process the video. Make sure the media URL is publicly reachable over HTTPS (not an ngrok-free/expired link) and meets Reel specs (MP4/MOV, H.264/AAC, 3s–15min).'
                    : 'Instagram video processing did not complete in time.';

                return PublishResult::failure($reason);
            }
        }

        // 3. Publish the container.
        $publish = Http::post($this->graphBase()."/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $token,
        ]);

        Log::info('[Instagram] media_publish response', $log + [
            'creation_id' => $creationId,
            'http_status' => $publish->status(),
            'body' => $publish->body(),
        ]);

        if (! $publish->successful()) {
            if ($reauth = $this->reauthFailure($account, $publish->json(), $log, $publish->body())) {
                return $reauth;
            }

            Log::error('[Instagram] publish failed', $log + ['creation_id' => $creationId, 'response' => $publish->body()]);

            return PublishResult::failure('Instagram publish failed: '.$publish->body(), $publish->json() ?? []);
        }

        $mediaId = $publish->json('id');
        Log::info('[Instagram] publish success', $log + ['media_id' => $mediaId]);

        return PublishResult::success($mediaId, null, $publish->json());
    }

    /**
     * When a Graph response is an expired/invalid-token error (OAuthException,
     * code 190), flag the account for reconnection and return an actionable
     * failure. A token can be revoked before its recorded expiry — password
     * change, app de-authorized, session invalidated — so ensureFreshToken's
     * time-based check never catches it and the only fix is a reconnect.
     * Returns null when the error is something else, so the caller falls through
     * to its normal handling.
     */
    protected function reauthFailure(SocialAccount $account, ?array $body, array $log, ?string $rawBody): ?PublishResult
    {
        if (! $this->isExpiredTokenError($body)) {
            return null;
        }

        $account->markNeedsReauth('Instagram session expired — reconnect the account.');
        Log::error('[Instagram] access token expired — flagged for reconnect', $log + ['response' => $rawBody]);

        return PublishResult::failure(
            'Instagram authorization expired — reconnect the account in Settings, then retry the post.',
            $body ?? []
        );
    }

    /** A Meta Graph "session expired / invalid access token" error (code 190). */
    protected function isExpiredTokenError(?array $body): bool
    {
        $error = $body['error'] ?? null;
        if (! is_array($error)) {
            return false;
        }

        return (int) ($error['code'] ?? 0) === 190
            || ($error['type'] ?? null) === 'OAuthException';
    }

    /**
     * Poll the container until it reaches a terminal state. Returns the final
     * status_code: FINISHED (ready), ERROR (rejected), or IN_PROGRESS (timed out).
     */
    protected function waitForContainer(string $creationId, string $token, int $maxAttempts = 20): string
    {
        $status = 'IN_PROGRESS';

        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = (string) Http::get($this->graphBase()."/{$creationId}", [
                'fields' => 'status_code',
                'access_token' => $token,
            ])->json('status_code');

            if ($status === 'FINISHED' || $status === 'ERROR') {
                return $status;
            }

            sleep(5);
        }

        return $status;
    }

    /**
     * Read a published media's lifetime view count via the Insights API.
     * Reels/video expose `views`; static images don't, so we fall back to
     * `reach`. Returns null when neither metric is available (or the call fails)
     * — the caller keeps the last known count rather than zeroing it out.
     *
     * Requires the `instagram_business_manage_insights` scope (gated behind the
     * reach feature flag — see config/social.php).
     */
    public function fetchMediaViews(SocialAccount $account, string $mediaId): ?int
    {
        $account = $this->ensureFreshToken($account);

        foreach (['views', 'reach'] as $metric) {
            $response = Http::get($this->graphBase()."/{$mediaId}/insights", [
                'metric' => $metric,
                'access_token' => $account->access_token,
            ]);

            if (! $response->successful()) {
                continue; // try the next metric (image posts lack "views")
            }

            $entry = $response->json('data.0');
            // Classic insights use values[0].value; some metrics use total_value.value.
            $value = data_get($entry, 'values.0.value', data_get($entry, 'total_value.value'));
            if ($value !== null) {
                return (int) $value;
            }
        }

        Log::warning('[Instagram] media insights returned no view metric', ['media_id' => $mediaId]);

        return null;
    }

    /**
     * Post a comment on a published Instagram media object. IG comments are
     * flat — $previousRemoteId is ignored.
     */
    public function comment(SocialAccount $account, string $remotePostId, string $body, ?string $previousRemoteId = null): CommentResult
    {
        $response = Http::post($this->graphBase()."/{$remotePostId}/comments", [
            'message' => $body,
            'access_token' => $account->access_token,
        ]);

        if (! $response->successful()) {
            return CommentResult::failure('Instagram comment failed: '.$response->body(), $response->json() ?? []);
        }

        return CommentResult::success((string) $response->json('id'), $response->json() ?? []);
    }
}
