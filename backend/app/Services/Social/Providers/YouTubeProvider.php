<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * YouTube "publishing" = uploading a video. A post must carry a video media
 * item; its content becomes the video title/description.
 */
class YouTubeProvider extends GoogleOAuthProvider
{
    protected string $platform = 'youtube';

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $tokens = $this->exchangeGoogleCode($code, $redirectUri);

        $channel = Http::withToken($tokens->accessToken)
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet',
                'mine' => 'true',
            ])->json('items.0');

        $snippet = $channel['snippet'] ?? [];

        $account = $this->storeAccount($user, [
            'platform_account_id' => $channel['id'] ?? null,
            'name' => $snippet['title'] ?? null,
            'username' => $snippet['customUrl'] ?? null,
            'avatar_url' => data_get($snippet, 'thumbnails.default.url'),
            'profile_url' => isset($channel['id']) ? 'https://www.youtube.com/channel/'.$channel['id'] : null,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scopes,
            'metadata' => ['channel_id' => $channel['id'] ?? null],
        ]);

        return collect([$account]);
    }

    /**
     * Subscriber count via the Data API statistics part (1 quota unit). Uses the
     * account's own OAuth token and stored channel id.
     */
    public function fetchFollowerCount(SocialAccount $account): ?int
    {
        $account = $this->ensureFreshToken($account);
        $channelId = $account->meta('channel_id') ?? $account->platform_account_id;

        $response = Http::withToken($account->access_token)
            ->get('https://www.googleapis.com/youtube/v3/channels', $channelId
                ? ['part' => 'statistics', 'id' => $channelId]
                : ['part' => 'statistics', 'mine' => 'true']);

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::warning('[YouTube] subscriber lookup failed', [
                'account_id' => $account->id, 'status' => $response->status(),
            ]);

            return null;
        }

        $count = $response->json('items.0.statistics.subscriberCount');

        return $count === null ? null : (int) $count;
    }

    /**
     * Per-video engagement via the Data API statistics part (≤50 ids/call,
     * 1 quota unit). Shares aren't exposed by the API, so they stay 0.
     */
    public function fetchPostMetrics(SocialAccount $account, array $remotePostIds): array
    {
        if (empty($remotePostIds)) {
            return [];
        }

        $account = $this->ensureFreshToken($account);

        $response = Http::withToken($account->access_token)
            ->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'statistics',
                'id' => implode(',', array_slice($remotePostIds, 0, 50)),
            ]);

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::warning('[YouTube] video stats failed', [
                'account_id' => $account->id, 'status' => $response->status(),
            ]);

            return [];
        }

        $out = [];
        foreach ($response->json('items') ?? [] as $v) {
            $s = $v['statistics'] ?? [];
            $out[(string) $v['id']] = [
                'likes' => (int) ($s['likeCount'] ?? 0),
                'comments' => (int) ($s['commentCount'] ?? 0),
                'shares' => 0,
                'views' => (int) ($s['viewCount'] ?? 0),
            ];
        }

        return $out;
    }

    /** Why the legacy SocialPost path no longer uploads to YouTube. */
    public const USE_POSTS_API = 'YouTube uploads are no longer made from the social posts API, which had to hold the whole video in memory. Create the post through the posts API (POST /api/posts with an uploaded video) instead.';

    /**
     * Retired. This path downloaded the whole video into worker
     * memory before the PUT; the Post pipeline (PublishYouTubeJob) streams it
     * from the media disk through YouTubePublishService in resumable chunks,
     * and no client of this endpoint sends YouTube targets any more.
     */
    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        return PublishResult::failure(self::USE_POSTS_API);
    }
}
