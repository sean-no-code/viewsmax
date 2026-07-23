<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LinkedIn publishing via the Posts API (member context). Uses OpenID Connect
 * to identify the member, then posts as urn:li:person:{sub}.
 */
class LinkedInProvider extends AbstractSocialProvider implements SupportsComments
{
    protected string $platform = 'linkedin';

    protected const DEFAULT_API_VERSION = '202601';

    /**
     * The LinkedIn-Version (YYYYMM) to send. Configurable so an expired version
     * can be bumped via env without a code change. Defensive against malformed
     * env values: LinkedIn 426s NONEXISTENT_VERSION on anything that isn't an
     * active YYYYMM (e.g. the 8-digit "20250601" seen in prod), so anything
     * that isn't exactly YYYYMM falls back to the default. Versions sunset
     * after ~1 year — bump DEFAULT_API_VERSION when publishes start 426ing.
     */
    protected function apiVersion(): string
    {
        $configured = (string) $this->config('api_version');

        return preg_match('/^\d{6}$/', $configured) ? $configured : self::DEFAULT_API_VERSION;
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $this->scopeString(' '),
        ]);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $tokens = $this->exchangeAuthorizationCode(
            'https://www.linkedin.com/oauth/v2/accessToken',
            $code,
            $redirectUri
        );

        // OpenID userinfo gives us the member id (sub) and profile basics.
        $profile = Http::withToken($tokens->accessToken)
            ->get('https://api.linkedin.com/v2/userinfo')
            ->json();

        $memberId = $profile['sub'] ?? null;

        $account = $this->storeAccount($user, [
            'platform_account_id' => $memberId,
            'name' => $profile['name'] ?? null,
            'username' => $profile['email'] ?? null,
            'avatar_url' => $profile['picture'] ?? null,
            'profile_url' => 'https://www.linkedin.com/in/me',
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scopes,
            'metadata' => [
                'author_urn' => $memberId ? 'urn:li:person:'.$memberId : null,
            ],
        ]);

        return collect([$account]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        if ($account->hasValidToken() || empty($account->refresh_token)) {
            return $account;
        }

        try {
            $tokens = $this->refreshWithToken('https://www.linkedin.com/oauth/v2/accessToken', $account->refresh_token);

            return $this->applyTokens($account, $tokens);
        } catch (\Throwable $e) {
            $account->markNeedsReauth('LinkedIn token refresh failed.');

            return $account;
        }
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $log = ['account_id' => $account->id, 'api_version' => $this->apiVersion()];
        Log::info('[LinkedIn] publish start', $log);

        // LinkedIn's Posts API can't publish video. Fail this target with a clear
        // note instead of silently dropping the video and posting text-only. Only
        // this target is affected — sibling platform targets publish normally.
        if ($this->firstVideoUrl($post)) {
            Log::warning('[LinkedIn] video not supported; failing target', $log);

            return PublishResult::failure('LinkedIn API does not support video posts.');
        }

        $authorUrn = $account->meta('author_urn');
        if (! $authorUrn) {
            Log::warning('[LinkedIn] missing author_urn', $log);

            return PublishResult::failure('LinkedIn author URN is missing; reconnect the account.');
        }
        $log['author_urn'] = $authorUrn;

        $commentary = (string) $post->content;
        $image = $this->firstImageUrl($post);
        Log::info('[LinkedIn] building post', $log + [
            'commentary_length' => mb_strlen($commentary),
            'has_image' => (bool) $image,
        ]);

        $body = [
            'author' => $authorUrn,
            'commentary' => $commentary,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        // Attach an image if one was uploaded successfully.
        if ($image) {
            $imageUrn = $this->uploadImage($account, $image);
            if ($imageUrn) {
                Log::info('[LinkedIn] image attached', $log + ['image_urn' => $imageUrn]);
                $body['content'] = [
                    'media' => [
                        'title' => mb_substr($commentary, 0, 60) ?: 'Image',
                        'id' => $imageUrn,
                    ],
                ];
            } else {
                Log::warning('[LinkedIn] image upload failed; posting text only', $log + ['image_url' => $image]);
            }
        }

        Log::info('[LinkedIn] POST /rest/posts request', $log + ['body' => $body]);

        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'LinkedIn-Version' => $this->apiVersion(),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->post('https://api.linkedin.com/rest/posts', $body);

        Log::info('[LinkedIn] POST /rest/posts response', $log + [
            'http_status' => $response->status(),
            'body' => $response->body(),
            'restli_id' => $response->header('x-restli-id'),
            'linkedin_id' => $response->header('x-linkedin-id'),
        ]);

        if (! $response->successful()) {
            Log::error('[LinkedIn] publish failed', $log + [
                'http_status' => $response->status(),
                'response' => $response->body(),
            ]);

            return PublishResult::failure('LinkedIn publish failed: '.$response->body(), $response->json() ?? []);
        }

        // The created post id is returned in the x-restli-id / x-linkedin-id header.
        $postUrn = $response->header('x-restli-id') ?: $response->header('x-linkedin-id');
        Log::info('[LinkedIn] publish success', $log + ['post_urn' => $postUrn]);

        return PublishResult::success(
            $postUrn,
            $postUrn ? "https://www.linkedin.com/feed/update/{$postUrn}" : null,
            $response->json() ?? []
        );
    }

    /**
     * Register and upload an image, returning its urn (or null on failure).
     */
    protected function uploadImage(SocialAccount $account, string $imageUrl): ?string
    {
        $authorUrn = $account->meta('author_urn');

        $init = Http::withToken($account->access_token)
            ->withHeaders([
                'LinkedIn-Version' => $this->apiVersion(),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->post('https://api.linkedin.com/rest/images?action=initializeUpload', [
                'initializeUploadRequest' => ['owner' => $authorUrn],
            ]);

        if (! $init->successful()) {
            Log::error('[LinkedIn] image initializeUpload failed', [
                'account_id' => $account->id,
                'http_status' => $init->status(),
                'response' => $init->body(),
            ]);

            return null;
        }

        $uploadUrl = $init->json('value.uploadUrl');
        $imageUrn = $init->json('value.image');

        $binary = Http::get($imageUrl);
        if (! $binary->successful()) {
            Log::error('[LinkedIn] failed to fetch source image', [
                'account_id' => $account->id,
                'image_url' => $imageUrl,
                'http_status' => $binary->status(),
            ]);

            return null;
        }

        $upload = Http::withToken($account->access_token)
            ->withBody($binary->body(), $binary->header('Content-Type') ?: 'image/jpeg')
            ->put($uploadUrl);

        if (! $upload->successful()) {
            Log::error('[LinkedIn] image binary upload failed', [
                'account_id' => $account->id,
                'http_status' => $upload->status(),
                'response' => $upload->body(),
            ]);

            return null;
        }

        return $imageUrn;
    }

    /**
     * Post a comment on a published LinkedIn post (Social Actions API).
     * LinkedIn comments are flat — every one lands on the post itself, so
     * $previousRemoteId is ignored.
     */
    public function comment(SocialAccount $account, string $remotePostId, string $body, ?string $previousRemoteId = null): CommentResult
    {
        $actor = $account->meta('author_urn');
        if (! $actor) {
            return CommentResult::failure('LinkedIn author URN is missing; reconnect the account.');
        }

        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'LinkedIn-Version' => $this->apiVersion(),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->post('https://api.linkedin.com/rest/socialActions/'.urlencode($remotePostId).'/comments', [
                'actor' => $actor,
                'object' => $remotePostId,
                'message' => ['text' => $body],
            ]);

        if (! $response->successful()) {
            return CommentResult::failure('LinkedIn comment failed: '.$response->body(), $response->json() ?? []);
        }

        return CommentResult::success((string) ($response->json('id') ?? ''), $response->json() ?? []);
    }
}
