<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * Latest videos via video/list, newest first. Requires the video.list scope
     * (gated behind stats_enabled, like fetchPostMetrics). Empty when off/on failure.
     */
    public function fetchRecentPosts(SocialAccount $account, int $limit): array
    {
        if (! $this->config('stats_enabled')) {
            return [];
        }

        try {
            $account = $this->ensureFreshToken($account);
            $response = Http::withToken($account->access_token)
                ->post('https://open.tiktokapis.com/v2/video/list/?fields=id,title,video_description,create_time,share_url', [
                    'max_count' => min(max($limit, 1), 20),
                ]);

            if (! $response->successful()) {
                \Illuminate\Support\Facades\Log::warning('[TikTok] recent videos lookup failed', [
                    'account_id' => $account->id, 'status' => $response->status(),
                ]);

                return [];
            }

            $out = [];
            foreach ($response->json('data.videos') ?? [] as $v) {
                $id = (string) ($v['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[] = [
                    'remote_post_id' => $id,
                    'url' => $v['share_url'] ?? null,
                    'caption' => (string) (($v['title'] ?? '') !== '' ? $v['title'] : ($v['video_description'] ?? '')),
                    'published_at' => ! empty($v['create_time']) ? Carbon::createFromTimestamp((int) $v['create_time']) : null,
                ];
            }

            return array_slice($out, 0, $limit);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TikTok] recent videos lookup errored', [
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

        return $this->refreshingSafely($account, function (SocialAccount $account) {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_key' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);

            if (! $response->successful()) {
                // Only a definitive rejection means the grant is dead; a 429/5xx
                // must not brick the account (the daily refresh retries tomorrow).
                if ($this->isDefinitiveAuthFailure($response->status())) {
                    $account->markNeedsReauth('TikTok token refresh was rejected — reconnect the account.');
                } else {
                    Log::warning('TikTok token refresh failed transiently', [
                        'account_id' => $account->id, 'status' => $response->status(),
                    ]);
                }

                return $account;
            }

            return $this->applyTokens($account, OAuthResult::fromArray($response->json()));
        });
    }

    /** Where TikTok publishing lives now; see publish(). */
    public const USE_POSTS_API = 'TikTok posts go through the posts API (POST /api/posts): it serves the media from our verified domain and carries your privacy and creator settings, which this legacy endpoint cannot.';

    /**
     * The legacy SocialPost path cannot publish to TikTok correctly: its media
     * is a bare URL (TikTok only pulls from our verified domain, via a signed
     * MediaProxy link that needs a storage path the legacy post never stored)
     * and it carries no privacy or creator-info choice (it hard-coded
     * SELF_ONLY). Rather than half-work, point the caller at the posts API,
     * where PublishToTikTokJob + TikTokPublishService do it properly.
     */
    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        return PublishResult::failure(self::USE_POSTS_API);
    }
}
