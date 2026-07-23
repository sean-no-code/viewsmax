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

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $video = $this->firstVideoUrl($post);
        if (! $video) {
            return PublishResult::failure('YouTube requires a video to publish.');
        }

        $content = trim((string) $post->content);
        $title = mb_substr($content !== '' ? $content : 'New video', 0, 95);

        $metadata = [
            'snippet' => [
                'title' => $title,
                'description' => $content,
                'categoryId' => '22', // People & Blogs
            ],
            'status' => [
                'privacyStatus' => 'private', // safe default; user can flip to public
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        // 1. Initiate a resumable upload session.
        $init = Http::withToken($account->access_token)
            ->withHeaders([
                'X-Upload-Content-Type' => 'video/*',
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata);

        if (! $init->successful()) {
            return PublishResult::failure('YouTube upload init failed: '.$init->body(), $init->json() ?? []);
        }

        $uploadUrl = $init->header('Location');
        if (! $uploadUrl) {
            return PublishResult::failure('YouTube did not return a resumable upload URL.');
        }

        // 2. Stream the source video into the upload session.
        $binary = Http::timeout(600)->get($video);
        if (! $binary->successful()) {
            return PublishResult::failure('Could not download source video for YouTube upload.');
        }

        $upload = Http::withToken($account->access_token)
            ->timeout(600)
            ->withBody($binary->body(), 'video/*')
            ->put($uploadUrl);

        if (! $upload->successful()) {
            return PublishResult::failure('YouTube upload failed: '.$upload->body(), $upload->json() ?? []);
        }

        $videoId = $upload->json('id');

        return PublishResult::success(
            $videoId,
            $videoId ? "https://www.youtube.com/watch?v={$videoId}" : null,
            $upload->json()
        );
    }
}
