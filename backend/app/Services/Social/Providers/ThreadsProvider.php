<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\MetaGraphOutcome;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\MetaGraphErrors;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Threads publishing via the Threads API (graph.threads.net). The flow mirrors
 * Instagram: create a media container (or one per carousel item plus the
 * CAROUSEL parent), wait for it to finish processing, then publish it.
 */
class ThreadsProvider extends AbstractSocialProvider implements SupportsComments
{
    protected string $platform = 'threads';

    /** Threads carousels take 2–10 items. */
    public const MAX_CAROUSEL_ITEMS = 10;

    /** Container status polls: 20 × 5s ≈ 100s per container, inside the job's 300s timeout. */
    private const POLL_ATTEMPTS = 20;

    private const POLL_INTERVAL_SECONDS = 5;

    public const MESSAGE_MEDIA_UNPROCESSABLE = 'Threads could not process the media, please check the media format and try again';

    public const MESSAGE_MEDIA_TIMEOUT = 'Threads took too long to process the media, please try again';

    /**
     * Threads-specific Graph errors layered over MetaGraphErrors' generic
     * rules. Token
     * errors (code 190 / "Error validating access token") need no entry:
     * the generic rules already classify them as reauth.
     */
    public const ERRORS = [
        2207051 => [MetaGraphOutcome::FAIL, 'Error from Meta: We restrict certain activity to protect our community'],
        4279013 => [MetaGraphOutcome::FAIL, 'User restricted'],
        // threads_publish raced the container: the queue retries the whole post.
        4279009 => [MetaGraphOutcome::RETRY, 'Threads could not find the media container yet, please try again in a few seconds'],
        'The media could not be fetched from this URI' => [MetaGraphOutcome::FAIL, "One of the media URLs is invalid or inaccessible, make sure it's uploaded to ViewsMax first"],
        'text must be at most 500 characters' => [MetaGraphOutcome::FAIL, 'Post text exceeds 500 characters limit'],
    ];

    protected function apiBase(): string
    {
        return 'https://graph.threads.net/'.$this->config('api_version', 'v1.0');
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return 'https://threads.net/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => $this->scopeString(','),
        ]);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        // Short-lived token + user id.
        $short = Http::asForm()->post('https://graph.threads.net/oauth/access_token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $short->successful()) {
            $this->fail('token exchange', $short->status(), $short->body());
        }

        $userId = $short->json('user_id');
        $shortToken = $short->json('access_token');

        // Upgrade to a long-lived (≈60 day) token. This must NOT fall back to
        // the 1-hour short-lived token: storing it (with no expiry) made a
        // "successful" connect die within the hour at publish time (OAuth 190),
        // looping the user through reconnect → expired → reconnect.
        $long = Http::get('https://graph.threads.net/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => $this->clientSecret(),
            'access_token' => $shortToken,
        ]);

        if (! $long->successful() || ! $long->json('access_token')) {
            $this->fail('long-lived token exchange', $long->status(), $long->body());
        }

        $accessToken = $long->json('access_token');
        // Threads always returns expires_in (~60 days); guard with a real expiry
        // anyway — a null expiry reads as "valid forever" and is never refreshed.
        $expiresIn = (int) ($long->json('expires_in') ?: 5184000);

        // Fetch the profile for display.
        $profile = Http::withToken($accessToken)
            ->get($this->apiBase().'/me', ['fields' => 'id,username,name,threads_profile_picture_url'])
            ->json();

        $account = $this->storeAccount($user, [
            'platform_account_id' => $userId ?? ($profile['id'] ?? null),
            'name' => $profile['name'] ?? null,
            'username' => $profile['username'] ?? null,
            'avatar_url' => $profile['threads_profile_picture_url'] ?? null,
            'profile_url' => isset($profile['username']) ? 'https://www.threads.net/@'.$profile['username'] : null,
            'access_token' => $accessToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'scopes' => $this->config('scopes', []),
            'metadata' => ['threads_user_id' => $userId],
        ]);

        return collect([$account]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        // Meta-family long-lived tokens can only be refreshed while STILL
        // VALID (and ≥24h old) — waiting until expiry makes the refresh
        // itself fail and strands the account. Refresh early instead.
        $stillFresh = fn (SocialAccount $a) => $a->hasValidToken()
            && ! ($a->token_expires_at !== null && $a->token_expires_at->lt(now()->addDays(7)));
        if ($stillFresh($account)) {
            return $account;
        }

        return $this->refreshingSafely($account, isFresh: $stillFresh, refresh: function (SocialAccount $account) {
            // Long-lived Threads tokens are refreshed (not re-exchanged).
            $response = Http::get('https://graph.threads.net/refresh_access_token', [
                'grant_type' => 'th_refresh_token',
                'access_token' => $account->access_token,
            ]);

            if ($response->successful()) {
                return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
            }

            // Only a definitive rejection ON AN EXPIRED token means the user
            // must reconnect; anything else (5xx/429, or an early-refresh
            // hiccup on a still-valid token) must not brick the account.
            if ($this->isDefinitiveAuthFailure($response->status()) && ! $account->hasValidToken()) {
                $account->markNeedsReauth('Threads token expired — reconnect the account.');
            } else {
                Log::warning('Threads token refresh failed transiently', [
                    'account_id' => $account->id, 'status' => $response->status(),
                ]);
            }

            return $account;
        });
    }

    /**
     * @throws TransientPublishException when Meta asks for a retry before anything is published
     */
    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $userId = $account->platform_account_id;
        $text = (string) $post->content;
        $media = array_values(array_filter(
            $this->mediaItems($post),
            fn (array $m) => in_array($m['type'], ['image', 'video'], true),
        ));

        $log = ['account_id' => $account->id, 'user_id' => $userId, 'media_count' => count($media), 'api_base' => $this->apiBase()];
        Log::info('[Threads] publish start', $log + ['text_length' => mb_strlen($text)]);

        if (count($media) > self::MAX_CAROUSEL_ITEMS) {
            return PublishResult::failure('Threads carousels can hold at most '.self::MAX_CAROUSEL_ITEMS.' items; this post has '.count($media).'.');
        }

        // 1. Create the container (one per carousel item, then the parent).
        $creationId = count($media) > 1
            ? $this->createCarousel($account, $media, $text, $log)
            : $this->createContainer($account, $this->singleContainerParams($text, $media[0] ?? null), 'create container', $log);
        if ($creationId instanceof PublishResult) {
            return $creationId;
        }

        // 2. Wait until it is FINISHED. Containers process asynchronously and
        // publishing early fails with "Media not found" (4279009) — TEXT ones
        // included, so every type is polled.
        if ($failure = $this->awaitContainer($account, $creationId, $log)) {
            return $failure;
        }

        // 3. Publish. Nothing below may throw TransientPublishException: once
        // threads_publish succeeds a retry would post the thread twice.
        $publish = Http::withToken($account->access_token)->post($this->apiBase()."/{$userId}/threads_publish", [
            'creation_id' => $creationId,
        ]);

        Log::info('[Threads] publish container response', $log + [
            'creation_id' => $creationId,
            'http_status' => $publish->status(),
            'body' => $publish->body(),
        ]);

        if (! $publish->successful()) {
            return PublishResult::failure($this->graphFailure($account, $publish, 'publish', $log + ['creation_id' => $creationId]), $publish->json() ?? []);
        }

        $threadId = $publish->json('id');
        Log::info('[Threads] publish success', $log + ['thread_id' => $threadId]);

        return PublishResult::success($threadId, null, $publish->json());
    }

    /**
     * Post a reply on Threads. Chains onto the previous reply when given so a
     * series of comments forms a visible thread; text-only (Threads replies
     * with media aren't part of this pipeline). The comment job never retries
     * (a reply isn't idempotent), so a transient Meta error is a plain failure.
     */
    public function comment(SocialAccount $account, string $remotePostId, string $body, ?string $previousRemoteId = null): CommentResult
    {
        $userId = $account->platform_account_id;
        $log = ['account_id' => $account->id, 'user_id' => $userId, 'reply_to' => $remotePostId];

        try {
            $creationId = $this->createContainer($account, [
                'media_type' => 'TEXT',
                'text' => $body,
                'reply_to_id' => $previousRemoteId ?: $remotePostId,
            ], 'reply container', $log);
            if ($creationId instanceof PublishResult) {
                return CommentResult::failure((string) $creationId->error, $creationId->response);
            }

            if ($failure = $this->awaitContainer($account, $creationId, $log)) {
                return CommentResult::failure((string) $failure->error, $failure->response);
            }

            $publish = Http::withToken($account->access_token)->post($this->apiBase()."/{$userId}/threads_publish", [
                'creation_id' => $creationId,
            ]);

            if (! $publish->successful()) {
                return CommentResult::failure($this->graphFailure($account, $publish, 'reply publish', $log), $publish->json() ?? []);
            }

            return CommentResult::success((string) $publish->json('id'), $publish->json() ?? []);
        } catch (TransientPublishException $e) {
            return CommentResult::failure($e->getMessage());
        }
    }

    /**
     * Carousel: one is_carousel_item container per media, all of them
     * processed, then the CAROUSEL parent that carries the text and the
     * children ids. Returns the parent's id, or the failure to hand back.
     *
     * @param  list<array{url: string, type: string}>  $media
     */
    private function createCarousel(SocialAccount $account, array $media, string $text, array $log): string|PublishResult
    {
        $children = [];
        foreach ($media as $item) {
            $id = $this->createContainer($account, $this->mediaParams($item) + ['is_carousel_item' => 'true'], 'create carousel item', $log);
            if ($id instanceof PublishResult) {
                return $id;
            }
            $children[] = $id;
        }

        foreach ($children as $id) {
            if ($failure = $this->awaitContainer($account, $id, $log)) {
                return $failure;
            }
        }

        return $this->createContainer($account, [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'text' => $text,
        ], 'create carousel', $log + ['children' => $children]);
    }

    /** @param  array{url: string, type: string}|null  $media */
    private function singleContainerParams(string $text, ?array $media): array
    {
        return ($media ? $this->mediaParams($media) : ['media_type' => 'TEXT']) + ['text' => $text];
    }

    /** @param  array{url: string, type: string}  $media */
    private function mediaParams(array $media): array
    {
        return $media['type'] === 'video'
            ? ['media_type' => 'VIDEO', 'video_url' => $media['url']]
            : ['media_type' => 'IMAGE', 'image_url' => $media['url']];
    }

    /**
     * POST /{user}/threads. Returns the container id, or the failure to hand
     * back when Meta rejected it (a transient rejection is thrown instead).
     */
    private function createContainer(SocialAccount $account, array $params, string $step, array $log): string|PublishResult
    {
        // An empty caption is omitted rather than sent as text="".
        $create = Http::withToken($account->access_token)
            ->post($this->apiBase()."/{$account->platform_account_id}/threads", array_filter($params, fn ($v) => $v !== null && $v !== ''));

        Log::info("[Threads] {$step} response", $log + [
            'media_type' => $params['media_type'],
            'http_status' => $create->status(),
            'body' => $create->body(),
        ]);

        if (! $create->successful() || ! $create->json('id')) {
            return PublishResult::failure($this->graphFailure($account, $create, $step, $log), $create->json() ?? []);
        }

        return (string) $create->json('id');
    }

    /**
     * Poll the container until it is FINISHED. Returns null when it is ready,
     * otherwise the failure to hand back: Threads' own error_message when it
     * rejected the media (ERROR/EXPIRED), a timeout, or a Graph error on the
     * poll itself (classified like any other call).
     */
    private function awaitContainer(SocialAccount $account, string $creationId, array $log): ?PublishResult
    {
        $log += ['creation_id' => $creationId];
        $status = 'IN_PROGRESS';
        $errorMessage = null;

        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            $poll = Http::withToken($account->access_token)
                ->get($this->apiBase()."/{$creationId}", ['fields' => 'status,error_message']);

            if (! $poll->successful()) {
                return PublishResult::failure($this->graphFailure($account, $poll, 'container status', $log), $poll->json() ?? []);
            }

            $status = (string) $poll->json('status');
            $errorMessage = $poll->json('error_message');

            if ($status === 'FINISHED') {
                Log::info('[Threads] container processed', $log);

                return null;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                break;
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }

        Log::error('[Threads] media not publishable', $log + ['status' => $status, 'error_message' => $errorMessage]);

        if (! in_array($status, ['ERROR', 'EXPIRED'], true)) {
            return PublishResult::failure(self::MESSAGE_MEDIA_TIMEOUT);
        }

        // Threads reports UNKNOWN when it has nothing useful to say.
        return PublishResult::failure(
            is_string($errorMessage) && $errorMessage !== '' && $errorMessage !== 'UNKNOWN'
                ? $errorMessage
                : self::MESSAGE_MEDIA_UNPROCESSABLE,
        );
    }

    /**
     * Classify a failed Graph call: a transient outcome goes to the queue, a
     * dead token flags the account for reconnect (so the user is told once
     * instead of every post failing with raw JSON while the account still
     * shows connected), and the user-facing message comes back.
     *
     * @throws TransientPublishException
     */
    private function graphFailure(SocialAccount $account, Response $response, string $step, array $log): string
    {
        $outcome = MetaGraphErrors::fromResponse($response, self::ERRORS);

        Log::error("[Threads] {$step} failed", $log + ['kind' => $outcome->kind, 'reason' => $outcome->raw]);

        $outcome->throwIfRetry();

        if ($outcome->needsReauth()) {
            $account->markNeedsReauth($outcome->message);
        }

        return $outcome->message;
    }
}
