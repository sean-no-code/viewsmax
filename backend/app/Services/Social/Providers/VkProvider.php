<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * VK publishing to the connected user's own wall.
 *
 * Sign-in is VK ID (OAuth 2.1, authorization code + PKCE) on id.vk.ru. The
 * redirect carries a `device_id` next to the code; the code exchange and every
 * refresh need it, so it is kept in the account's metadata. Access tokens last
 * an hour and each refresh rotates the refresh token.
 *
 * Posting goes through the VK API (api.vk.ru/method, `v` pinned in config):
 *  - text: wall.post with `message`
 *  - image: photos.getWallUploadServer → upload the file → photos.saveWallPhoto
 *  - video: video.save → upload the file to the returned upload_url
 * Each upload becomes a `photo{owner}_{id}` / `video{owner}_{id}` attachment;
 * VK takes at most 10 per post. VK answers errors with HTTP 200 and an
 * `error` object, so every call is checked for it (see apiError()).
 *
 * Community walls are not supported: they need a community pick and its own
 * token.
 */
class VkProvider extends AbstractSocialProvider
{
    protected string $platform = 'vk';

    /** VK wall posts take at most 10 media attachments (wall.post docs). */
    public const MAX_ATTACHMENTS = 10;

    protected const ID_BASE = 'https://id.vk.ru';

    protected const API_BASE = 'https://api.vk.ru/method';

    /** Refresh this long before expiry: tokens only live an hour. */
    protected const REFRESH_MARGIN_MINUTES = 5;

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        if (empty($options['code_challenge'])) {
            throw new RuntimeException('VK sign-in needs a PKCE code challenge.');
        }

        return self::ID_BASE.'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $options['code_challenge'],
            'code_challenge_method' => 'S256',
            'scope' => $this->scopeString(' '),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $deviceId = trim((string) ($options['device_id'] ?? ''));
        if ($deviceId === '') {
            throw new RuntimeException('VK did not send back a device id. Start the connection again.');
        }
        if (empty($options['code_verifier'])) {
            throw new RuntimeException('The VK sign-in expired. Start the connection again.');
        }

        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $options['code_verifier'],
            'redirect_uri' => $redirectUri,
            'device_id' => $deviceId,
            'state' => $options['state'] ?? Str::random(32),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            $this->fail('token exchange', $response->status(), $this->bodyExcerpt($response));
        }

        $tokens = OAuthResult::fromArray($response->json());
        $this->assertGrantedScopes($tokens->scopes, ['wall'],
            'Reconnect and allow access to your wall. If VK never offers it, wall posting has not been enabled for this app yet.');

        $userId = (string) $response->json('user_id');
        $profile = $this->profile($tokens->accessToken);
        $userId = $userId !== '' ? $userId : (string) ($profile['user_id'] ?? '');
        if ($userId === '') {
            $this->fail('profile lookup', $response->status(), 'no user_id in the token or user_info response');
        }

        $name = trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? ''));

        $account = $this->storeAccount($user, [
            'platform_account_id' => $userId,
            'name' => $name !== '' ? $name : 'VK id'.$userId,
            'username' => null,
            'avatar_url' => $profile['avatar'] ?? null,
            'profile_url' => 'https://vk.com/id'.$userId,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scopes ?: $this->config('scopes', []),
            // Every refresh must send the device id the sign-in returned.
            'metadata' => ['device_id' => $deviceId],
        ]);

        return collect([$account]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        $stillFresh = fn (SocialAccount $a) => $a->hasValidToken()
            && ! ($a->token_expires_at !== null && $a->token_expires_at->lt(now()->addMinutes(self::REFRESH_MARGIN_MINUTES)));
        if ($stillFresh($account) || empty($account->refresh_token)) {
            return $account;
        }

        $deviceId = (string) $account->meta('device_id');
        if ($deviceId === '') {
            // A refresh can't be made without it; only a reconnect brings it back.
            if (! $account->hasValidToken()) {
                $account->markNeedsReauth('VK needs you to reconnect the account.');
            }

            return $account;
        }

        return $this->refreshingSafely($account, isFresh: $stillFresh, refresh: function (SocialAccount $account) use ($deviceId) {
            $response = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'device_id' => $deviceId,
                'state' => Str::random(32),
            ]);

            if ($response->successful() && $response->json('access_token')) {
                return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
            }

            $error = (string) $response->json('error');
            $log = ['account_id' => $account->id, 'status' => $response->status(), 'body' => $this->bodyExcerpt($response)];

            if ($error === 'invalid_client') {
                // Our app credentials, not the user's grant: a reconnect won't help.
                Log::error('[VK] token refresh refused the app credentials; check VK_CLIENT_ID / VK_SERVICE_TOKEN', $log);

                return $account;
            }

            $transient = static::isTransientStatus($response->status())
                || in_array($error, ['slow_down', 'temporarily_unavailable', 'server_error'], true);

            // An early refresh refused on a still-valid token must not brick
            // the account; it is tried again before the token runs out.
            if (! $transient && ! $account->hasValidToken()) {
                Log::warning('[VK] token refresh rejected; account needs reconnect', $log);
                $account->markNeedsReauth('VK authorization expired or was revoked — reconnect the account.');
            } else {
                Log::warning('[VK] token refresh failed', $log);
            }

            return $account;
        });
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $text = trim((string) $post->content);
        $media = array_slice($this->mediaItems($post), 0, self::MAX_ATTACHMENTS);

        if ($text === '' && $media === []) {
            return PublishResult::failure('A VK post needs text, an image or a video.');
        }

        $ownerId = (string) $account->platform_account_id;

        try {
            $attachments = [];
            foreach ($media as $item) {
                $attachments[] = $item['type'] === 'video'
                    ? $this->uploadVideo($account, $item['url'])
                    : $this->uploadPhoto($account, $item['url']);
            }

            Log::info('[VK] wall.post', ['account_id' => $account->id, 'attachments' => count($attachments)]);
            $response = $this->call($account, 'wall.post', array_filter([
                'owner_id' => $ownerId,
                'message' => $text,
                'attachments' => implode(',', $attachments),
            ], fn ($v) => $v !== ''), 'post to the wall');
        } catch (TransientPublishException $e) {
            throw $e; // the job lets the queue retry
        } catch (RuntimeException $e) {
            return PublishResult::failure($e->getMessage());
        }

        $postId = (string) ($response['post_id'] ?? '');
        if ($postId === '') {
            Log::error('[VK] wall.post answered without a post id', ['account_id' => $account->id, 'response' => $response]);

            return PublishResult::failure('VK did not confirm the post. Check the wall before trying again.', $response);
        }

        return PublishResult::success($postId, "https://vk.com/wall{$ownerId}_{$postId}", $response);
    }

    /**
     * Upload one image to the user's wall album and return its attachment id.
     *
     * @throws RuntimeException with a user-facing message
     */
    protected function uploadPhoto(SocialAccount $account, string $url): string
    {
        // Through SafeHttp (fetchMedia): media links come from users. Fetched
        // first so an unreachable link costs no VK calls.
        $file = $this->fetchMedia($url);
        if ($file === null) {
            throw new RuntimeException('Could not download the image for VK: '.$url);
        }

        $server = $this->call($account, 'photos.getWallUploadServer', [], 'prepare a photo upload');
        $uploadUrl = (string) ($server['upload_url'] ?? '');
        if ($uploadUrl === '') {
            Log::error('[VK] photos.getWallUploadServer gave no upload_url', ['account_id' => $account->id, 'response' => $server]);
            throw new RuntimeException('VK did not give an address to upload the image to.');
        }

        $upload = Http::timeout(120)
            ->attach('photo', $file['body'], 'photo.'.$this->imageExtension($file['content_type'] ?? null))
            ->post($uploadUrl);

        $photo = (string) $upload->json('photo');
        if (! $upload->successful() || $photo === '' || $photo === '[]') {
            Log::error('[VK] photo upload refused', [
                'account_id' => $account->id, 'status' => $upload->status(), 'body' => $this->bodyExcerpt($upload),
            ]);
            throw new RuntimeException('VK did not accept the image. VK takes JPG, PNG or GIF up to 50 MB.');
        }

        $saved = $this->call($account, 'photos.saveWallPhoto', [
            'photo' => $photo,
            'server' => $upload->json('server'),
            'hash' => $upload->json('hash'),
        ], 'save the photo');

        $first = $saved[0] ?? null;
        if (! is_array($first) || empty($first['id']) || empty($first['owner_id'])) {
            Log::error('[VK] photos.saveWallPhoto gave no photo', ['account_id' => $account->id, 'response' => $saved]);
            throw new RuntimeException('VK did not save the image.');
        }

        return 'photo'.$first['owner_id'].'_'.$first['id'];
    }

    /**
     * Upload one video to the user's videos (not posted on its own) and
     * return its attachment id. VK processes it after the upload; the wall
     * post shows it once that's done.
     *
     * @throws RuntimeException with a user-facing message
     */
    protected function uploadVideo(SocialAccount $account, string $url): string
    {
        $file = $this->fetchMedia($url, 'video');
        if ($file === null) {
            throw new RuntimeException('Could not download the video for VK: '.$url);
        }

        $saved = $this->call($account, 'video.save', ['wallpost' => 0], 'prepare a video upload');
        $uploadUrl = (string) ($saved['upload_url'] ?? '');
        if ($uploadUrl === '') {
            Log::error('[VK] video.save gave no upload_url', ['account_id' => $account->id, 'response' => $saved]);
            throw new RuntimeException('VK did not give an address to upload the video to.');
        }

        $upload = Http::timeout(600)->attach('video_file', $file['body'], 'video.mp4')->post($uploadUrl);

        $videoId = $upload->json('video_id') ?? null;
        if (! $upload->successful() || empty($videoId)) {
            Log::error('[VK] video upload refused', [
                'account_id' => $account->id, 'status' => $upload->status(), 'body' => $this->bodyExcerpt($upload),
            ]);
            throw new RuntimeException('VK did not accept the video upload (HTTP '.$upload->status().').');
        }

        $ownerId = $upload->json('owner_id') ?? $saved['owner_id'] ?? $account->platform_account_id;

        return 'video'.$ownerId.'_'.$videoId;
    }

    /**
     * One VK API method call. Returns the `response` payload; on an `error`
     * object it throws (see apiError()). A per-second rate limit (code 6) is
     * waited out once before giving up.
     *
     * @throws RuntimeException|TransientPublishException
     */
    protected function call(SocialAccount $account, string $method, array $params, string $action): array
    {
        for ($attempt = 1; ; $attempt++) {
            $response = Http::asForm()->acceptJson()->timeout(60)
                ->withToken((string) $account->access_token)
                ->post(self::API_BASE.'/'.$method, $params + ['v' => $this->apiVersion()]);

            $this->throwIfTransient($response, $action);

            $error = $response->json('error');
            if (is_array($error) && (int) ($error['error_code'] ?? 0) === 6 && $attempt === 1) {
                usleep(400_000);

                continue;
            }

            break;
        }

        if (is_array($error)) {
            $this->apiError($account, $method, $action, (int) ($error['error_code'] ?? 0), (string) ($error['error_msg'] ?? ''));
        }

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! array_key_exists('response', $json)) {
            Log::error("[VK] {$method} gave an unexpected answer", [
                'account_id' => $account->id, 'status' => $response->status(), 'body' => $this->bodyExcerpt($response),
            ]);

            throw new RuntimeException("VK gave an unexpected answer while trying to {$action} (HTTP {$response->status()}).");
        }

        return (array) $json['response'];
    }

    /**
     * Turn a VK API error into the matching user-facing failure. VK's own
     * code and message are always logged.
     *
     * @throws RuntimeException|TransientPublishException
     */
    protected function apiError(SocialAccount $account, string $method, string $action, int $code, string $message): never
    {
        Log::error("[VK] {$method} failed", [
            'account_id' => $account->id, 'error_code' => $code, 'error_msg' => $message,
        ]);

        // Rate limit that outlasted the retry, or VK's own server trouble: the queue retries later.
        if (in_array($code, [1, 6, 10], true)) {
            throw new TransientPublishException($code === 6
                ? 'VK rate limit hit — retrying automatically.'
                : 'VK returned a temporary error — retrying automatically.');
        }

        if ($code === 5) {
            $account->markNeedsReauth('VK authorization expired or was revoked — reconnect the account.');
        }

        throw new RuntimeException(match ($code) {
            5 => 'VK authorization expired or was revoked — reconnect the account.',
            7, 15 => $this->permissionMessage($method),
            9 => 'VK is limiting how often this account can post right now (flood control). Try again later.',
            29 => "VK's daily limit for this action has been reached for this account. Try again tomorrow.",
            14 => 'VK asked for a captcha before accepting this. Open VK, post once by hand, then try again.',
            17 => 'VK needs you to confirm your account. Open VK, follow its prompt, then try again.',
            18 => 'This VK page is deleted or blocked.',
            214 => 'VK does not allow posting on this wall right now (posting is closed or restricted).',
            219, 224 => 'VK refused the post: too many advertising posts were published recently.',
            222 => 'VK refused the post because it contains links, which are not allowed here.',
            default => "VK refused to {$action}: ".($message !== '' ? $message : 'no reason given')." (error {$code}).",
        });
    }

    /** "Permission denied" worded for what was being attempted. */
    protected function permissionMessage(string $method): string
    {
        $what = match (true) {
            str_starts_with($method, 'photos.') => 'upload photos',
            str_starts_with($method, 'video.') => 'upload videos',
            default => 'post on this wall',
        };

        return "VK refused permission to {$what}. Reconnect VK and allow the requested access; "
            .'if it keeps happening, VK has not enabled this for the ViewsMax app yet.';
    }

    /**
     * POST to VK ID's token endpoint. A confidential app sends its service
     * token with every exchange and refresh.
     */
    protected function tokenRequest(array $form): Response
    {
        return Http::asForm()->acceptJson()->post(self::ID_BASE.'/oauth2/auth', $form + [
            'client_id' => $this->clientId(),
            'service_token' => $this->serviceToken(),
        ]);
    }

    /**
     * The signed-in user's VK ID profile ({user_id, first_name, last_name,
     * avatar, ...}); empty when VK doesn't answer, so the connect still works.
     */
    protected function profile(?string $accessToken): array
    {
        $response = Http::asForm()->acceptJson()->post(self::ID_BASE.'/oauth2/user_info', [
            'client_id' => $this->clientId(),
            'access_token' => $accessToken,
        ]);

        if (! $response->successful() || $response->json('error')) {
            Log::warning('[VK] user_info failed', ['status' => $response->status(), 'body' => $this->bodyExcerpt($response)]);

            return [];
        }

        return (array) ($response->json('user') ?? $response->json());
    }

    protected function serviceToken(): string
    {
        $token = $this->config('service_token');
        if (empty($token)) {
            throw new RuntimeException('VK service_token is not configured.');
        }

        return $token;
    }

    protected function apiVersion(): string
    {
        return (string) $this->config('api_version', '5.199');
    }

    protected function imageExtension(?string $contentType): string
    {
        return match (strtolower(trim(explode(';', (string) $contentType)[0]))) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }
}
