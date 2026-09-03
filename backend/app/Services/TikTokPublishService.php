<?php

namespace App\Services;

use App\Exceptions\TikTokCreatorUnavailableException;
use App\Exceptions\TikTokPublishException;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TikTok Content Posting API client.
 * https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 *
 * Comments here cite the Content Sharing Guidelines by TikTok's own section
 * headings — "Required UX n(x)", "Technical Consideration n(x)", "Intended Use
 * n" — because their numbering restarts in each section, so a bare "2(b)" is
 * ambiguous. https://developers.tiktok.com/doc/content-sharing-guidelines/
 *
 * Flow: creatorInfo() (allowed privacy/interaction options) -> initDirectPost()
 * (PULL_FROM_URL) -> fetchStatus() polled until PUBLISH_COMPLETE.
 *
 * Note: while the app is unaudited, TikTok only accepts privacy_level SELF_ONLY
 * and posts to the developer's own authorized accounts.
 */
class TikTokPublishService
{
    private const BASE = 'https://open.tiktokapis.com/v2';

    // TikTok FILE_UPLOAD chunk bounds.
    private const MAX_CHUNK = 64 * 1024 * 1024; // 64 MB (TikTok max per chunk)

    // We upload in modest pieces rather than buffering the whole video in one
    // chunk: each chunk is read fully into a PHP string (plus Guzzle's copy), so
    // a single large chunk would exhaust the worker's memory_limit. 8 MB stays
    // well within TikTok's 5–64 MB per-chunk range while keeping memory bounded.
    private const UPLOAD_CHUNK = 8 * 1024 * 1024; // 8 MB

    public function __construct(private SocialProviderManager $providers) {}

    /**
     * A live access token for the account, refreshing it first if needed. Multi-
     * account: TikTok tokens now live in the `social_accounts` store, refreshed
     * via the provider's ensureFreshToken (was the single-connection OAuth service).
     */
    private function freshToken(SocialAccount $account): string
    {
        $fresh = $this->providers->for('tiktok')->ensureFreshToken($account);
        if (! $fresh->access_token) {
            throw new Exception('No access token available for TikTok account.');
        }

        return $fresh->access_token;
    }

    /**
     * Truncate to a maximum number of UTF-16 code units — the unit TikTok's
     * title/description limits are measured in. mb_substr counts code points,
     * so emoji (2 UTF-16 units each) pushed titles over the limit and TikTok
     * rejected the post with invalid_params ("post info is empty or incorrect").
     */
    public static function utf16Truncate(string $text, int $maxUnits): string
    {
        $out = '';
        $units = 0;
        foreach (mb_str_split($text) as $char) {
            $units += mb_ord($char) > 0xFFFF ? 2 : 1;
            if ($units > $maxUnits) {
                break;
            }
            $out .= $char;
        }

        return $out;
    }

    /**
     * Photo posts carry a short single-line title (≤90 UTF-16 units): take the
     * caption's first line and collapse stray whitespace. The full caption
     * still travels in `description`.
     */
    private static function photoTitle(string $caption): string
    {
        $firstLine = preg_split('/\R/u', $caption)[0] ?? '';

        return self::utf16Truncate(trim(preg_replace('/\s+/u', ' ', $firstLine) ?? ''), 90);
    }

    /**
     * Query the creator's allowed posting options. Required by TikTok before a
     * post: the composer must render privacy/interaction choices from this.
     */
    public function creatorInfo(SocialAccount $account): array
    {
        $token = $this->freshToken($account);

        // TikTok requires a JSON *object* body. Passing no data makes Laravel
        // serialize an empty array `[]`, which TikTok rejects as
        // "The request parameter type is incorrect" — send an explicit `{}`.
        $response = Http::withToken($token)
            ->withBody('{}', 'application/json; charset=UTF-8')
            ->post(self::BASE.'/post/publish/creator_info/query/');

        // Required UX 1(b). TikTok's Query Creator Info reference is explicit: "You can decide
        // whether the request is successful based on the error code. Any code
        // other than `ok` indicates the request did not succeed." Three
        // creator-cannot-post codes arrive under a status their own table labels
        // "200 (intentional)", so checking the HTTP status alone lets them
        // through as success and the creator is never told to retry.
        $code = $response->json('error.code');

        if (in_array($code, TikTokCreatorUnavailableException::CODES, true)) {
            Log::warning('TikTok creator cannot post right now', ['code' => $code, 'response' => $response->body()]);
            throw new TikTokCreatorUnavailableException($code, self::cannotPostMessage($code));
        }

        if (! $response->successful() || ($code !== null && $code !== 'ok')) {
            Log::error('TikTok creator_info failed', ['code' => $code, 'response' => $response->body()]);
            throw new Exception('Failed to fetch TikTok creator info.');
        }

        $data = $response->json('data', []);

        return [
            'creator_nickname' => $data['creator_nickname'] ?? null,
            'creator_username' => $data['creator_username'] ?? null,
            'creator_avatar_url' => $data['creator_avatar_url'] ?? null,
            'privacy_level_options' => $data['privacy_level_options'] ?? [],
            'comment_disabled' => (bool) ($data['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($data['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($data['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => $data['max_video_post_duration_sec'] ?? 0,
        ];
    }

    /**
     * Initialize a direct video post that TikTok pulls from a public URL.
     * Returns the publish_id used to poll status.
     *
     * @param  array  $options  privacy_level + disable_comment/duet/stitch
     */
    public function initDirectPost(SocialAccount $account, string $caption, string $videoUrl, array $options): string
    {
        $token = $this->freshToken($account);

        $postInfo = [
            'title' => self::utf16Truncate($caption ?? '', 2200),
            'privacy_level' => $options['privacy_level'] ?? 'SELF_ONLY',
            'disable_comment' => (bool) ($options['disable_comment'] ?? false),
            'disable_duet' => (bool) ($options['disable_duet'] ?? false),
            'disable_stitch' => (bool) ($options['disable_stitch'] ?? false),
            // Commercial-content disclosure: your_brand -> Brand Organic,
            // branded_content -> Branded Content (paid partnership). TikTok
            // requires these to reflect the user's disclosure choice.
            'brand_organic_toggle' => (bool) ($options['your_brand'] ?? false),
            'brand_content_toggle' => (bool) ($options['branded_content'] ?? false),
        ];
        // Custom cover frame (ms into the video) when the composer set one.
        if (! empty($options['video_cover_timestamp_ms'])) {
            $postInfo['video_cover_timestamp_ms'] = (int) $options['video_cover_timestamp_ms'];
        }

        $response = Http::withToken($token)
            ->contentType('application/json; charset=UTF-8')
            ->post(self::BASE.'/post/publish/video/init/', [
                'post_info' => $postInfo,
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $videoUrl,
                ],
            ]);

        if (! $response->successful()) {
            Log::error('TikTok video init failed', ['response' => $response->body()]);
            throw new Exception('TikTok rejected the post: '.$response->body());
        }

        $publishId = $response->json('data.publish_id');
        if (! $publishId) {
            throw new Exception('TikTok did not return a publish_id.');
        }

        return $publishId;
    }

    /**
     * Publish a video by uploading the bytes directly to TikTok (FILE_UPLOAD).
     * Unlike PULL_FROM_URL this needs no public / domain-verified URL, so it
     * works from local storage and behind tunnels (ngrok).
     *
     * Tries Direct Post first (honours the privacy / interaction options). If
     * the app is unaudited and the account is public, TikTok refuses the direct
     * post — we then fall back to Upload-to-Inbox so the creator can finish
     * publishing inside the TikTok app.
     *
     * @param  resource  $stream  readable stream of the video file
     * @return array{publish_id: string, mode: string, log: array}  mode = direct|inbox
     */
    public function publishVideoFromStream(SocialAccount $account, string $caption, $stream, int $videoSize, array $options): array
    {
        if ($videoSize <= 0) {
            throw new Exception('The video file is empty.');
        }

        // Append-only audit trail of every TikTok call, persisted to the post
        // target's meta so failures can be reviewed in the admin monitor.
        $log = [];

        // Split into fixed UPLOAD_CHUNK pieces (the final chunk carries the
        // remainder, per TikTok's spec). Videos smaller than one chunk upload
        // whole. This keeps per-chunk memory bounded regardless of video size.
        $chunkSize = (int) min(self::UPLOAD_CHUNK, $videoSize);
        $totalChunks = (int) max(1, intdiv($videoSize, $chunkSize));

        $token = $this->freshToken($account);
        $sourceInfo = [
            'source' => 'FILE_UPLOAD',
            'video_size' => $videoSize,
            'chunk_size' => $chunkSize,
            'total_chunk_count' => $totalChunks,
        ];

        // 1) Direct Post.
        $mode = 'direct';
        $init = $this->initRequest($token, '/post/publish/video/init/', [
            'post_info' => $this->videoPostInfo($caption, $options),
            'source_info' => $sourceInfo,
        ]);
        $log[] = $this->event('init:direct', $init['status'], $init['body']);

        // 2) Fall back to Upload-to-Inbox for unaudited apps + public accounts.
        if (! $init['ok'] && $init['code'] === 'unaudited_client_can_only_post_to_private_accounts') {
            $mode = 'inbox';
            $init = $this->initRequest($token, '/post/publish/inbox/video/init/', [
                'source_info' => $sourceInfo,
            ]);
            $log[] = $this->event('init:inbox', $init['status'], $init['body']);
        }

        if (! $init['ok'] && ($rateMsg = $this->rateLimitMessage($init['code']))) {
            throw new TikTokPublishException($rateMsg, ['log' => $log, 'mode' => $mode]);
        }
        if (! $init['ok']) {
            Log::error('TikTok init failed', ['mode' => $mode, 'status' => $init['status'], 'response' => $init['body']]);
            throw new TikTokPublishException('TikTok rejected the post: '.$init['body'], ['log' => $log, 'mode' => $mode]);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $isLast = $i === $totalChunks - 1;
            $start = $chunkSize * $i;
            $thisSize = $isLast ? ($videoSize - $start) : $chunkSize;
            $bytes = $this->readExact($stream, $thisSize);
            $len = strlen($bytes);
            if ($len === 0) {
                throw new TikTokPublishException('Unexpected end of video file while uploading.', ['log' => $log, 'mode' => $mode]);
            }
            $range = "{$start}-".($start + $len - 1)."/{$videoSize}";
            $upload = $this->uploadVideoChunk($init['upload_url'], $bytes, $start, $start + $len - 1, $videoSize);
            $log[] = $this->event("upload:{$i}", $upload['status'], $upload['body'], ['range' => $range]);

            if (! $upload['ok']) {
                Log::error('TikTok chunk upload failed', ['range' => $range, 'status' => $upload['status'], 'response' => $upload['body']]);
                throw new TikTokPublishException('Uploading the video to TikTok failed (HTTP '.$upload['status'].').', ['log' => $log, 'mode' => $mode]);
            }
        }

        return ['publish_id' => $init['publish_id'], 'mode' => $mode, 'log' => $log];
    }

    /**
     * Publish a video that TikTok pulls from a public URL (PULL_FROM_URL).
     *
     * This is the required transfer method whenever the file already lives on
     * our own storage — Technical Consideration 2(d): "If video resources are already on API Clients' servers, do not use
     * FILE_UPLOAD; use PULL_FROM_URL instead." Post media sits on the media disk,
     * so that is the normal case.
     *
     * TikTok fetches the bytes itself, so unlike publishVideoFromStream there is
     * no chunked upload — and the URL must be https, must not redirect, and must
     * be on a domain verified in the developer portal. MediaProxy signs a link on
     * APP_URL for exactly that reason.
     *
     * Mirrors the FILE_UPLOAD path otherwise, including the inbox fallback for
     * unaudited clients posting to a public account.
     *
     * @return array{publish_id: string, mode: string, log: array}  mode = direct|inbox
     */
    public function publishVideoFromUrl(SocialAccount $account, string $caption, string $videoUrl, array $options): array
    {
        if ($videoUrl === '') {
            throw new Exception('There is no video URL to publish.');
        }

        $log = [];
        $token = $this->freshToken($account);
        $sourceInfo = ['source' => 'PULL_FROM_URL', 'video_url' => $videoUrl];

        $mode = 'direct';
        $init = $this->initRequest($token, '/post/publish/video/init/', [
            'post_info' => $this->videoPostInfo($caption, $options),
            'source_info' => $sourceInfo,
        ], requireUploadUrl: false);
        $log[] = $this->event('init:direct', $init['status'], $init['body']);

        if (! $init['ok'] && $init['code'] === 'unaudited_client_can_only_post_to_private_accounts') {
            $mode = 'inbox';
            $init = $this->initRequest($token, '/post/publish/inbox/video/init/', [
                'source_info' => $sourceInfo,
            ], requireUploadUrl: false);
            $log[] = $this->event('init:inbox', $init['status'], $init['body']);
        }

        if (! $init['ok'] && ($rateMsg = $this->rateLimitMessage($init['code']))) {
            throw new TikTokPublishException($rateMsg, ['log' => $log, 'mode' => $mode]);
        }
        if (! $init['ok']) {
            Log::error('TikTok pull-from-url init failed', ['mode' => $mode, 'status' => $init['status'], 'response' => $init['body']]);
            throw new TikTokPublishException('TikTok rejected the post: '.$init['body'], ['log' => $log, 'mode' => $mode]);
        }

        return ['publish_id' => $init['publish_id'], 'mode' => $mode, 'log' => $log];
    }

    /**
     * The post_info block for a video, carrying the creator's choices. Shared by
     * both transfer methods so a change to the disclosure or interaction fields
     * can never apply to one and not the other.
     */
    private function videoPostInfo(string $caption, array $options): array
    {
        $postInfo = [
            'title' => self::utf16Truncate($caption ?? '', 2200),
            'privacy_level' => $options['privacy_level'] ?? 'SELF_ONLY',
            'disable_comment' => (bool) ($options['disable_comment'] ?? false),
            'disable_duet' => (bool) ($options['disable_duet'] ?? false),
            'disable_stitch' => (bool) ($options['disable_stitch'] ?? false),
            // Commercial-content disclosure: your_brand -> Brand Organic,
            // branded_content -> Branded Content (paid partnership). TikTok
            // requires these to reflect the user's disclosure choice.
            'brand_organic_toggle' => (bool) ($options['your_brand'] ?? false),
            'brand_content_toggle' => (bool) ($options['branded_content'] ?? false),
        ];
        // Custom cover frame (ms into the video) when the composer set one.
        if (! empty($options['video_cover_timestamp_ms'])) {
            $postInfo['video_cover_timestamp_ms'] = (int) $options['video_cover_timestamp_ms'];
        }

        return $postInfo;
    }

    /**
     * Publish a photo slideshow that TikTok pulls from public image URLs
     * (Content Posting API, media_type PHOTO). Unlike video there is no chunked
     * upload — TikTok fetches the images itself — so the URLs must be publicly
     * reachable (and on a domain verified in the TikTok developer portal).
     *
     * Mirrors the video flow: tries a Direct Post first, then falls back to the
     * creator's inbox (MEDIA_UPLOAD) for unaudited apps + public accounts.
     *
     * @param  string[]  $imageUrls  public, TikTok-reachable image URLs
     * @return array{publish_id: string, mode: string, log: array}  mode = direct|inbox
     */
    public function publishPhotosFromUrls(SocialAccount $account, string $caption, array $imageUrls, array $options): array
    {
        $imageUrls = array_values(array_filter($imageUrls));
        if (empty($imageUrls)) {
            throw new Exception('There are no images to publish.');
        }

        $log = [];
        $token = $this->freshToken($account);

        // Photos carry a short single-line title (≤90 UTF-16 units) and a
        // longer description (the caption). Limits are in UTF-16 units.
        $postInfo = [
            'title' => self::photoTitle($caption ?? ''),
            'description' => self::utf16Truncate($caption ?? '', 2200),
            'privacy_level' => $options['privacy_level'] ?? 'SELF_ONLY',
            'disable_comment' => (bool) ($options['disable_comment'] ?? false),
            // Off by default: only auto-add TikTok's recommended music when the
            // user (or an agent) explicitly opts in via options.auto_add_music.
            'auto_add_music' => (bool) ($options['auto_add_music'] ?? false),
            // Commercial-content disclosure (photo posts support these too).
            'brand_organic_toggle' => (bool) ($options['your_brand'] ?? false),
            'brand_content_toggle' => (bool) ($options['branded_content'] ?? false),
        ];
        $sourceInfo = [
            'source' => 'PULL_FROM_URL',
            'photo_cover_index' => 0,
            'photo_images' => $imageUrls,
        ];

        // 1) Direct Post (honours the privacy / interaction options).
        $mode = 'direct';
        $init = $this->initRequest($token, '/post/publish/content/init/', [
            'post_info' => $postInfo,
            'source_info' => $sourceInfo,
            'post_mode' => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ], requireUploadUrl: false);
        $log[] = $this->event('init:photo:direct', $init['status'], $init['body']);

        // 2) Fall back to Upload-to-Inbox for unaudited apps + public accounts.
        if (! $init['ok'] && $init['code'] === 'unaudited_client_can_only_post_to_private_accounts') {
            $mode = 'inbox';
            $init = $this->initRequest($token, '/post/publish/content/init/', [
                'post_info' => $postInfo,
                'source_info' => $sourceInfo,
                'post_mode' => 'MEDIA_UPLOAD',
                'media_type' => 'PHOTO',
            ], requireUploadUrl: false);
            $log[] = $this->event('init:photo:inbox', $init['status'], $init['body']);
        }

        if (! $init['ok'] && ($rateMsg = $this->rateLimitMessage($init['code']))) {
            throw new TikTokPublishException($rateMsg, ['log' => $log, 'mode' => $mode]);
        }
        if (! $init['ok']) {
            Log::error('TikTok photo init failed', ['mode' => $mode, 'status' => $init['status'], 'response' => $init['body']]);
            throw new TikTokPublishException('TikTok rejected the slideshow: '.$init['body'], ['log' => $log, 'mode' => $mode]);
        }

        return ['publish_id' => $init['publish_id'], 'mode' => $mode, 'log' => $log];
    }

    /**
     * Build one audit-log entry. Bodies are decoded (JSON when possible) and
     * truncated so the meta column stays small.
     */
    private function event(string $step, int $status, ?string $body, array $extra = []): array
    {
        return array_merge([
            'step' => $step,
            'status' => $status,
            'response' => $this->decodeBody($body),
            'at' => now()->toIso8601String(),
        ], $extra);
    }

    /**
     * Decode a TikTok response body for storage: JSON when valid, otherwise a
     * truncated raw string.
     */
    private function decodeBody(?string $body)
    {
        if ($body === null || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return mb_strlen($body) > 1000 ? mb_substr($body, 0, 1000).'…' : $body;
    }

    /**
     * Map TikTok's "creator can't post right now" init error codes to an
     * actionable, user-facing message (rate limit / restricted account).
     * Returns null for any other code so the generic handler takes over.
     */
    private function rateLimitMessage(?string $code): ?string
    {
        return in_array($code, TikTokCreatorUnavailableException::CODES, true)
            ? self::cannotPostMessage($code)
            : null;
    }

    /**
     * The user-facing wording for each "creator can't post right now" code.
     * Required UX 1(b) asks that the user be prompted to try again later, so
     * every variant says so.
     */
    private static function cannotPostMessage(string $code): string
    {
        return match ($code) {
            'spam_risk_too_many_posts' => "You've reached TikTok's posting limit for this account — try again later.",
            'spam_risk_user_banned_from_posting' => 'This TikTok account is currently restricted from posting — try again later.',
            'reached_active_user_cap' => "TikTok's daily limit for this app has been reached — try again later.",
            default => "You can't post to TikTok right now — try again later.",
        };
    }

    /**
     * POST a publish init request and normalize the result.
     *
     * FILE_UPLOAD (video) inits return an upload_url to PUT chunks to;
     * PULL_FROM_URL (photo) inits don't — TikTok fetches the media itself — so
     * $requireUploadUrl lets the photo path treat "publish_id, no upload_url" as
     * success.
     *
     * @return array{ok: bool, publish_id: ?string, upload_url: ?string, code: ?string, status: int, body: string}
     */
    private function initRequest(string $token, string $endpoint, array $body, bool $requireUploadUrl = true): array
    {
        $response = Http::withToken($token)
            ->contentType('application/json; charset=UTF-8')
            ->post(self::BASE.$endpoint, $body);

        $publishId = $response->json('data.publish_id');
        $uploadUrl = $response->json('data.upload_url');

        Log::info('TikTok init response', ['endpoint' => $endpoint, 'status' => $response->status(), 'body' => $response->body()]);

        return [
            'ok' => $response->successful() && $publishId && ($uploadUrl || ! $requireUploadUrl),
            'publish_id' => $publishId,
            'upload_url' => $uploadUrl,
            'code' => $response->json('error.code'),
            'status' => $response->status(),
            'body' => $response->body(),
        ];
    }

    /**
     * PUT a single video chunk to the TikTok-provided upload URL. The upload
     * URL is pre-signed — it must NOT carry the OAuth bearer token. Returns the
     * normalized result (does not throw) so the caller can record the response.
     *
     * @return array{ok: bool, status: int, body: string}
     */
    public function uploadVideoChunk(string $uploadUrl, string $bytes, int $start, int $end, int $total): array
    {
        $response = Http::timeout(120)
            ->withHeaders([
                'Content-Type' => 'video/mp4',
                'Content-Range' => "bytes {$start}-{$end}/{$total}",
            ])
            ->withBody($bytes, 'video/mp4')
            ->put($uploadUrl);

        Log::info('TikTok chunk upload response', ['range' => "{$start}-{$end}/{$total}", 'status' => $response->status(), 'body' => $response->body()]);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->body(),
        ];
    }

    /**
     * Read exactly $len bytes from the stream (or until EOF).
     *
     * @param  resource  $stream
     */
    private function readExact($stream, int $len): string
    {
        $buf = '';
        while ($len > 0 && ! feof($stream)) {
            $chunk = fread($stream, (int) min(1024 * 1024, $len));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
            $len -= strlen($chunk);
        }

        return $buf;
    }

    /**
     * Fetch the processing status of a publish_id.
     * status: PROCESSING_DOWNLOAD | PROCESSING_UPLOAD | SEND_TO_USER_INBOX |
     *         PUBLISH_COMPLETE | FAILED
     */
    public function fetchStatus(SocialAccount $account, string $publishId): array
    {
        $token = $this->freshToken($account);

        $response = Http::withToken($token)
            ->contentType('application/json; charset=UTF-8')
            ->post(self::BASE.'/post/publish/status/fetch/', [
                'publish_id' => $publishId,
            ]);

        if (! $response->successful()) {
            Log::error('TikTok status fetch failed', ['response' => $response->body()]);
            throw new Exception('Failed to fetch TikTok publish status.');
        }

        return $response->json('data', []);
    }
}
