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
 * Threads publishing via the Threads API (graph.threads.net). The flow mirrors
 * Instagram: create a media container, then publish it.
 */
class ThreadsProvider extends AbstractSocialProvider implements SupportsComments
{
    protected string $platform = 'threads';

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

        // Upgrade to a long-lived (≈60 day) token.
        $long = Http::get('https://graph.threads.net/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => $this->clientSecret(),
            'access_token' => $shortToken,
        ]);

        $accessToken = $long->json('access_token', $shortToken);
        $expiresIn = $long->json('expires_in');

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
            'token_expires_at' => $expiresIn ? now()->addSeconds((int) $expiresIn) : null,
            'scopes' => $this->config('scopes', []),
            'metadata' => ['threads_user_id' => $userId],
        ]);

        return collect([$account]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        if ($account->hasValidToken()) {
            return $account;
        }

        // Long-lived Threads tokens are refreshed (not re-exchanged).
        $response = Http::get('https://graph.threads.net/refresh_access_token', [
            'grant_type' => 'th_refresh_token',
            'access_token' => $account->access_token,
        ]);

        if ($response->successful()) {
            return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
        }

        $account->markNeedsReauth('Threads token refresh failed.');

        return $account;
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $userId = $account->platform_account_id;
        $token = $account->access_token;
        $text = (string) $post->content;
        $image = $this->firstImageUrl($post);
        $video = $this->firstVideoUrl($post);

        $mediaType = $video ? 'VIDEO' : ($image ? 'IMAGE' : 'TEXT');
        $log = ['account_id' => $account->id, 'user_id' => $userId, 'media_type' => $mediaType, 'api_base' => $this->apiBase()];
        Log::info('[Threads] publish start', $log + ['text_length' => mb_strlen($text)]);

        // 1. Create container.
        $create = Http::withToken($token)->post($this->apiBase()."/{$userId}/threads", array_filter([
            'media_type' => $mediaType,
            'text' => $text,
            'image_url' => $mediaType === 'IMAGE' ? $image : null,
            'video_url' => $mediaType === 'VIDEO' ? $video : null,
        ]));

        Log::info('[Threads] create container response', $log + [
            'http_status' => $create->status(),
            'body' => $create->body(),
        ]);

        if (! $create->successful()) {
            Log::error('[Threads] container creation failed', $log + ['response' => $create->body()]);

            return PublishResult::failure('Threads container creation failed: '.$create->body(), $create->json() ?? []);
        }

        $creationId = $create->json('id');

        // Media containers process asynchronously. Publishing before the container
        // is FINISHED fails with "Media not found" (code 24 / subcode 4279009), so
        // poll the container status until it's ready (mirrors Instagram).
        if ($mediaType !== 'TEXT') {
            $status = $this->waitForContainer($creationId, $token);
            Log::info('[Threads] container processed', $log + ['creation_id' => $creationId, 'status' => $status]);

            if ($status !== 'FINISHED') {
                Log::error('[Threads] media not publishable', $log + ['creation_id' => $creationId, 'status' => $status]);

                $reason = in_array($status, ['ERROR', 'EXPIRED'], true)
                    ? 'Threads could not process the media. Make sure the media URL is publicly reachable over HTTPS (not an expired/tunnelled link) and meets Threads specs.'
                    : 'Threads media processing did not complete in time.';

                return PublishResult::failure($reason);
            }
        }

        // 2. Publish container.
        $publish = Http::withToken($token)->post($this->apiBase()."/{$userId}/threads_publish", [
            'creation_id' => $creationId,
        ]);

        Log::info('[Threads] publish container response', $log + [
            'creation_id' => $creationId,
            'http_status' => $publish->status(),
            'body' => $publish->body(),
        ]);

        if (! $publish->successful()) {
            Log::error('[Threads] publish failed', $log + ['creation_id' => $creationId, 'response' => $publish->body()]);

            return PublishResult::failure('Threads publish failed: '.$publish->body(), $publish->json() ?? []);
        }

        $threadId = $publish->json('id');
        Log::info('[Threads] publish success', $log + ['thread_id' => $threadId]);

        return PublishResult::success($threadId, null, $publish->json());
    }

    /**
     * Poll the container until it reaches a terminal state. Returns the final
     * status: FINISHED (ready), ERROR/EXPIRED (rejected), or IN_PROGRESS (timed
     * out). Threads exposes this as `status` (Instagram uses `status_code`).
     */
    protected function waitForContainer(string $creationId, string $token, int $maxAttempts = 20): string
    {
        $status = 'IN_PROGRESS';

        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = (string) Http::withToken($token)
                ->get($this->apiBase()."/{$creationId}", ['fields' => 'status'])
                ->json('status');

            if (in_array($status, ['FINISHED', 'ERROR', 'EXPIRED'], true)) {
                return $status;
            }

            sleep(5);
        }

        return $status;
    }

    /**
     * Post a reply on Threads. Chains onto the previous reply when given so a
     * series of comments forms a visible thread; text-only (Threads replies
     * with media aren't part of this pipeline).
     */
    public function comment(SocialAccount $account, string $remotePostId, string $body, ?string $previousRemoteId = null): CommentResult
    {
        $userId = $account->platform_account_id;
        $token = $account->access_token;

        $create = Http::withToken($token)->post($this->apiBase()."/{$userId}/threads", [
            'media_type' => 'TEXT',
            'text' => $body,
            'reply_to_id' => $previousRemoteId ?: $remotePostId,
        ]);

        if (! $create->successful()) {
            return CommentResult::failure('Threads reply container failed: '.$create->body(), $create->json() ?? []);
        }

        $publish = Http::withToken($token)->post($this->apiBase()."/{$userId}/threads_publish", [
            'creation_id' => $create->json('id'),
        ]);

        if (! $publish->successful()) {
            return CommentResult::failure('Threads reply publish failed: '.$publish->body(), $publish->json() ?? []);
        }

        return CommentResult::success((string) $publish->json('id'), $publish->json() ?? []);
    }
}
