<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SupportsComments;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * LinkedIn publishing via the Posts API (member context). Uses OpenID Connect
 * to identify the member, then posts as urn:li:person:{sub}.
 */
class LinkedInProvider extends AbstractSocialProvider implements SupportsComments
{
    protected string $platform = 'linkedin';

    protected const DEFAULT_API_VERSION = '202601';

    /**
     * What publishing needs: openid + profile identify the member (userinfo
     * sub → author urn), w_member_social creates posts. email is requested
     * but optional, so it is not listed.
     */
    public const PUBLISH_SCOPES = ['openid', 'profile', 'w_member_social'];

    /**
     * LinkedIn's cap on images in one multiImage post.
     */
    public const MAX_IMAGES = 20;

    /**
     * Image status polling: attempts × delay bounds how long a
     * publish waits for LinkedIn to finish processing an upload.
     */
    protected const IMAGE_STATUS_ATTEMPTS = 10;

    protected const IMAGE_STATUS_DELAY_SECONDS = 2;

    /**
     * Response bodies LinkedIn uses for transient conditions whatever the
     * status. Matched case-sensitively.
     */
    protected const RETRYABLE_BODY_MARKERS = ['Unable to obtain activity', 'resource is forbidden', 'Service Unavailable'];

    /**
     * LinkedIn "little text" markup characters and their escaped form (row
     * 104). A single strtr pass means the backslash added for one character
     * is never re-escaped by the next rule.
     */
    protected const COMMENTARY_ESCAPES = [
        '\\' => '\\\\',
        '<' => '\<',
        '>' => '\>',
        '#' => '\#',
        '~' => '\~',
        '_' => '\_',
        '|' => '\|',
        '[' => '\[',
        ']' => '\]',
        '*' => '\*',
        '(' => '\(',
        ')' => '\)',
        '{' => '\{',
        '}' => '\}',
        '@' => '\@',
    ];

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

    /**
     * Backslash-escape the characters LinkedIn's commentary format treats as
     * markup — sent raw they mangle the text
     * or get the post rejected. Organization mentions
     * (@[Name](urn:li:organization:123)) are LinkedIn's own syntax and pass
     * through untouched.
     */
    public static function escapeCommentary(string $text): string
    {
        $mention = '/@\[.+?\]\(urn:li:organization.+?\)/';
        preg_match_all($mention, $text, $found);
        $mentions = $found[0];

        $escaped = '';
        foreach (preg_split($mention, $text) as $i => $plain) {
            $escaped .= strtr($plain, self::COMMENTARY_ESCAPES).($mentions[$i] ?? '');
        }

        return $escaped;
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
        $this->assertGrantedScopes($tokens->scopes, self::PUBLISH_SCOPES);

        // OpenID userinfo gives us the member id (sub) and profile basics.
        $profile = Http::withToken($tokens->accessToken)
            ->get('https://api.linkedin.com/v2/userinfo')
            ->json();

        $memberId = $profile['sub'] ?? null;

        $account = $this->storeAccount($user, [
            'platform_account_id' => $memberId,
            'name' => $profile['name'] ?? null,
            // OIDC userinfo has no vanity handle (that needs r_basicprofile), and
            // the email is not a handle. Explicit null so a reconnect clears the
            // email older code stored here (storeAccount is an updateOrCreate).
            'username' => null,
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

        return $this->refreshingSafely($account, function (SocialAccount $account) {
            $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful()) {
                if ($this->isDefinitiveAuthFailure($response->status())) {
                    $account->markNeedsReauth('LinkedIn token refresh was rejected — reconnect the account.');
                } else {
                    Log::warning('LinkedIn token refresh failed transiently', [
                        'account_id' => $account->id, 'status' => $response->status(),
                    ]);
                }

                return $account;
            }

            return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
        });
    }

    /**
     * Versioned Rest.li request every /rest call starts from.
     */
    protected function rest(SocialAccount $account): PendingRequest
    {
        return Http::withToken($account->access_token)->withHeaders([
            'LinkedIn-Version' => $this->apiVersion(),
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
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

        $images = $this->mediaItems($post, 'image');
        if (count($images) > self::MAX_IMAGES) {
            Log::warning('[LinkedIn] too many images; failing target', $log + ['image_count' => count($images)]);

            return PublishResult::failure(sprintf('LinkedIn allows at most %d images per post (%d attached).', self::MAX_IMAGES, count($images)));
        }

        $commentary = self::escapeCommentary((string) $post->content);
        Log::info('[LinkedIn] building post', $log + [
            'commentary_length' => mb_strlen($commentary),
            'image_count' => count($images),
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

        // Every image must upload and finish processing before the post is
        // created: a broken upload fails the target rather
        // than silently posting text-only, and attaching an image LinkedIn has
        // not finished processing gets the post rejected.
        $imageUrns = [];
        foreach ($images as $image) {
            $uploaded = $this->uploadImage($account, $image['url']);
            if ($uploaded instanceof PublishResult) {
                return $uploaded;
            }
            $imageUrns[] = $uploaded;
        }

        if ($imageUrns !== []) {
            if ($notReady = $this->waitForImages($account, $imageUrns)) {
                return $notReady;
            }
            Log::info('[LinkedIn] images attached', $log + ['image_urns' => $imageUrns]);
            $body['content'] = $this->imageContent($images, $imageUrns, (string) $post->content);
        }

        Log::info('[LinkedIn] POST /rest/posts request', $log + ['body' => $body]);

        $response = $this->rest($account)->post('https://api.linkedin.com/rest/posts', $body);

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

            if ($this->failReauthIfUnauthorized($account, $response, 'publish')) {
                return PublishResult::failure((string) $account->last_error);
            }

            // A non-2xx create means nothing was posted, so a retry can't
            // double-post. Never throw once this call has succeeded.
            $this->throwIfRetryable($response, 'publish');

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
     * content.media for a single image, content.multiImage for several (row
     * 108). The media title is the one place LinkedIn shows plain text, so it
     * takes the unescaped caption.
     *
     * @param  array<int, array{url:string,alt:?string}>  $images
     * @param  array<int, string>  $urns
     */
    protected function imageContent(array $images, array $urns, string $caption): array
    {
        if (count($urns) === 1) {
            return ['media' => ['title' => mb_substr($caption, 0, 60) ?: 'Image', 'id' => $urns[0]]];
        }

        return ['multiImage' => ['images' => array_map(
            fn (string $urn, array $image) => array_filter(['id' => $urn, 'altText' => $image['alt'] ?? null]),
            $urns,
            $images
        )]];
    }

    /**
     * Besides 429/5xx, LinkedIn reports some transient conditions under other
     * statuses whose bodies mark them as retryable. Only
     * call this before anything was created — a retry re-runs the publish.
     *
     * @throws TransientPublishException
     */
    protected function throwIfRetryable(Response $response, string $action): void
    {
        $this->throwIfTransient($response, $action);

        foreach (self::RETRYABLE_BODY_MARKERS as $marker) {
            if (str_contains((string) $response->body(), $marker)) {
                Log::warning("[LinkedIn] {$action} failed transiently ({$marker}); queue will retry", [
                    'status' => $response->status(),
                    'body' => $this->bodyExcerpt($response),
                ]);

                throw new TransientPublishException("LinkedIn returned a temporary error during {$action} ({$marker}) — retrying automatically.");
            }
        }
    }

    /**
     * Register and upload one image. Returns its urn, or the failure publish()
     * must return (a broken image fails the post instead of silently
     * posting text-only).
     */
    protected function uploadImage(SocialAccount $account, string $imageUrl): string|PublishResult
    {
        $init = $this->rest($account)->post('https://api.linkedin.com/rest/images?action=initializeUpload', [
            'initializeUploadRequest' => ['owner' => $account->meta('author_urn')],
        ]);

        if (! $init->successful()) {
            Log::error('[LinkedIn] image initializeUpload failed', [
                'account_id' => $account->id,
                'http_status' => $init->status(),
                'response' => $init->body(),
            ]);

            if ($this->failReauthIfUnauthorized($account, $init, 'image upload')) {
                return PublishResult::failure((string) $account->last_error);
            }
            $this->throwIfRetryable($init, 'image upload');

            return PublishResult::failure('LinkedIn image upload could not be initialized (HTTP '.$init->status().'): '.$init->body(), $init->json() ?? []);
        }

        $uploadUrl = (string) $init->json('value.uploadUrl');
        $imageUrn = (string) $init->json('value.image');
        if ($uploadUrl === '' || $imageUrn === '') {
            Log::error('[LinkedIn] image initializeUpload returned no upload URL', ['account_id' => $account->id, 'response' => $init->body()]);

            return PublishResult::failure('LinkedIn image upload could not be initialized: no upload URL returned.');
        }

        $binary = $this->fetchMedia($imageUrl);
        if ($binary === null) {
            Log::error('[LinkedIn] failed to fetch source image', [
                'account_id' => $account->id,
                'image_url' => $imageUrl,
            ]);

            return PublishResult::failure('Could not download the image to upload to LinkedIn: '.$imageUrl);
        }

        $upload = Http::withToken($account->access_token)
            ->withBody($binary['body'], $binary['content_type'] ?: 'image/jpeg')
            ->put($uploadUrl);

        if (! $upload->successful()) {
            Log::error('[LinkedIn] image binary upload failed', [
                'account_id' => $account->id,
                'http_status' => $upload->status(),
                'response' => $upload->body(),
            ]);
            $this->throwIfTransient($upload, 'image upload');

            return PublishResult::failure('LinkedIn image upload failed (HTTP '.$upload->status().'): '.$upload->body());
        }

        return $imageUrn;
    }

    /**
     * Poll each uploaded image until LinkedIn reports it AVAILABLE;
     * a post created earlier is rejected with a processing error. Member
     * tokens (w_member_social) are write-only on /rest/images and answer 403
     * to the status read — wait one grace cycle instead of
     * polling and trust the upload. Returns the failure publish() must
     * return, or null once every image is ready.
     *
     * @param  array<int, string>  $urns
     */
    protected function waitForImages(SocialAccount $account, array $urns): ?PublishResult
    {
        foreach ($urns as $urn) {
            for ($attempt = 1; $attempt <= self::IMAGE_STATUS_ATTEMPTS; $attempt++) {
                $response = $this->rest($account)->get('https://api.linkedin.com/rest/images/'.rawurlencode($urn));

                if ($response->status() === 403) {
                    Log::info('[LinkedIn] image status not readable with this token; waiting one grace cycle', ['account_id' => $account->id]);
                    Sleep::for(self::IMAGE_STATUS_DELAY_SECONDS)->seconds();

                    return null;
                }
                if ($this->failReauthIfUnauthorized($account, $response, 'image status')) {
                    return PublishResult::failure((string) $account->last_error);
                }

                $status = (string) $response->json('status');
                if ($status === 'AVAILABLE') {
                    continue 2;
                }
                if ($status === 'PROCESSING_FAILED') {
                    $reason = (string) $response->json('processingFailureReason');
                    Log::error('[LinkedIn] image processing failed', ['account_id' => $account->id, 'image_urn' => $urn, 'response' => $response->body()]);

                    return PublishResult::failure('LinkedIn image processing failed'.($reason !== '' ? ": {$reason}" : '.'), $response->json() ?? []);
                }

                // PROCESSING, a non-2xx hiccup or a body without a status: ask again.
                Log::info('[LinkedIn] image still processing', [
                    'account_id' => $account->id, 'image_urn' => $urn, 'http_status' => $response->status(), 'status' => $status, 'attempt' => $attempt,
                ]);
                Sleep::for(self::IMAGE_STATUS_DELAY_SECONDS)->seconds();
            }

            return PublishResult::failure('LinkedIn did not finish processing the image in time; nothing was published, please try again.');
        }

        return null;
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

        $response = $this->rest($account)
            ->post('https://api.linkedin.com/rest/socialActions/'.urlencode($remotePostId).'/comments', [
                'actor' => $actor,
                'object' => $remotePostId,
                'message' => ['text' => self::escapeCommentary($body)],
            ]);

        if (! $response->successful()) {
            // The post is already live, so this never throws: a transient here
            // must not retry (and re-run) the publish that created it.
            if ($this->failReauthIfUnauthorized($account, $response, 'comment')) {
                return CommentResult::failure((string) $account->last_error);
            }

            return CommentResult::failure('LinkedIn comment failed: '.$response->body(), $response->json() ?? []);
        }

        return CommentResult::success((string) ($response->json('id') ?? ''), $response->json() ?? []);
    }
}
