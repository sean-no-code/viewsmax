<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Exceptions\XSearchTierException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\CaptionRules;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * X (Twitter) publishing via API v2 with OAuth 2.0 Authorization Code + PKCE.
 */
class XProvider extends AbstractSocialProvider implements SupportsComments
{
    protected string $platform = 'x';

    protected const TOKEN_URL = 'https://api.twitter.com/2/oauth2/token';

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
            'scope' => $this->scopeString(' '),
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

        $response = Http::asForm()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post(self::TOKEN_URL, [
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId(),
            ]);

        if (! $response->successful()) {
            $account->markNeedsReauth('X token refresh failed.');

            return $account;
        }

        return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
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
                $label = $total > 1 ? sprintf(' at tweet %d of %d', $i + 1, $total) : '';
                $message = "X publish failed{$label}: ".$response->body();

                // Rate limits / X hiccups: let the queue retry and resume.
                if ($response->status() === 429 || $response->serverError()) {
                    throw new TransientPublishException($message);
                }

                return PublishResult::failure($message, [
                    'tweet_ids' => $tweetIds,
                    'partial' => $tweetIds !== [],
                ] + ($response->json() ?? []));
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
            return CommentResult::failure('X comment failed: '.$response->body(), $response->json() ?? []);
        }

        return CommentResult::success((string) $response->json('data.id'), $response->json() ?? []);
    }

    /**
     * Upload up to 4 images via the v2 media endpoint (media.write scope).
     * A refused upload is a loud failure — silently posting text-only when the
     * user attached images would misrepresent their post.
     *
     * @return array{0: array<int, string>, 1: ?string} [mediaIds, error]
     */
    protected function uploadImages(SocialAccount $account, SocialPost $post): array
    {
        $ids = [];

        foreach (array_slice($this->mediaItems($post, 'image'), 0, CaptionRules::X_MAX_IMAGES) as $img) {
            $binary = Http::get($img['url']);
            if (! $binary->successful()) {
                return [[], 'X image upload failed: could not fetch '.$img['url']];
            }

            $upload = Http::withToken($account->access_token)
                ->attach('media', $binary->body(), 'upload')
                ->post('https://api.twitter.com/2/media/upload', [
                    'media_category' => 'tweet_image',
                ]);

            $id = $upload->json('data.id') ?? $upload->json('media_id_string');
            if (! $id) {
                return [[], 'X image upload was refused: '.$upload->body()];
            }

            $ids[] = (string) $id;
        }

        return [$ids, null];
    }
}
