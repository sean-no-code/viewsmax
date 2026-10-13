<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pinterest publishing via the v5 API. Every Pin lives on a board and needs an
 * image or a video:
 *  - image: one POST /pins with media_source.image_url
 *  - video: register an upload (POST /media), upload the file to the returned
 *    URL, wait for processing, then POST /pins with the media id and a cover
 *
 * With `sandbox` on, every call goes to api-sandbox.pinterest.com — Pins made
 * there are visible only to their creator (and video Pins aren't supported).
 */
class PinterestProvider extends AbstractSocialProvider
{
    protected string $platform = 'pinterest';

    public const TITLE_LIMIT = 100;

    public const ALT_TEXT_LIMIT = 500;

    protected function apiBase(): string
    {
        // Local testing only: a mock server built from Pinterest's official
        // API spec. Empty in every real environment.
        if ($override = $this->config('api_base')) {
            return rtrim($override, '/');
        }

        return $this->config('sandbox')
            ? 'https://api-sandbox.pinterest.com/v5'
            : 'https://api.pinterest.com/v5';
    }

    protected function api(SocialAccount $account): PendingRequest
    {
        return Http::withToken($account->access_token)->acceptJson();
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return 'https://www.pinterest.com/oauth/?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopeString(','),
            'state' => $state,
        ]);
    }

    /**
     * Pinterest's token endpoint takes the app credentials as HTTP Basic auth,
     * not in the form body.
     */
    protected function tokenRequest(array $form): \Illuminate\Http\Client\Response
    {
        return Http::asForm()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post($this->apiBase().'/oauth/token', $form);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            // A refresh token that renews itself on every refresh, instead of
            // one with a fixed end date.
            'continuous_refresh' => 'true',
        ]);

        if (! $response->successful()) {
            $this->fail('token exchange', $response->status(), $response->body());
        }

        $tokens = OAuthResult::fromArray($response->json());

        $profile = Http::withToken($tokens->accessToken)->acceptJson()
            ->get($this->apiBase().'/user_account')
            ->json() ?? [];

        $username = $profile['username'] ?? null;

        $account = $this->storeAccount($user, [
            'platform_account_id' => $profile['id'] ?? $username,
            'name' => $profile['business_name'] ?? $username,
            'username' => $username,
            'avatar_url' => $profile['profile_image'] ?? null,
            'profile_url' => $username ? 'https://www.pinterest.com/'.$username.'/' : null,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scopes ?: $this->config('scopes', []),
            'metadata' => ['account_type' => $profile['account_type'] ?? null],
        ]);

        return collect([$account]);
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        // Access tokens last 30 days and Pinterest says to refresh them before
        // they expire; the refresh token itself dies if unused for 60 days.
        // Refreshing in the last week (the daily social:refresh-tokens run
        // gets there) keeps an idle account connected indefinitely.
        $stillFresh = fn (SocialAccount $a) => $a->hasValidToken()
            && ! ($a->token_expires_at !== null && $a->token_expires_at->lt(now()->addDays(7)));
        if ($stillFresh($account) || empty($account->refresh_token)) {
            return $account;
        }

        return $this->refreshingSafely($account, isFresh: $stillFresh, refresh: function (SocialAccount $account) {
            $response = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);

            if (! $response->successful()) {
                // An early refresh that fails on a still-valid token must not
                // brick the account; the next daily run tries again.
                if ($this->isDefinitiveAuthFailure($response->status()) && ! $account->hasValidToken()) {
                    $account->markNeedsReauth('Pinterest token refresh was rejected — reconnect the account.');
                } else {
                    Log::warning('Pinterest token refresh failed transiently', [
                        'account_id' => $account->id, 'status' => $response->status(),
                    ]);
                }

                return $account;
            }

            return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
        });
    }

    /**
     * The account's boards ({id, name, privacy}), fetched live every time.
     * Pinterest's developer guidelines forbid storing data read from the API,
     * so the list is never saved.
     *
     * @return array<int, array{id:string, name:string, privacy:?string}>
     */
    public function boards(SocialAccount $account): array
    {
        $boards = [];
        $bookmark = null;

        // 250 is the API's page maximum; 4 pages covers any realistic account.
        for ($page = 0; $page < 4; $page++) {
            $response = $this->api($account)->get($this->apiBase().'/boards', array_filter([
                'page_size' => 250,
                'bookmark' => $bookmark,
            ]));

            if (! $response->successful()) {
                $this->fail('board list', $response->status(), $response->body());
            }

            foreach ((array) $response->json('items', []) as $board) {
                $boards[(string) $board['id']] = [
                    'id' => (string) $board['id'],
                    'name' => (string) ($board['name'] ?? ''),
                    'privacy' => $board['privacy'] ?? null,
                ];
            }

            $bookmark = $response->json('bookmark');
            if (! $bookmark) {
                break;
            }
        }

        return array_values($boards);
    }

    /**
     * Create a public board on the account and return it as {id, name,
     * privacy}. Secret boards would need the extra *_secret permissions.
     * Throws with Pinterest's own message when it refuses (e.g. a duplicate
     * name).
     *
     * @return array{id:string, name:string, privacy:?string}
     */
    public function createBoard(SocialAccount $account, string $name, ?string $description = null): array
    {
        $response = $this->api($account)->post($this->apiBase().'/boards', array_filter([
            'name' => $name,
            'description' => $description,
            'privacy' => 'PUBLIC',
        ], fn ($v) => $v !== null && $v !== ''));

        if (! $response->successful()) {
            Log::warning('[Pinterest] create board failed', [
                'account_id' => $account->id, 'status' => $response->status(), 'body' => $response->body(),
            ]);

            throw new \RuntimeException((string) ($response->json('message') ?: 'HTTP '.$response->status()));
        }

        return [
            'id' => (string) $response->json('id'),
            'name' => (string) $response->json('name', $name),
            'privacy' => $response->json('privacy'),
        ];
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $image = $this->firstImageUrl($post);
        $video = $this->firstVideoUrl($post);
        $log = ['account_id' => $account->id, 'api_base' => $this->apiBase(), 'video' => (bool) $video];

        if (! $image && ! $video) {
            return PublishResult::failure('Pinterest needs an image or a video for every Pin.');
        }

        // Nothing is guessed: the board, title, link and alt text are exactly
        // what the user chose, and an empty field is left out of the Pin.
        $boardId = trim((string) ($post->board_id ?? ''));
        if ($boardId === '') {
            return PublishResult::failure('Choose a Pinterest board for this account.');
        }
        if (! in_array($boardId, array_column($this->boards($account), 'id'), true)) {
            return PublishResult::failure("The chosen Pinterest board isn't on this account anymore. Choose another board.");
        }

        $payload = array_filter([
            'board_id' => $boardId,
            'title' => trim((string) ($post->title ?? '')),
            'description' => trim((string) $post->content),
            'link' => trim((string) ($post->link ?? '')),
            'alt_text' => trim((string) ($post->alt_text ?? '')),
        ], fn ($v) => $v !== '');

        if ($video) {
            $cover = $post->cover_url ?? null;
            if (! $cover) {
                return PublishResult::failure('A Pinterest video Pin needs a cover image. Add one in Platform options → Pinterest.');
            }

            [$mediaId, $error] = $this->uploadVideo($account, $video);
            if ($error) {
                return PublishResult::failure($error);
            }

            $payload['media_source'] = [
                'source_type' => 'video_id',
                'media_id' => $mediaId,
                'cover_image_url' => $cover,
            ];
        } else {
            $payload['media_source'] = ['source_type' => 'image_url', 'url' => $image];
        }

        Log::info('[Pinterest] create pin', $log + ['board_id' => $boardId]);
        $response = $this->api($account)->post($this->apiBase().'/pins', $payload);

        if (! $response->successful()) {
            Log::error('[Pinterest] create pin failed', $log + ['status' => $response->status(), 'body' => $response->body()]);

            return PublishResult::failure('Pinterest Pin creation failed: '.$response->body(), $response->json() ?? []);
        }

        $pinId = (string) $response->json('id');

        return PublishResult::success($pinId, 'https://www.pinterest.com/pin/'.$pinId.'/', $response->json());
    }

    /**
     * Register → upload → wait for processing. Returns [media_id, error].
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function uploadVideo(SocialAccount $account, string $videoUrl): array
    {
        $register = $this->api($account)->post($this->apiBase().'/media', ['media_type' => 'video']);
        if (! $register->successful()) {
            return [null, 'Pinterest video upload could not start: '.$register->body()];
        }

        $mediaId = (string) $register->json('media_id');
        $uploadUrl = (string) $register->json('upload_url');
        $params = (array) $register->json('upload_parameters', []);

        $binary = Http::timeout(120)->get($videoUrl);
        if (! $binary->successful()) {
            return [null, 'Pinterest video upload failed: could not fetch '.$videoUrl];
        }

        // The upload URL is a pre-signed storage form: every parameter goes in
        // as a form field, then the file.
        $upload = Http::timeout(300)->asMultipart()
            ->attach('file', $binary->body(), 'video.mp4')
            ->post($uploadUrl, $params);

        if (! $upload->successful()) {
            return [null, 'Pinterest video upload failed (HTTP '.$upload->status().').'];
        }

        $status = $this->waitForMedia($account, $mediaId);
        if ($status !== 'succeeded') {
            return [null, $status === 'failed'
                ? 'Pinterest could not process the video. Check it meets Pinterest video specs.'
                : 'Pinterest video processing did not complete in time.'];
        }

        return [$mediaId, null];
    }

    /** Poll the upload until it reaches succeeded/failed, or time out. */
    protected function waitForMedia(SocialAccount $account, string $mediaId, int $maxAttempts = 30): string
    {
        $status = 'processing';

        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = (string) $this->api($account)->get($this->apiBase().'/media/'.$mediaId)->json('status');

            if (in_array($status, ['succeeded', 'failed'], true)) {
                return $status;
            }

            sleep(5);
        }

        return $status;
    }
}
