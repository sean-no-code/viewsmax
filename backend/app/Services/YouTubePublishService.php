<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /** The Google scope a token must carry to upload; the Analytics connect doesn't ask for it. */
    public const UPLOAD_SCOPE = 'https://www.googleapis.com/auth/youtube.upload';

    public const RECONNECT_FOR_UPLOAD = 'Reconnect YouTube from the Connections page to grant video upload permission, then try again.';

    /**
     * Turn a 401/403 from the upload endpoint into advice the user can act on.
     * A dead token, a grant without the upload scope and an exhausted API
     * quota all arrive here; "reconnect" is wrong advice for two of them.
     *
     * @param  array<string, mixed>  $error  Google's `error` object, possibly empty
     */
    public static function refusalMessage(int $status, array $error): string
    {
        $reason = (string) data_get($error, 'errors.0.reason', '');
        $message = (string) ($error['message'] ?? '');

        Log::warning('YouTube refused the upload', ['status' => $status, 'reason' => $reason, 'message' => $message]);

        if ($status === 401) {
            return 'Your YouTube session expired or was revoked. Reconnect YouTube from the Connections page, then try again.';
        }

        return match (true) {
            in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true)
                => "YouTube's daily API quota is used up, so the upload was refused. It resets at midnight Pacific time; try again after that.",
            $reason === 'uploadLimitExceeded'
                => "This channel has reached YouTube's upload limit for today. Try again tomorrow.",
            $reason === 'youtubeSignupRequired'
                => 'This Google account has no YouTube channel. Create one on YouTube, then reconnect.',
            $reason === 'insufficientPermissions' || str_contains(strtolower($message), 'insufficient')
                => self::RECONNECT_FOR_UPLOAD,
            default => self::RECONNECT_FOR_UPLOAD.($reason !== '' ? " (YouTube said: {$reason}.)" : ''),
        };
    }

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

        // A dead token, a missing upload scope or an exhausted quota all surface
        // here as 401/403 — name the one it was so the advice is right.
        if (in_array($init->status(), [401, 403], true)) {
            throw new RuntimeException(self::refusalMessage($init->status(), (array) ($init->json('error') ?? [])));
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
