<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Exceptions\XSearchTierException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\CaptionRules;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Contracts\SupportsFollowing;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * X (Twitter) publishing via API v2 with OAuth 2.0 Authorization Code + PKCE.
 */
class XProvider extends AbstractSocialProvider implements SupportsComments, SupportsFollowing
{
    protected string $platform = 'x';

    /** Only requested when the user leaves "Follow us" ticked at connect time. */
    public const FOLLOW_SCOPE = 'follows.write';

    protected const TOKEN_URL = 'https://api.twitter.com/2/oauth2/token';

    /**
     * What publishing needs (text + images + silent refresh). Checked against
     * the token response at connect so a token minted without one of these
     * fails there, not at publish. follows.write is optional and not listed.
     */
    public const PUBLISH_SCOPES = ['tweet.read', 'tweet.write', 'users.read', 'offline.access', 'media.write'];

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        // PKCE: the caller generates a verifier and passes its S256 challenge.
        $challenge = $options['code_challenge'] ?? null;

        // X moved the OAuth 2.0 authorize page to the x.com host. The legacy
        // twitter.com/i/oauth2/authorize page fires a broken api.twitter.com
        // validation call (400) — landing on x.com avoids it.
        return 'https://x.com/i/oauth2/authorize?'.http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $this->scopeString(' ').(empty($options['follow_us']) ? '' : ' '.self::FOLLOW_SCOPE),
            'code_challenge' => $challenge,
            'code_challenge_method' => $challenge ? 'S256' : null,
        ]));
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post(self::TOKEN_URL, [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'client_id' => $this->clientId(),
                'code_verifier' => $options['code_verifier'] ?? 'challenge',
            ]);

        if (! $response->successful()) {
            $this->fail('token exchange', $response->status(), $response->body());
        }

        $tokens = OAuthResult::fromArray($response->json());
        $this->assertGrantedScopes($tokens->scopes, self::PUBLISH_SCOPES);

        $profile = Http::withToken($tokens->accessToken)
            ->get('https://api.twitter.com/2/users/me', ['user.fields' => 'profile_image_url,username,name'])
            ->json('data');

        $account = $this->storeAccount($user, [
            'platform_account_id' => $profile['id'] ?? null,
            'name' => $profile['name'] ?? null,
            'username' => $profile['username'] ?? null,
            'avatar_url' => $profile['profile_image_url'] ?? null,
            'profile_url' => isset($profile['username']) ? 'https://x.com/'.$profile['username'] : null,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scopes,
            'metadata' => [],
        ]);

        return collect([$account]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        if ($account->hasValidToken() || empty($account->refresh_token)) {
            return $account;
        }

        // X refresh tokens are single-use and rotate — refreshes must be
        // serialized per account or concurrent publish jobs brick it.
        return $this->refreshingSafely($account, function (SocialAccount $account) {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->post(self::TOKEN_URL, [
                    'refresh_token' => $account->refresh_token,
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->clientId(),
                ]);

            if (! $response->successful()) {
                if ($this->isDefinitiveAuthFailure($response->status())) {
                    $account->markNeedsReauth('X token refresh was rejected — reconnect the account.');
                } else {
                    \Illuminate\Support\Facades\Log::warning('X token refresh failed transiently', [
                        'account_id' => $account->id, 'status' => $response->status(),
                    ]);
                }

                return $account;
            }

            return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
        });
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        return $this->publishThread($account, $post);
    }

    /**
     * Publish a caption as a tweet — or, when it contains `---` lines, as a
     * reply-chained thread. Progress-aware: $postedIds carries tweet ids that
     * already went out on a previous attempt (they are never re-posted, and
     * media never re-uploads mid-thread), and $onProgress is invoked with the
     * full id list after every tweet so the caller can persist resume state.
     *
     * A transient failure (429/5xx) mid-thread throws TransientPublishException
     * AFTER progress was persisted, so a queue retry resumes; a permanent
     * failure returns a PublishResult whose response carries the ids posted so
     * far plus a `partial` flag.
     *
     * @param  array<int, string>  $postedIds
     * @param  (callable(array<int, string>): void)|null  $onProgress
     */
    public function publishThread(SocialAccount $account, SocialPost $post, array $postedIds = [], ?callable $onProgress = null): PublishResult
    {
        $segments = CaptionRules::splitXThread((string) $post->content);
        if ($segments === []) {
            $segments = [''];
        }
        $total = count($segments);
        $tweetIds = array_values($postedIds);

        // Media rides on the first tweet only; a resume past tweet 1 must
        // never re-upload (the media is already on the live tweet).
        $mediaIds = [];
        if ($tweetIds === []) {
            [$mediaIds, $mediaError] = $this->uploadImages($account, $post);
            if ($mediaError !== null) {
                return PublishResult::failure($mediaError);
            }
            if ($segments[0] === '' && $mediaIds === []) {
                return PublishResult::failure('X post requires text or media.');
            }
        }

        $lastJson = [];
        for ($i = count($tweetIds); $i < $total; $i++) {
            $payload = ['text' => $segments[$i]];
            if ($i === 0 && $mediaIds !== []) {
                $payload['media'] = ['media_ids' => $mediaIds];
            }
            if ($i > 0) {
                $payload['reply'] = ['in_reply_to_tweet_id' => $tweetIds[$i - 1]];
            }

            $response = Http::withToken($account->access_token)
                ->post('https://api.twitter.com/2/tweets', $payload);

            if (! $response->successful()) {
                $progress = ['tweet_ids' => $tweetIds, 'partial' => $tweetIds !== []];

                // A dead token flags the account for reconnect.
                if ($this->failReauthIfUnauthorized($account, $response, 'publish', $this->tokenRejected(...))) {
                    return PublishResult::failure((string) $account->last_error, $progress);
                }

                $position = $total > 1 ? sprintf(' (tweet %d of %d)', $i + 1, $total) : '';
                $message = $this->rejectionMessage($response).$position;
                Log::warning('X publish rejected', [
                    'social_account_id' => $account->id,
                    'status' => $response->status(),
                    'body' => $this->bodyExcerpt($response),
                ]);

                // Rate limits / X hiccups: let the queue retry and resume. The
                // status rides in the exception code so PublishToXJob::failed()
                // can tell an exhausted rate limit apart.
                if (self::isTransientStatus($response->status())) {
                    throw new TransientPublishException($message, $response->status());
                }

                return PublishResult::failure($message, $progress + ($response->json() ?? []));
            }

            $tweetIds[] = (string) $response->json('data.id');
            $lastJson = $response->json() ?? [];
            if ($onProgress) {
                $onProgress($tweetIds);
            }

            // A polite pause between chained tweets, but not after the last one.
            if ($i < $total - 1) {
                Sleep::for(2)->seconds();
            }
        }

        $firstId = $tweetIds[0] ?? null;
        $username = $account->username;

        return PublishResult::success(
            $firstId,
            ($username && $firstId) ? "https://x.com/{$username}/status/{$firstId}" : null,
            ['tweet_ids' => $tweetIds] + $lastJson
        );
    }

    /**
     * Public like/reply/retweet counts for up to 100 tweets in one call.
     *
     * @param  array<int, string>  $tweetIds
     * @return array<string, array{like_count:int, retweet_count:int, reply_count:int}> keyed by tweet id
     */
    public function getTweetMetrics(SocialAccount $account, array $tweetIds): array
    {
        $response = Http::withToken($account->access_token)
            ->get('https://api.twitter.com/2/tweets', [
                'ids' => implode(',', array_slice($tweetIds, 0, 100)),
                'tweet.fields' => 'public_metrics',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('X metrics lookup failed: '.$response->body());
        }

        $metrics = [];
        foreach ($response->json('data') ?? [] as $tweet) {
            $metrics[$tweet['id']] = [
                'like_count' => (int) data_get($tweet, 'public_metrics.like_count', 0),
                'retweet_count' => (int) data_get($tweet, 'public_metrics.retweet_count', 0),
                'reply_count' => (int) data_get($tweet, 'public_metrics.reply_count', 0),
            ];
        }

        return $metrics;
    }

    /**
     * Follower count via users/me public_metrics. May 403 on restrictive API
     * plans (see XPublishedPostsService note) — returns null rather than throw.
     */
    public function fetchFollowerCount(SocialAccount $account): ?int
    {
        $account = $this->ensureFreshToken($account);

        $response = Http::withToken($account->access_token)
            ->get('https://api.twitter.com/2/users/me', ['user.fields' => 'public_metrics']);

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::warning('X follower lookup failed', [
                'account_id' => $account->id, 'status' => $response->status(),
            ]);

            return null;
        }

        $count = data_get($response->json(), 'data.public_metrics.followers_count');

        return $count === null ? null : (int) $count;
    }

    /**
     * Per-tweet engagement, normalized to the shared shape. X exposes no public
     * impression/view count on this plan, so views is 0.
     */
    public function fetchPostMetrics(SocialAccount $account, array $remotePostIds): array
    {
        if (empty($remotePostIds)) {
            return [];
        }

        $account = $this->ensureFreshToken($account);

        $out = [];
        foreach ($this->getTweetMetrics($account, $remotePostIds) as $id => $m) {
            $out[(string) $id] = [
                'likes' => (int) ($m['like_count'] ?? 0),
                'comments' => (int) ($m['reply_count'] ?? 0),
                'shares' => (int) ($m['retweet_count'] ?? 0),
                'views' => 0,
            ];
        }

        return $out;
    }

    /**
     * Keyword search for users, for the composer's @mention typeahead.
     * Tier-gated by X: a 403 throws XSearchTierException so callers can
     * degrade to lookupUsername(); other failures bubble as RequestException.
     *
     * @return array<int, array{id: string, username: string, name: string, avatar_url: ?string, verified: bool}>
     */
    public function searchUsers(SocialAccount $account, string $query, int $limit = 8): array
    {
        $response = Http::withToken($account->access_token)
            ->get('https://api.twitter.com/2/users/search', [
                'query' => $query,
                'max_results' => max(1, min($limit, 25)),
                'user.fields' => 'profile_image_url,verified',
            ]);

        if ($response->status() === 403) {
            throw new XSearchTierException('X user search refused (API tier): '.$response->body());
        }
        $response->throw();

        return array_map([$this, 'normalizeUser'], $response->json('data') ?? []);
    }

    /**
     * Exact-username lookup — the degraded mention path available on lower API
     * tiers. Null when the handle doesn't exist (X reports that as 200 +
     * errors, no data). Non-429 failures also resolve to null; a 429 bubbles
     * so the caller can surface the rate limit.
     *
     * @return array{id: string, username: string, name: string, avatar_url: ?string, verified: bool}|null
     */
    public function lookupUsername(SocialAccount $account, string $username): ?array
    {
        $response = Http::withToken($account->access_token)
            ->get('https://api.twitter.com/2/users/by/username/'.rawurlencode($username), [
                'user.fields' => 'profile_image_url,verified',
            ]);

        if ($response->status() === 429) {
            $response->throw();
        }
        if (! $response->successful() || ! $response->json('data')) {
            return null;
        }

        return $this->normalizeUser($response->json('data'));
    }

    public function follow(SocialAccount $account, string $handle): void
    {
        $target = $this->lookupUsername($account, $handle);
        if (! $target) {
            throw new \RuntimeException("X account @{$handle} not found.");
        }

        $response = Http::withToken($account->access_token)
            ->post("https://api.twitter.com/2/users/{$account->platform_account_id}/following", [
                'target_user_id' => $target['id'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("X refused the follow (HTTP {$response->status()}): ".$response->body());
        }
    }

    /**
     * @return array{id: string, username: string, name: string, avatar_url: ?string, verified: bool}
     */
    private function normalizeUser(array $user): array
    {
        return [
            'id' => (string) ($user['id'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'avatar_url' => $user['profile_image_url'] ?? null,
            'verified' => (bool) ($user['verified'] ?? false),
        ];
    }

    /**
     * Retweet a tweet as the connected account. An "already retweeted" refusal
     * reports success — the desired end state exists.
     *
     * @return array{success: bool, error: ?string}
     */
    public function retweet(SocialAccount $account, string $tweetId): array
    {
        $response = Http::withToken($account->access_token)
            ->post("https://api.twitter.com/2/users/{$account->platform_account_id}/retweets", [
                'tweet_id' => $tweetId,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'error' => null];
        }

        if (str_contains(strtolower($response->body()), 'already retweeted')) {
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => 'X retweet failed: '.$response->body()];
    }

    /**
     * A comment on X is a reply tweet. Chains onto the previous comment when
     * given, otherwise anchors to the post itself (for threads, callers pass
     * the LAST segment id so the reply continues the thread).
     */
    public function comment(SocialAccount $account, string $remotePostId, string $body, ?string $previousRemoteId = null): CommentResult
    {
        $response = Http::withToken($account->access_token)
            ->post('https://api.twitter.com/2/tweets', [
                'text' => $body,
                'reply' => ['in_reply_to_tweet_id' => $previousRemoteId ?: $remotePostId],
            ]);

        if (! $response->successful()) {
            if ($this->failReauthIfUnauthorized($account, $response, 'comment', $this->tokenRejected(...))) {
                return CommentResult::failure((string) $account->last_error, $response->json() ?? []);
            }

            Log::warning('X comment rejected', [
                'social_account_id' => $account->id,
                'status' => $response->status(),
                'body' => $this->bodyExcerpt($response),
            ]);

            return CommentResult::failure($this->rejectionMessage($response, 'the reply'), $response->json() ?? []);
        }

        return CommentResult::success((string) $response->json('data.id'), $response->json() ?? []);
    }

    /** Final error once a rate limit never cleared. */
    public const RATE_LIMIT_MESSAGE = 'X rate limit reached, please try again later';

    public const UNAVAILABLE_MESSAGE = 'X is currently unavailable, please try again later';

    /**
     * X error bodies → what the user reads instead of raw JSON.
     * Substring matches, checked in order.
     */
    protected const ERROR_MESSAGES = [
        'You are not permitted to perform this action' => 'There is a problem posting, please edit your post and check character count and media attachments',
        'Service Unavailable' => self::UNAVAILABLE_MESSAGE,
        'Too Many Requests' => self::RATE_LIMIT_MESSAGE,
        'maximum of one cashtag' => 'There can be maximum of one cashtag ($SYMBOL) per post',
        'maximum of 4 items' => 'There must be a maximum of 4 items per post',
        'You are not allowed to create a Tweet' => 'You are not allowed to create a post with duplicate content',
        'usage-capped' => 'Posting failed - capped reached. Please try again later',
        'user-suspended' => 'Your X account has been suspended, please reconnect with another account',
        'duplicate-rules' => 'You have already posted this post, please wait before posting again',
        'Your account is not permitted to access this feature' => 'X blocked your request',
        'The Tweet contains an invalid URL.' => 'The Tweet contains a URL that is not allowed on X',
        'This user is not allowed to post a video longer than 2 minutes' => 'The video you are trying to post is longer than 2 minutes, which is not allowed for this account',
        'This user is not allowed to post a video longer than 10 minutes' => 'The video you are trying to post is longer than 10 minutes, which is not allowed for this account',
        'Your account is temporarily locked' => 'Your X account is temporarily locked, log in to x.com to unlock it and then try again',
        'Crypto addresses are prohibited' => 'X does not allow crypto addresses in posts for the first 7 days after connecting the account',
        'Your media IDs are invalid' => 'X rejected the attached media, please re-upload the media and try again',
        'not authorized to create or publish articles' => 'Publishing articles on X requires an X Premium subscription',
        'Please include either text or media in your Tweet' => 'One of the posts in this thread has no text or media, please add some text or remove it',
    ];

    /**
     * User-facing reason for a refused X call. Known bodies come from
     * ERROR_MESSAGES; a rate limit or 5xx with an unlisted body still reads as
     * such; anything else quotes X's own detail/title ("X rejected the post:
     * Your Tweet text is too long.") rather than the JSON blob.
     */
    protected function rejectionMessage(Response $response, string $what = 'the post'): string
    {
        $body = (string) $response->body();
        foreach (self::ERROR_MESSAGES as $needle => $message) {
            if (str_contains($body, $needle)) {
                return $message;
            }
        }

        if ($response->status() === 429) {
            return self::RATE_LIMIT_MESSAGE;
        }
        if ($response->serverError()) {
            return self::UNAVAILABLE_MESSAGE;
        }

        $detail = $response->json('errors.0.message') ?: $response->json('detail') ?: $response->json('title');

        return "X rejected {$what}: ".($detail ?: "HTTP {$response->status()}");
    }

    /**
     * Bodies that mean a dead token regardless of status:
     * X answers some revoked/expired tokens with a 403 whose body still says
     * Unauthorized. Used as failReauthIfUnauthorized()'s $alsoWhen.
     */
    protected function tokenRejected(Response $response): bool
    {
        $body = (string) $response->body();

        return str_contains($body, '"title":"Unauthorized"') || str_contains($body, 'Unsupported Authentication');
    }

    public const MEDIA_SCOPE = 'media.write';

    public const RECONNECT_HINT = 'reconnect X under Dashboard → Connections to grant image upload, then try again.';

    /**
     * Upload up to 4 images via the v2 media endpoint (media.write scope).
     * A refused upload is a loud failure — silently posting text-only when the
     * user attached images would misrepresent their post.
     *
     * The scope is checked up front: a token minted without media.write is a
     * guaranteed 403, and the fix (reconnecting) is something only the user
     * can do, so say that instead of relaying X's bare `Forbidden`.
     *
     * @return array{0: array<int, string>, 1: ?string} [mediaIds, error]
     */
    protected function uploadImages(SocialAccount $account, SocialPost $post): array
    {
        $images = array_slice($this->mediaItems($post, 'image'), 0, CaptionRules::X_MAX_IMAGES);
        if ($images === []) {
            return [[], null];
        }

        // Scopes are what X granted at connect time. Unknown (empty) is left
        // to the upload itself; a known list without media.write is not.
        $scopes = (array) $account->scopes;
        if ($scopes !== [] && ! in_array(self::MEDIA_SCOPE, $scopes, true)) {
            return [[], 'X image upload needs a permission this connection was made without — '.self::RECONNECT_HINT];
        }

        $ids = [];

        foreach ($images as $img) {
            $binary = $this->fetchMedia($img['url']);
            if ($binary === null) {
                return [[], 'X image upload failed: could not fetch '.$img['url']];
            }

            $upload = Http::withToken($account->access_token)
                ->attach('media', $binary['body'], 'upload')
                ->post('https://api.twitter.com/2/media/upload', [
                    'media_category' => 'tweet_image',
                ]);

            $id = $upload->json('data.id') ?? $upload->json('media_id_string');
            if (! $id) {
                Log::warning('X image upload refused', [
                    'social_account_id' => $account->id,
                    'status' => $upload->status(),
                    'body' => $this->bodyExcerpt($upload),
                ]);

                if ($this->failReauthIfUnauthorized($account, $upload, 'image upload', $this->tokenRejected(...))) {
                    return [[], (string) $account->last_error];
                }

                // 403 with the scope present means X still sees an old token
                // (or the app lost media access) — reconnecting fixes both.
                $hint = $upload->status() === 403 ? ' — '.self::RECONNECT_HINT : '';

                return [[], $this->rejectionMessage($upload, 'the image upload').$hint];
            }

            $ids[] = (string) $id;
        }

        return [$ids, null];
    }
}
