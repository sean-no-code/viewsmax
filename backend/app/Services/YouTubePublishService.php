<?php

namespace App\Services;

use App\Exceptions\MediaReadException;
use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use App\Support\BoundedMediaStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Token refresh + resumable video upload for the posting pipeline's YouTube
 * targets. Multi-account: tokens live per channel in the `social_accounts`
 * store, refreshed via the YouTube provider's Google ensureFreshToken.
 */
class YouTubePublishService
{
    private const PRIVACY = ['public', 'unlisted', 'private'];

    /** Bytes per resumable-upload PUT (Google wants a multiple of 256 KiB). */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * Seconds one chunk PUT (or the session probe) may take. Kept far below
     * PublishYouTubeJob::$timeout so a stalled transfer surfaces as a
     * retryable error that resumes, not as a killed job.
     */
    private const CHUNK_TIMEOUT = 120;

    /** The Google scope a token must carry to upload; the Analytics connect doesn't ask for it. */
    public const UPLOAD_SCOPE = 'https://www.googleapis.com/auth/youtube.upload';

    public const RECONNECT_FOR_UPLOAD = 'Reconnect YouTube from the Connections page to grant video upload permission, then try again.';

    /** A dead token (401 / UNAUTHENTICATED / invalid_grant): the account needs reconnecting. */
    public const SESSION_EXPIRED = 'Your YouTube session expired or was revoked. Reconnect YouTube from the Connections page, then try again.';

    /**
     * Advice for a failed Data API call. Google doesn't keep refusals to
     * 401/403: a channel over its own upload cap comes back as a 400
     * `uploadLimitExceeded`, a bad title as a 400 `invalidTitle`.
     *
     * @param  string  $what  what was being sent ("upload", "thumbnail"), for the fallback wording
     */
    public static function failureMessage(Response $response, string $what = 'upload'): string
    {
        return self::refusalMessage($response->status(), (array) ($response->json('error') ?? []), $what);
    }

    /**
     * Turn a refusal from the Data API into advice the user can act on. A dead
     * token, a grant without the upload scope, an exhausted API quota and a
     * title YouTube won't take all arrive here; "reconnect" is wrong advice
     * for most of them.
     *
     * @param  array<string, mixed>  $error  Google's `error` object, possibly empty
     */
    public static function refusalMessage(int $status, array $error, string $what = 'upload'): string
    {
        $reason = (string) data_get($error, 'errors.0.reason', '');
        $domain = (string) data_get($error, 'errors.0.domain', '');
        $message = (string) ($error['message'] ?? '');

        Log::warning("YouTube refused the {$what}", ['status' => $status, 'reason' => $reason, 'message' => $message]);

        if (self::isAuthFailure($status, $error)) {
            return self::SESSION_EXPIRED;
        }

        return match (true) {
            in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)
                => "YouTube's daily API quota is used up, so the upload was refused. It resets at midnight Pacific time; try again after that.",
            in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)
                => 'YouTube is receiving requests from this channel too quickly. Wait a little while, then try again.',
            $reason === 'uploadLimitExceeded'
                => "This channel has reached YouTube's upload limit for today. Try again tomorrow.",
            $reason === 'youtubeSignupRequired'
                => 'This Google account has no YouTube channel. Create one on YouTube, then reconnect.',
            $reason === 'insufficientPermissions' || str_contains(strtolower($message), 'insufficient')
                => self::RECONNECT_FOR_UPLOAD,
            $reason === 'invalidTags'
                => 'YouTube rejected the video tags: at most 500 characters in total, with no < or > characters.',
            $reason === 'invalidTitle'
                => 'YouTube rejected the video title: it must be 1-100 characters with no < or > characters.',
            $reason === 'invalidDescription'
                => 'YouTube rejected the video description: it may contain disallowed characters such as < or >.',
            $reason === 'invalidCategoryId'
                => 'YouTube rejected the video category.',
            $reason === 'mediaBodyRequired'
                => 'YouTube did not receive the video file. Re-upload the video, then try again.',
            $reason === 'imageFormatUnsupported'
                => 'YouTube does not support the thumbnail format; use a JPEG or PNG image.',
            $reason === 'imageTooTall'
                => 'The thumbnail image is too tall for YouTube.',
            $reason === 'imageTooWide'
                => 'The thumbnail image is too wide for YouTube.',
            $reason === 'failedPrecondition'
                => 'The thumbnail is too large for YouTube (2 MB maximum).',
            $domain === 'youtube.thumbnail'
                => 'This channel is not verified, so YouTube does not allow custom thumbnails. Verify the channel on YouTube, then try again.',
            in_array($status, [401, 403], true)
                => self::RECONNECT_FOR_UPLOAD.($reason !== '' ? " (YouTube said: {$reason}.)" : ''),
            default => "YouTube rejected the {$what}: ".($message !== '' ? $message : "HTTP {$status}"),
        };
    }

    /**
     * Whether the Data API said the token itself is dead (Unauthorized,
     * UNAUTHENTICATED, invalid_grant). Scope and quota refusals are 403s with
     * their own reasons and are not this.
     *
     * @param  array<string, mixed>  $error
     */
    public static function isAuthFailure(int $status, array $error): bool
    {
        $message = strtolower((string) ($error['message'] ?? ''));

        return $status === 401
            || ($error['status'] ?? null) === 'UNAUTHENTICATED'
            || data_get($error, 'errors.0.reason') === 'authError'
            || str_contains($message, 'invalid_grant')
            || str_contains($message, 'unauthorized');
    }

    /**
     * Publish-time counterpart of AbstractSocialProvider::failReauthIfUnauthorized
     * for the two YouTube publish paths, which only hold the thrown error by
     * the time the account is at hand: flag the account for reconnect when the
     * upload failed on a dead token, so it doesn't keep showing "Connected".
     */
    public static function flagReauthIfExpired(SocialAccount $account, Throwable $e): bool
    {
        if ($e->getMessage() !== self::SESSION_EXPIRED) {
            return false;
        }

        Log::warning('[youtube] upload rejected the token; account needs reconnect', ['account_id' => $account->id]);
        $account->markNeedsReauth(self::SESSION_EXPIRED);

        return true;
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
     * Upload a video via YouTube's resumable upload endpoint in 8 MB chunks,
     * streaming the bytes from the given stream so large files don't load
     * into memory. The session is probed and resumed:
     * a previous attempt's session URI ($resumeUrl) is asked what it already
     * holds before a single byte is re-sent, so a worker that died after
     * Google received the last byte never uploads the video twice.
     *
     * @param  resource  $videoStream  positioned at byte 0
     * @param  callable(string, ?string): void|null  $onStep  told the step name ("session-init", "probe", "upload") before each call so the job can persist it; the "upload" step also carries the session URI to persist for a resume
     * @param  string|null  $resumeUrl  the session URI a previous attempt persisted, if any
     * @return array{video_id: ?string, video_url: ?string, response: mixed}
     *
     * @throws TransientPublishException on a 429/5xx or a dropped connection mid-upload: the session stays resumable, so the job retries and resumes from the committed offset
     */
    public function uploadVideo(string $accessToken, $videoStream, int $size, array $snippet, string $privacyStatus, ?callable $onStep = null, ?string $resumeUrl = null): array
    {
        if (! in_array($privacyStatus, self::PRIVACY, true)) {
            $privacyStatus = 'private';
        }
        if ($size <= 0) {
            throw new MediaReadException('file is empty');
        }
        $step = $onStep ?? fn () => null;

        $uploadUrl = null;
        $offset = 0;
        if ($resumeUrl) {
            $step('probe');
            $probe = $this->probeSession($accessToken, $resumeUrl, $size);
            if ($probe instanceof Response) {
                return self::result($probe); // the last attempt finished the upload before it died
            }
            if ($probe !== null) {
                [$uploadUrl, $offset] = [$resumeUrl, $probe];
            }
        }

        if ($uploadUrl === null) {
            $step('session-init');
            $uploadUrl = $this->openSession($accessToken, $size, [
                'snippet' => $snippet,
                'status' => [
                    'privacyStatus' => $privacyStatus,
                    'selfDeclaredMadeForKids' => false,
                ],
            ]);
        }

        $step('upload', $uploadUrl);

        return self::result($this->uploadChunks($accessToken, $uploadUrl, $videoStream, $size, $offset));
    }

    /**
     * Open a resumable upload session and return its URI. Opening one is
     * idempotent, so a connection error, 429 or 5xx is retried a few times
     * with a short pause; a 4xx is Google's real answer
     * (dead token, quota, missing scope, bad title) and is never retried. A
     * 429/5xx that outlives the retries is handed to the queue as transient.
     */
    private function openSession(string $accessToken, int $size, array $metadata): string
    {
        $init = Http::retry(3, 5000, fn (Throwable $e) => self::isTransient($e), throw: false)
            ->withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Type' => 'video/*',
                'X-Upload-Content-Length' => (string) $size,
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata);

        if (self::isTransientStatus($init->status())) {
            throw self::transient($init->status(), 'opening the upload session');
        }
        if (! $init->successful()) {
            throw new RuntimeException(self::failureMessage($init));
        }

        $uploadUrl = $init->header('Location');
        if (! $uploadUrl) {
            throw new RuntimeException('YouTube did not return a resumable upload URL.');
        }

        return $uploadUrl;
    }

    /**
     * Ask a stored session for the truth before sending anything: the
     * created video when the last byte already
     * arrived, the committed offset to resume from, or null when the session
     * is gone (expired, unknown) and a new one must be opened.
     *
     * @return Response|int|null  the final 200/201 response, the resume offset, or null
     */
    private function probeSession(string $accessToken, string $uploadUrl, int $size): Response|int|null
    {
        try {
            $probe = Http::withToken($accessToken)
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::CHUNK_TIMEOUT)
                ->withHeaders(['Content-Range' => "bytes */{$size}"])
                ->withBody('', 'video/*')
                ->put($uploadUrl);
        } catch (ConnectionException) {
            throw new TransientPublishException('The connection to YouTube dropped while checking the upload session; retrying automatically.');
        }

        if ($probe->successful()) {
            return $probe;
        }
        if ($probe->status() === 308) {
            return self::committedOffset($probe);
        }
        if (self::isTransientStatus($probe->status())) {
            throw self::transient($probe->status(), 'checking the upload session');
        }
        if (self::isAuthFailure($probe->status(), (array) ($probe->json('error') ?? []))) {
            throw new RuntimeException(self::SESSION_EXPIRED);
        }

        // 404/410: the session expired. Anything else unexpected: don't guess,
        // a fresh session costs nothing (no video exists until the last byte).
        Log::warning('YouTube upload session is gone; opening a new one', ['status' => $probe->status()]);

        return null;
    }

    /**
     * Send the bytes from $offset in 8 MB chunks until Google returns the
     * video. 308 = more expected, resume from the offset the session reports;
     * 429/5xx or a dropped connection = the session is still resumable, so the
     * queue retries and the next attempt resumes from the committed offset.
     *
     * @param  resource  $videoStream
     */
    private function uploadChunks(string $accessToken, string $uploadUrl, $videoStream, int $size, int $offset): Response
    {
        // One bounded stream for the whole file: a stalled storage read or a
        // file shorter than $size throws MediaReadException (a re-upload
        // message) out of the PUT instead of running the job into its timeout.
        $bounded = new BoundedMediaStream($videoStream, $size);

        while (true) {
            $end = min($offset + self::CHUNK_BYTES, $size) - 1;

            try {
                $upload = Http::withToken($accessToken)
                    ->withOptions(['allow_redirects' => false])
                    ->timeout(self::CHUNK_TIMEOUT)
                    ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$size}"])
                    ->withBody($this->chunk($bounded, $offset, $end - $offset + 1), 'video/*')
                    ->put($uploadUrl);
            } catch (ConnectionException) {
                throw new TransientPublishException("The connection to YouTube dropped at byte {$offset} of {$size}; the upload resumes on the next attempt.");
            }

            if ($upload->status() === 308) {
                $committed = self::committedOffset($upload);
                if ($committed <= $offset) {
                    // No progress reported for the bytes just sent: let the
                    // next attempt probe rather than loop on the same chunk.
                    throw new TransientPublishException("YouTube did not confirm the bytes from {$offset}; the upload resumes on the next attempt.");
                }
                $offset = $committed;

                continue;
            }
            if ($upload->successful()) {
                return $upload;
            }
            if (self::isTransientStatus($upload->status())) {
                throw self::transient($upload->status(), "uploading the video (byte {$offset} of {$size})");
            }

            throw new RuntimeException(self::failureMessage($upload));
        }
    }

    /**
     * The bytes [$start, $start + $length) of the file, read through the
     * bounded stream so the stall and short-file protection still applies
     * (an 8 MB chunk is a bounded buffer, unlike the whole video). A seekable
     * handle is positioned directly; a one-way stream is read forward to the
     * offset and cannot go backwards, so a resume behind its position is
     * handed to the next attempt, which opens the file afresh.
     */
    private function chunk(BoundedMediaStream $bounded, int $start, int $length): string
    {
        $current = $bounded->tell();
        if ($current > $start && ! $bounded->isSeekable()) {
            throw new TransientPublishException("YouTube committed fewer bytes than were sent (byte {$start}); the upload resumes on the next attempt.");
        }
        if ($current !== $start && $bounded->isSeekable()) {
            $bounded->seek($start);
        }
        while ($bounded->tell() < $start) {
            $bounded->read(min(1024 * 1024, $start - $bounded->tell()));
        }

        $bytes = '';
        while (strlen($bytes) < $length) {
            $bytes .= $bounded->read(min(1024 * 1024, $length - strlen($bytes)));
        }

        return $bytes;
    }

    /**
     * Bytes Google has committed so far, from a 308's `Range: bytes=0-N`
     * header. No Range header means nothing was received yet.
     */
    private static function committedOffset(Response $response): int
    {
        return preg_match('/-(\d+)\s*$/', (string) $response->header('Range'), $m) ? (int) $m[1] + 1 : 0;
    }

    /** @return array{video_id: ?string, video_url: ?string, response: mixed} */
    private static function result(Response $upload): array
    {
        $videoId = $upload->json('id');

        return [
            'video_id' => $videoId,
            'video_url' => $videoId ? "https://www.youtube.com/watch?v={$videoId}" : null,
            'response' => $upload->json(),
        ];
    }

    private static function isTransientStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private static function transient(int $status, string $action): TransientPublishException
    {
        Log::warning('YouTube answered transiently; queue will retry', ['status' => $status, 'action' => $action]);

        return new TransientPublishException($status === 429
            ? "YouTube rate limit hit while {$action}; retrying automatically."
            : "YouTube returned a temporary error while {$action} (HTTP {$status}); retrying automatically.");
    }

    private static function isTransient(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && self::isTransientStatus($e->response->status()));
    }

    /**
     * Set a custom thumbnail on an already-uploaded video via thumbnails.set.
     * Streams the image bytes so large covers don't buffer into memory. Throws
     * with the mapped reason so the caller can decide whether it's fatal (it
     * isn't — the video is already live, so the job stores it as a warning).
     *
     * @param  resource  $imageStream
     */
    public function setThumbnail(string $accessToken, string $videoId, $imageStream, string $mime = 'image/jpeg'): void
    {
        $response = Http::withToken($accessToken)
            ->withBody($imageStream, $mime)
            ->post("https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId={$videoId}&uploadType=media");

        if (! $response->successful()) {
            throw new RuntimeException(self::failureMessage($response, 'thumbnail'));
        }
    }
}
