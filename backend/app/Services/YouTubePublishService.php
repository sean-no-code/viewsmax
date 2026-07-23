<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Token refresh + resumable video upload for the legacy posting pipeline's
 * YouTube targets. Multi-account: tokens now live per channel in the
 * `social_accounts` store, refreshed via the YouTube provider's Google
 * ensureFreshToken (was the single-connection youtube row).
 */
class YouTubePublishService
{
    private const PRIVACY = ['public', 'unlisted', 'private'];

    /**
     * Return a usable access token for the account, refreshing via Google if the
     * stored one is expired. The provider persists the refreshed token.
     */
    public function freshAccessToken(SocialAccount $account): string
    {
        $fresh = app(SocialProviderManager::class)->for('youtube')->ensureFreshToken($account);
        if (! $fresh->access_token) {
            throw new RuntimeException('Your YouTube session expired. Reconnect YouTube to keep posting.');
        }

        return $fresh->access_token;
    }

    /**
     * Upload a video via YouTube's resumable upload endpoint, streaming the
     * bytes from the given stream so large files don't load into memory.
     *
     * @param  resource  $videoStream
     * @return array{video_id: ?string, video_url: ?string, response: mixed}
     */
    public function uploadVideo(string $accessToken, $videoStream, int $size, array $snippet, string $privacyStatus): array
    {
        if (! in_array($privacyStatus, self::PRIVACY, true)) {
            $privacyStatus = 'public';
        }

        $metadata = [
            'snippet' => $snippet,
            'status' => [
                'privacyStatus' => $privacyStatus,
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        // 1. Open a resumable upload session.
        $init = Http::withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Type' => 'video/*',
                'X-Upload-Content-Length' => (string) $size,
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata);

        // A missing upload scope surfaces here as 401/403 — make it actionable.
        if (in_array($init->status(), [401, 403], true)) {
            throw new RuntimeException('Reconnect YouTube to grant video upload permission, then try again.');
        }
        if (! $init->successful()) {
            throw new RuntimeException('YouTube upload init failed: '.$init->body());
        }

        $uploadUrl = $init->header('Location');
        if (! $uploadUrl) {
            throw new RuntimeException('YouTube did not return a resumable upload URL.');
        }

        // 2. Stream the video bytes into the session in a single PUT.
        $upload = Http::withToken($accessToken)
            ->timeout(600)
            ->withBody($videoStream, 'video/*')
            ->put($uploadUrl);

        if (! $upload->successful()) {
            throw new RuntimeException('YouTube upload failed: '.$upload->body());
        }

        $videoId = $upload->json('id');

        return [
            'video_id' => $videoId,
            'video_url' => $videoId ? "https://www.youtube.com/watch?v={$videoId}" : null,
            'response' => $upload->json(),
        ];
    }

    /**
     * Set a custom thumbnail on an already-uploaded video via thumbnails.set.
     * Streams the image bytes so large covers don't buffer into memory. Throws
     * on failure so the caller can decide whether it's fatal (it isn't — the
     * video is already live, so a rejected thumbnail is logged, not fatal).
     *
     * @param  resource  $imageStream
     */
    public function setThumbnail(string $accessToken, string $videoId, $imageStream, string $mime = 'image/jpeg'): void
    {
        $response = Http::withToken($accessToken)
            ->withBody($imageStream, $mime)
            ->post("https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId={$videoId}&uploadType=media");

        if (! $response->successful()) {
            throw new RuntimeException('YouTube thumbnail set failed: '.$response->body());
        }
    }
}
