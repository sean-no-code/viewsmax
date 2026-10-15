<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\MetaGraphOutcome;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\MetaGraphErrors;
use App\Services\Social\SocialConnectException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Instagram publishing via the "Instagram API with Instagram Login".
 *
 * This connects directly to an Instagram professional (Business/Creator)
 * account — no Facebook Page required. The flow mirrors Threads: authorize on
 * instagram.com, exchange for a short-lived token, upgrade to a long-lived
 * (~60 day) token, then publish via the graph.instagram.com create-container →
 * publish two-step (polling containers until processed). Graph errors go
 * through MetaGraphErrors +
 * ERRORS: transient ones throw TransientPublishException for the queue, dead
 * tokens and checkpoints flag the account, everything else fails with a
 * readable reason.
 */
class InstagramProvider extends AbstractSocialProvider implements SupportsComments
{
    protected string $platform = 'instagram';

    /**
     * Container polling budget for one publish, kept under
     * PublishToInstagramJob's 600 s timeout:
     * 16 × 30 s = 8 minutes.
     */
    public const CONTAINER_POLL_ATTEMPTS = 16;

    public const CONTAINER_POLL_INTERVAL_SECONDS = 30;

    /**
     * Instagram-specific Graph errors for MetaGraphErrors, keyed by
     * error_subcode or by body text, each with a readable reason for the
     * user. The generic rules (5xx/429, code
     * 190, (#200) / "Not enough permissions", "An unknown error occurred") are
     * in MetaGraphErrors itself; "190," is deliberately not a key here because
     * it would shadow the reauth rule for every dead token.
     *
     * A subcode key always beats a text key, so two cases are matched by
     * their JSON text fragment instead: subcode 2207051 arrives with the
     * checkpoint sentence in its message and must stay a checkpoint (the
     * checkpoint texts are listed first and win), and a bare 33 would also
     * match "33" inside a trace id.
     */
    public const ERRORS = [
        // retry
        2207082 => [MetaGraphOutcome::RETRY, 'Instagram could not process this video. If you attached audio to a video that has no sound track, set the original video volume to 0 and try again'],
        // checkpoint — before the 2207051 fragment below
        'You cannot access the app till you log in to' => [MetaGraphOutcome::CHECKPOINT, MetaGraphErrors::MESSAGE_CHECKPOINT],
        'Session key is malformed' => [MetaGraphOutcome::CHECKPOINT, MetaGraphErrors::MESSAGE_CHECKPOINT],
        // reauth
        '"error_subcode":33' => [MetaGraphOutcome::REAUTH, 'Something is wrong with your connected user, please re-authenticate'],
        'the user is not an instagram business' => [MetaGraphOutcome::REAUTH, 'Your Instagram account is not a business account, please convert it to a business account'],
        'session has been invalidated' => [MetaGraphOutcome::REAUTH, 'Your session has been invalidated, this can usually happen from frequent posting, please re-authenticate, and wait 1-2 days before posting again'],
        // restrictions / spam
        2207050 => [MetaGraphOutcome::FAIL, 'Instagram user is restricted'],
        '"error_subcode":2207051' => [MetaGraphOutcome::FAIL, 'Instagram blocked your request'],
        2207001 => [MetaGraphOutcome::FAIL, 'Instagram detected that your post is spam, please try again with different content'],
        2207027 => [MetaGraphOutcome::FAIL, 'Unknown error, please try again later or contact support'],
        // rate limits (not a reconnect, even though Meta sends them as OAuthException)
        2207042 => [MetaGraphOutcome::FAIL, 'You have reached the maximum of 25 posts per day, allowed for your account'],
        'Page request limit reached' => [MetaGraphOutcome::FAIL, 'Instagram posting for today is limited, please try again tomorrow'],
        // media download / upload
        2207003 => [MetaGraphOutcome::FAIL, 'Timeout downloading media, please try again'],
        2207004 => [MetaGraphOutcome::FAIL, 'Image is too large'],
        2207005 => [MetaGraphOutcome::FAIL, 'Unsupported image format'],
        2207006 => [MetaGraphOutcome::FAIL, 'Media not found, please upload again'],
        2207008 => [MetaGraphOutcome::FAIL, 'Media builder expired, please try again'],
        2207009 => [MetaGraphOutcome::FAIL, 'Aspect ratio not supported, must be between 4:5 to 1.91:1'],
        2207010 => [MetaGraphOutcome::FAIL, 'Caption is too long'],
        2207020 => [MetaGraphOutcome::FAIL, 'Media expired, please upload again'],
        2207023 => [MetaGraphOutcome::FAIL, 'Unknown media type'],
        2207026 => [MetaGraphOutcome::FAIL, 'Unsupported video format'],
        2207028 => [MetaGraphOutcome::FAIL, 'Carousel validation failed'],
        2207032 => [MetaGraphOutcome::FAIL, 'Failed to create media, please try again'],
        2207052 => [MetaGraphOutcome::FAIL, 'Media fetch failed, please try again'],
        2207053 => [MetaGraphOutcome::FAIL, "Instagram failed to upload the video. If it exceeds Instagram's limits (300MB, 1920px width, 25Mbps bitrate), export a smaller version and try again"],
        2207057 => [MetaGraphOutcome::FAIL, 'Invalid thumbnail offset for video'],
        2207077 => [MetaGraphOutcome::FAIL, 'Instagram Video download failed'],
        2207085 => [MetaGraphOutcome::FAIL, 'Instagram could not process the video, please check the video format, duration and resolution and try again'],
        36001 => [MetaGraphOutcome::FAIL, 'Invalid Instagram image resolution max: 1920x1080px'],
        36003 => [MetaGraphOutcome::FAIL, 'Aspect ratio not supported, must be between 4:5 to 1.91:1'],
        'too little or too many attachments' => [MetaGraphOutcome::FAIL, 'Instagram carousel should have between 2 and 10 media attachments'],
    ];

    /** Polls left for the publish in progress; reset by publish(). */
    private int $pollsLeft = self::CONTAINER_POLL_ATTEMPTS;

    protected function graphBase(): string
    {
        return 'https://graph.instagram.com/'.$this->config('graph_version', config('social.meta_graph_version', 'v25.0'));
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
            // Never offer "Log in with Facebook" on the dialog: that path hands
            // back a token graph.instagram.com rejects.
            'enable_fb_login' => '0',
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

        // Meta documents this response as {"data":[{"access_token","user_id","permissions"}]}
        // but some apps receive the flat {"access_token","user_id"} shape. Accept both:
        // reading only the flat shape left $shortToken null, and sending an empty
        // access_token to the long-lived exchange made Graph answer with the
        // misleading "Unsupported request - method type: get" (code 100).
        $shortToken = $short->json('access_token') ?? $short->json('data.0.access_token');
        $userId = $short->json('user_id') ?? $short->json('data.0.user_id');
        Log::info('[Instagram] short-lived token obtained', ['has_token' => ! empty($shortToken), 'user_id' => $userId]);

        if (empty($shortToken)) {
            Log::error('[Instagram] token exchange returned no access_token', ['body' => $short->body()]);
            $this->fail('token exchange', $short->status(), 'no access_token in response: '.$short->body());
        }

        $this->assertRequiredPermissions($short->json('permissions') ?? $short->json('data.0.permissions'));

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
            // Graph answers "Unsupported request - method type: get" (code 100) for
            // an empty access_token, but also for tokens it will not extend — seen
            // in production for one account while another connected fine with the
            // same build. The short-lived token is still valid for an hour, so ask
            // Graph who it belongs to: account_type tells us whether the account is
            // a professional one (personal accounts cannot use this API).
            $whoami = Http::withToken($shortToken)
                ->get($this->graphBase().'/me', ['fields' => 'user_id,username,account_type'])
                ->json();

            Log::error('[Instagram] long-lived token exchange failed', [
                'http_status' => $long->status(),
                'body' => $long->body(),
                'short_token_length' => strlen($shortToken),
                'ig_user_id' => $userId,
                'granted_permissions' => $short->json('permissions') ?? $short->json('data.0.permissions'),
                'account_type' => $whoami['account_type'] ?? null,
                'username' => $whoami['username'] ?? null,
                'whoami_error' => $whoami['error']['message'] ?? null,
            ]);
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

    /**
     * Fail the connect when the user unticked a publish-critical permission on
     * the Instagram consent screen. The token exchange succeeds anyway, and
     * storing the account meant every later post died with a raw (#200)/190
     * body instead of a fix the user could act on. Instagram reports the grant
     * on the token exchange itself as an array or a comma/space-separated
     * string; when it is absent we cannot verify and let the connect through.
     *
     * @param  array<int, string>|string|null  $granted
     *
     * @throws SocialConnectException naming the missing permissions
     */
    protected function assertRequiredPermissions(array|string|null $granted): void
    {
        if ($granted === null) {
            return;
        }

        if (is_string($granted)) {
            $granted = preg_split('/[,\s]+/', urldecode($granted), -1, PREG_SPLIT_NO_EMPTY);
        }

        // The insights scope is only requested behind the reach flag and never
        // blocks publishing, so a user who declines it may still connect.
        $required = array_diff((array) $this->config('scopes', []), ['instagram_business_manage_insights']);
        $missing = array_values(array_diff($required, $granted));

        Log::info('[Instagram] permissions checked', ['granted' => $granted, 'missing' => $missing]);

        if ($missing !== []) {
            throw new SocialConnectException(
                'Instagram did not grant the permissions this app needs to publish: '
                .implode(', ', $missing).'. Reconnect the account and tick every box on the permission screen.'
            );
        }
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
        $caption = (string) $post->content;
        $items = $this->mediaItems($post);
        $this->pollsLeft = self::CONTAINER_POLL_ATTEMPTS;

        $kind = match (true) {
            $items === [] => 'none',
            $items[0]['type'] === 'video' => 'REELS',
            default => 'IMAGE',
        };
        $log = ['account_id' => $account->id, 'ig_user_id' => $igUserId, 'media' => $kind, 'media_count' => count($items)];
        Log::info('[Instagram] publish start', $log + ['caption_length' => mb_strlen($caption)]);

        if ($items === []) {
            Log::warning('[Instagram] no media supplied', $log);

            return PublishResult::failure('Instagram requires at least one image or video to publish.');
        }

        // 1. Create a media container. A Reel may carry a custom cover image
        // (public URL); Instagram ignores cover_url for non-video posts.
        $video = $kind === 'REELS';
        $creationId = $this->createContainer($account, array_filter([
            'caption' => $caption,
            'image_url' => $video ? null : $items[0]['url'],
            'video_url' => $video ? $items[0]['url'] : null,
            'media_type' => $video ? 'REELS' : null,
            'cover_url' => $video ? ($post->cover_url ?? null) : null,
        ]), $log);

        if ($creationId instanceof PublishResult) {
            return $creationId;
        }

        // 2. Video containers must finish processing before publishing.
        if ($video && ($failure = $this->waitForContainer($account, $creationId, $log))) {
            return $failure;
        }

        // 3. Publish the container.
        $publish = Http::post($this->graphBase()."/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $account->access_token,
        ]);

        Log::info('[Instagram] media_publish response', $log + [
            'creation_id' => $creationId,
            'http_status' => $publish->status(),
            'body' => $publish->body(),
        ]);

        if (! $publish->successful()) {
            $outcome = $this->handleGraphError($account, MetaGraphErrors::fromResponse($publish, self::ERRORS), 'publish', $log + ['creation_id' => $creationId])
                ->throwIfRetry();

            return PublishResult::failure($outcome->message, $publish->json() ?? []);
        }

        $mediaId = $publish->json('id');
        Log::info('[Instagram] publish success', $log + ['media_id' => $mediaId]);

        return PublishResult::success($mediaId, null, $publish->json());
    }

    /**
     * POST a media container for the account. Returns its id, or the failure
     * to hand back when Graph rejects it (a transient rejection throws, see
     * handleGraphError). The access token is added here.
     *
     * @param  array<string, mixed>  $params
     */
    protected function createContainer(SocialAccount $account, array $params, array $log): string|PublishResult
    {
        $create = Http::post($this->graphBase()."/{$account->platform_account_id}/media", $params + [
            'access_token' => $account->access_token,
        ]);
        Log::info('[Instagram] create container response', $log + ['http_status' => $create->status(), 'body' => $create->body()]);

        if (! $create->successful()) {
            $outcome = $this->handleGraphError($account, MetaGraphErrors::fromResponse($create, self::ERRORS), 'container creation', $log)
                ->throwIfRetry();

            return PublishResult::failure($outcome->message, $create->json() ?? []);
        }

        return (string) $create->json('id');
    }

    /**
     * Poll a container until it is FINISHED. Returns null when it is ready,
     * otherwise the failure to hand back. An ERROR/EXPIRED container carries
     * its reason in `status` ("... Error code: 2207026"), not in an error
     * object, so that text is run through ERRORS the same way: a bad format,
     * a checkpoint or a retryable 2207082 reaches the user instead of one
     * generic "check the media URL" message.
     *
     * Draws on the per-publish poll budget (see CONTAINER_POLL_ATTEMPTS).
     */
    protected function waitForContainer(SocialAccount $account, string $creationId, array $log): ?PublishResult
    {
        $log += ['creation_id' => $creationId];

        while ($this->pollsLeft-- > 0) {
            $response = Http::get($this->graphBase()."/{$creationId}", [
                'fields' => 'status_code,status',
                'access_token' => $account->access_token,
            ]);

            if (! $response->successful()) {
                $outcome = $this->handleGraphError($account, MetaGraphErrors::fromResponse($response, self::ERRORS), 'container status', $log)
                    ->throwIfRetry();

                return PublishResult::failure($outcome->message, $response->json() ?? []);
            }

            $status = (string) $response->json('status_code');

            if ($status === 'FINISHED') {
                Log::info('[Instagram] container processed', $log);

                return null;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                // Wrapped as an error message so the classifier's fallback shows
                // Meta's own reason; the raw body keeps the subcode for the table.
                $reason = trim((string) $response->json('status')) ?: 'Instagram could not process the media';
                $outcome = $this->handleGraphError(
                    $account,
                    MetaGraphErrors::classify(200, ['error' => ['message' => $reason]], self::ERRORS, $response->body()),
                    'container processing',
                    $log + ['status_code' => $status],
                )->throwIfRetry();

                return PublishResult::failure($outcome->message, $response->json() ?? []);
            }

            if ($this->pollsLeft > 0) {
                Sleep::for(self::CONTAINER_POLL_INTERVAL_SECONDS)->seconds();
            }
        }

        Log::error('[Instagram] container still processing after the polling budget', $log);

        return PublishResult::failure('Instagram media processing did not complete in time.');
    }

    /**
     * Log a classified Graph error and, for a dead token or a checkpoint, flag
     * the account so the user is told to reconnect (a checkpoint message also
     * says to log in at instagram.com first). Returns the outcome so the
     * caller decides whether a retry outcome throws: the publish path does
     * (the queue backs off and retries), comment() does not (comment jobs run
     * once because a retry would double-post).
     */
    protected function handleGraphError(SocialAccount $account, MetaGraphOutcome $outcome, string $action, array $log): MetaGraphOutcome
    {
        Log::error("[Instagram] {$action} failed", $log + ['outcome' => $outcome->kind, 'reason' => $outcome->raw]);

        if ($outcome->needsReauth()) {
            $account->markNeedsReauth($outcome->message);
        }

        return $outcome;
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
            $outcome = $this->handleGraphError($account, MetaGraphErrors::fromResponse($response, self::ERRORS), 'comment', ['account_id' => $account->id, 'media_id' => $remotePostId]);

            return CommentResult::failure($outcome->message, $response->json() ?? []);
        }

        return CommentResult::success((string) $response->json('id'), $response->json() ?? []);
    }
}
