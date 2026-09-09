<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Automations\AutomationLog;
use App\Services\Social\Contracts\SupportsAutomations;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\MessageResult;
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
class InstagramProvider extends AbstractSocialProvider implements SupportsComments, SupportsAutomations
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
        $grantedScopes = array_values(array_filter((array) ($short->json('permissions') ?? []), 'is_string'));
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
            // Prefer what Meta says it actually granted (the token response
            // carries `permissions`); fall back to what we asked for.
            'scopes' => $grantedScopes ?: $this->config('scopes', []),
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
        $stillFresh = fn (SocialAccount $a) => $a->hasValidToken()
            && ! ($a->token_expires_at !== null && $a->token_expires_at->lt(now()->addDays(7)));
        if ($stillFresh($account)) {
            return $account;
        }

        return $this->refreshingSafely($account, isFresh: $stillFresh, refresh: function (SocialAccount $account) {
            // Long-lived Instagram tokens are refreshed (not re-exchanged).
            $response = Http::get('https://graph.instagram.com/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $account->access_token,
            ]);

            if ($response->successful()) {
                return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
            }

            // A failed proactive refresh isn't fatal while the token still
            // works; and a transient (5xx/429) failure on an expired token
            // must not brick the account either — only a definitive rejection
            // of a dead token forces a reconnect.
            if ($this->isDefinitiveAuthFailure($response->status()) && ! $account->hasValidToken()) {
                $account->markNeedsReauth('Instagram token expired — reconnect the account.');
            } else {
                Log::warning('[Instagram] token refresh failed transiently', [
                    'account_id' => $account->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return $account;
        });
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
     * Follower count via the IG user node. Gated behind the reach/insights flag
     * (same scope requirement as media insights); null when off or on failure.
     */
    public function fetchFollowerCount(SocialAccount $account): ?int
    {
        if (! $this->config('reach_enabled')) {
            return null;
        }

        $account = $this->ensureFreshToken($account);

        $response = Http::get($this->graphBase().'/me', [
            'fields' => 'followers_count',
            'access_token' => $account->access_token,
        ]);

        if (! $response->successful()) {
            Log::warning('[Instagram] follower lookup failed', [
                'account_id' => $account->id, 'status' => $response->status(),
            ]);

            return null;
        }

        $count = $response->json('followers_count');

        return $count === null ? null : (int) $count;
    }

    /**
     * Per-media engagement. IG insights on this integration expose views/reach
     * only (no likes/comments), so those stay 0. Gated behind the reach flag.
     */
    public function fetchPostMetrics(SocialAccount $account, array $remotePostIds): array
    {
        if (! $this->config('reach_enabled') || empty($remotePostIds)) {
            return [];
        }

        $out = [];
        foreach ($remotePostIds as $id) {
            $views = $this->fetchMediaViews($account, (string) $id);
            if ($views !== null) {
                $out[(string) $id] = ['likes' => 0, 'comments' => 0, 'shares' => 0, 'views' => $views];
            }
        }

        return $out;
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

    /*
    |--------------------------------------------------------------------------
    | Automations (comments / story replies / DMs)
    |--------------------------------------------------------------------------
    | All calls log through AutomationLog (the dedicated automations channel)
    | and flag the account for reconnect on an OAuthException 190.
    */

    public function subscribeWebhooks(SocialAccount $account, array $fields = ['comments', 'messages']): bool
    {
        $account = $this->ensureFreshToken($account);
        $ctx = AutomationLog::context(account: $account) + ['fields' => $fields];

        $response = Http::post($this->graphBase()."/{$account->platform_account_id}/subscribed_apps", [
            'subscribed_fields' => implode(',', $fields),
            'access_token' => $account->access_token,
        ]);

        if (! $response->successful() || ! $response->json('success')) {
            $this->flagIfExpired($account, $response->json());
            AutomationLog::error('webhook subscription refused', $ctx + ['http_status' => $response->status(), 'body' => $response->body()]);

            return false;
        }

        $account->forceFill([
            'webhook_subscribed_at' => now(),
            'metadata' => array_merge($account->metadata ?? [], ['webhook_fields' => array_values($fields)]),
        ])->save();

        AutomationLog::info('webhook subscription ok', $ctx);

        return true;
    }

    public function unsubscribeWebhooks(SocialAccount $account): bool
    {
        $response = Http::delete($this->graphBase()."/{$account->platform_account_id}/subscribed_apps", [
            'access_token' => $account->access_token,
        ]);

        AutomationLog::info('webhook unsubscribe', AutomationLog::context(account: $account) + ['http_status' => $response->status()]);

        if ($response->successful()) {
            $account->forceFill(['webhook_subscribed_at' => null])->save();
        }

        return $response->successful();
    }

    /**
     * The account's own posts/reels for the automation post picker.
     *
     * @return array{items: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    public function listMedia(SocialAccount $account, ?string $after = null, int $limit = 24): array
    {
        $account = $this->ensureFreshToken($account);
        $ctx = AutomationLog::context(account: $account) + ['after' => $after, 'limit' => $limit];

        $response = Http::get($this->graphBase().'/me/media', array_filter([
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp',
            'limit' => max(1, min($limit, 50)),
            'after' => $after,
            'access_token' => $account->access_token,
        ]));

        if (! $response->successful()) {
            $this->flagIfExpired($account, $response->json());
            AutomationLog::warning('media list failed', $ctx + ['http_status' => $response->status(), 'body' => $response->body()]);
            $this->fail('media list', $response->status(), $response->body());
        }

        $items = collect($response->json('data') ?? [])->map(fn (array $m) => [
            'id' => (string) ($m['id'] ?? ''),
            'caption' => isset($m['caption']) ? mb_substr((string) $m['caption'], 0, 120) : null,
            'media_type' => $m['media_type'] ?? null,
            'media_product_type' => $m['media_product_type'] ?? null,
            // Videos/reels carry thumbnail_url; images only media_url.
            'thumbnail_url' => $m['thumbnail_url'] ?? $m['media_url'] ?? null,
            'permalink' => $m['permalink'] ?? null,
            'timestamp' => $m['timestamp'] ?? null,
        ])->filter(fn (array $m) => $m['id'] !== '')->values()->all();

        AutomationLog::debug('media list fetched', $ctx + ['count' => count($items)]);

        return [
            'items' => $items,
            'next_cursor' => $response->json('paging.cursors.after') ?: null,
        ];
    }

    public function replyToComment(SocialAccount $account, string $commentId, string $text): CommentResult
    {
        $ctx = AutomationLog::context(account: $account) + ['comment_id' => $commentId];

        $response = Http::post($this->graphBase()."/{$commentId}/replies", [
            'message' => $text,
            'access_token' => $account->access_token,
        ]);

        if (! $response->successful()) {
            $this->flagIfExpired($account, $response->json());
            AutomationLog::warning('public reply failed', $ctx + ['http_status' => $response->status(), 'body' => $response->body()]);

            return CommentResult::failure('Instagram reply failed: '.$response->body(), $response->json() ?? []);
        }

        AutomationLog::info('public reply sent', $ctx + ['reply_id' => $response->json('id')]);

        return CommentResult::success((string) $response->json('id'), $response->json() ?? []);
    }

    public function sendMessage(SocialAccount $account, string $recipientId, array $message): MessageResult
    {
        return $this->postMessage($account, ['id' => $recipientId], $message);
    }

    public function sendPrivateReply(SocialAccount $account, string $commentId, array $message): MessageResult
    {
        return $this->postMessage($account, ['comment_id' => $commentId], $message);
    }

    /** POST /{ig_user_id}/messages with either {id} or {comment_id} as recipient. */
    protected function postMessage(SocialAccount $account, array $recipient, array $message): MessageResult
    {
        $ctx = AutomationLog::context(account: $account) + ['recipient' => $recipient, 'message_type' => isset($message['attachment']) ? 'card' : 'text'];

        $response = Http::withToken($account->access_token)
            ->post($this->graphBase()."/{$account->platform_account_id}/messages", [
                'recipient' => $recipient,
                'message' => $message,
            ]);

        if (! $response->successful()) {
            $this->flagIfExpired($account, $response->json());
            AutomationLog::warning('dm send failed', $ctx + ['http_status' => $response->status(), 'body' => $response->body()]);

            return MessageResult::failure('Instagram message failed: '.$response->body(), $response->json() ?? [], $response->status());
        }

        AutomationLog::info('dm sent', $ctx + ['message_id' => $response->json('message_id')]);

        return MessageResult::success($response->json('message_id') ? (string) $response->json('message_id') : null, $response->json() ?? []);
    }

    /** Flag the account for reconnect when a Graph error is a dead token. */
    protected function flagIfExpired(SocialAccount $account, ?array $body): void
    {
        if ($this->isExpiredTokenError($body)) {
            $account->markNeedsReauth('Instagram session expired — reconnect the account.');
            AutomationLog::error('access token expired — flagged for reconnect', AutomationLog::context(account: $account));
        }
    }
}
