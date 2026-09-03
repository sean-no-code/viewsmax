<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * TikTok publishing via the Content Posting API (direct post, PULL_FROM_URL).
 * TikTok is media-first: a post must include a video (or photos).
 */
class TikTokProvider extends AbstractSocialProvider
{
    protected string $platform = 'tiktok';

    protected const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key' => $this->clientId(),
            'scope' => $this->scopeString(','),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_key' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            $this->fail('token exchange', $response->status(), $response->body());
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $openId = $data['open_id'] ?? null;

        $profile = Http::withToken($accessToken)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,union_id,avatar_url,display_name',
            ])->json('data.user');

        $account = $this->storeAccount($user, [
            'platform_account_id' => $openId,
            'name' => $profile['display_name'] ?? null,
            'username' => $profile['display_name'] ?? null,
            'avatar_url' => $profile['avatar_url'] ?? null,
            'access_token' => $accessToken,
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
            'scopes' => isset($data['scope']) ? explode(',', $data['scope']) : $this->config('scopes', []),
            'metadata' => [
                'open_id' => $openId,
                'union_id' => $data['union_id'] ?? null,
            ],
        ]);

        return collect([$account]);
    }

    /**
     * Follower count via the user info endpoint. Requires the user.info.stats
     * scope (gated behind stats_enabled — users must reconnect). Null when off
     * or on any failure.
     */
    public function fetchFollowerCount(SocialAccount $account): ?int
    {
        if (! $this->config('stats_enabled')) {
            return null;
        }

        try {
            $account = $this->ensureFreshToken($account);
            $response = Http::withToken($account->access_token)
                ->get('https://open.tiktokapis.com/v2/user/info/', ['fields' => 'follower_count']);

            if (! $response->successful()) {
                \Illuminate\Support\Facades\Log::warning('[TikTok] follower lookup failed', [
                    'account_id' => $account->id, 'status' => $response->status(),
                ]);

                return null;
            }

            $count = $response->json('data.user.follower_count');

            return $count === null ? null : (int) $count;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TikTok] follower lookup errored', [
                'account_id' => $account->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Per-video engagement via the video/query endpoint. Requires the
     * video.list scope (gated behind stats_enabled). Empty when off/on failure.
     */
    public function fetchPostMetrics(SocialAccount $account, array $remotePostIds): array
    {
        if (! $this->config('stats_enabled') || empty($remotePostIds)) {
            return [];
        }

        try {
            $account = $this->ensureFreshToken($account);
            $response = Http::withToken($account->access_token)
                ->post('https://open.tiktokapis.com/v2/video/query/?fields=id,like_count,comment_count,share_count,view_count', [
                    'filters' => ['video_ids' => array_values(array_slice($remotePostIds, 0, 20))],
                ]);

            if (! $response->successful()) {
                \Illuminate\Support\Facades\Log::warning('[TikTok] video metrics failed', [
                    'account_id' => $account->id, 'status' => $response->status(),
                ]);

                return [];
            }

            $out = [];
            foreach ($response->json('data.videos') ?? [] as $v) {
                $out[(string) $v['id']] = [
                    'likes' => (int) ($v['like_count'] ?? 0),
                    'comments' => (int) ($v['comment_count'] ?? 0),
                    'shares' => (int) ($v['share_count'] ?? 0),
                    'views' => (int) ($v['view_count'] ?? 0),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TikTok] video metrics errored', [
                'account_id' => $account->id, 'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        if ($account->hasValidToken() || empty($account->refresh_token)) {
            return $account;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_key' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if (! $response->successful()) {
            $account->markNeedsReauth('TikTok token refresh failed.');

            return $account;
        }

        return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $video = $this->firstVideoUrl($post);
        $images = $this->mediaItems($post, 'image');
        $caption = (string) $post->content;

        if ($video) {
            return $this->publishVideo($account, $video, $caption);
        }

        if (! empty($images)) {
            return $this->publishPhotos($account, $images, $caption);
        }

        return PublishResult::failure('TikTok requires a video or photos to publish.');
    }

    protected function publishVideo(SocialAccount $account, string $videoUrl, string $caption): PublishResult
    {
        $response = Http::withToken($account->access_token)
            ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                'post_info' => [
                    'title' => mb_substr($caption, 0, 2200),
                    'privacy_level' => 'SELF_ONLY',
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $videoUrl,
                ],
            ]);

        if (! $response->successful() || $response->json('error.code', 'ok') !== 'ok') {
            return PublishResult::failure('TikTok video publish failed: '.$response->body(), $response->json() ?? []);
        }

        $publishId = $response->json('data.publish_id');

        return PublishResult::success($publishId, null, $response->json());
    }

    protected function publishPhotos(SocialAccount $account, array $images, string $caption): PublishResult
    {
        $response = Http::withToken($account->access_token)
            ->post('https://open.tiktokapis.com/v2/post/publish/content/init/', [
                'post_info' => [
                    'title' => mb_substr($caption, 0, 90),
                    'description' => mb_substr($caption, 0, 2200),
                    'privacy_level' => 'SELF_ONLY',
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'photo_cover_index' => 0,
                    'photo_images' => array_map(fn ($i) => $i['url'], $images),
                ],
                'post_mode' => 'DIRECT_POST',
                'media_type' => 'PHOTO',
            ]);

        if (! $response->successful() || $response->json('error.code', 'ok') !== 'ok') {
            return PublishResult::failure('TikTok photo publish failed: '.$response->body(), $response->json() ?? []);
        }

        $publishId = $response->json('data.publish_id');

        return PublishResult::success($publishId, null, $response->json());
    }
}
