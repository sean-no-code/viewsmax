<?php

namespace App\Services;

use App\Exceptions\MediaReadException;
use App\Exceptions\TikTokCreatorUnavailableException;
use App\Exceptions\TikTokPublishException;
use App\Exceptions\TikTokUploadInterruptedException;
use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use App\Support\BoundedMediaStream;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    // Publish calls retry a connection error, a 429 or a 5xx a few times with
    // a short pause so one TikTok blip cannot fail the post
    // for good. 3 attempts × the 30 s default HTTP timeout + 2 pauses = 100 s
    // per call, well under PublishToTikTokJob's $timeout.
    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MS = 5000;

    // One chunk PUT must also fit the retry budget inside the job timeout
    // (3 × 30 s + 2 × 5 s = 100 s). This path is only used where TikTok
    // cannot pull from our storage, so 8 MB in 30 s is plenty.
    private const CHUNK_TIMEOUT = 30;

    // Content-Types TikTok accepts on a chunk PUT; the header must match the
    // file, so a .mov sent as video/mp4 is rejected.
    private const CHUNK_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];

    // Error codes that mean the token is dead or lacks the publish scopes:
    // every later post would fail the same way until the user reconnects.
    private const AUTH_CODES = ['access_token_invalid', 'scope_not_authorized', 'scope_permission_missed'];

    public const REAUTH_MESSAGE = 'TikTok no longer accepts this account\'s authorization — reconnect the account and post again.';

    public function __construct(private SocialProviderManager $providers) {}

    /**
     * A request that retries transient failures only. A 4xx is TikTok's real
     * answer (bad params, unaudited client, creator can't post) and is never
     * retried. `throw: false` keeps the final response flowing into the
     * callers' existing status/body inspection instead of surfacing as a
     * RequestException, so the inbox fallback and error mapping are unchanged.
     */
    private static function retrying(): PendingRequest
    {
        return Http::retry(self::RETRY_TIMES, self::RETRY_DELAY_MS, fn (Throwable $e) => self::isTransient($e), throw: false);
    }

    private static function isTransient(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError()));
    }

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

        // The @handle only arrives here (user.info.basic has display_name, not
        // username) and the composer queries this before every post, so keep
        // it on the account for building post URLs later.
        if (! empty($data['creator_username']) && $account->exists
            && $account->meta('creator_username') !== $data['creator_username']) {
            $account->forceFill(['metadata' => array_merge($account->metadata ?? [], [
                'creator_username' => $data['creator_username'],
            ])])->save();
        }

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
        // 0 (the first frame) is a real choice, so only null means "unset".
        if (($options['video_cover_timestamp_ms'] ?? null) !== null) {
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
     * $onInit runs as soon as TikTok has accepted the init, with the same
     * array this method returns (minus the upload events): the job persists the
     * publish_id there, so a worker killed mid-upload polls that id on its next
     * attempt instead of starting a second post.
     *
     * @param  resource  $stream  readable stream of the video file
     * @param  string  $mime  the stored file's type; TikTok rejects a chunk whose Content-Type does not match the bytes
     * @return array{publish_id: string, mode: string, log: array}  mode = direct|inbox
     */
    public function publishVideoFromStream(SocialAccount $account, string $caption, $stream, int $videoSize, array $options, string $mime = 'video/mp4', ?callable $onInit = null): array
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

        if (! $init['ok']) {
            $this->rejectInit($account, $init, 'TikTok rejected the post', $log, $mode);
        }

        $result = ['publish_id' => $init['publish_id'], 'upload_url' => $init['upload_url'], 'mode' => $mode, 'log' => $log];
        if ($onInit) {
            $onInit($result);
        }

        // Bound every read: a stalled R2 read or a file shorter than the size
        // we declared to TikTok fails with a re-upload message instead of
        // running the job into its timeout.
        $reader = new BoundedMediaStream($stream, $videoSize);
        $mime = in_array($mime, self::CHUNK_MIMES, true) ? $mime : 'video/mp4';

        for ($i = 0; $i < $totalChunks; $i++) {
            $isLast = $i === $totalChunks - 1;
            $start = $chunkSize * $i;
            $thisSize = $isLast ? ($videoSize - $start) : $chunkSize;
            try {
                $bytes = $this->readExact($reader, $thisSize);
                $len = strlen($bytes);
                $range = "{$start}-".($start + $len - 1)."/{$videoSize}";
                $upload = $this->uploadVideoChunk($init['upload_url'], $bytes, $start, $start + $len - 1, $videoSize, $mime);
            } catch (MediaReadException|ConnectionException $e) {
                // Not TikTok's verdict: the init is already accepted and earlier
                // chunks may have landed, so the caller polls instead of failing.
                // The audit log so far rides along.
                throw new TikTokUploadInterruptedException($e->getMessage(), ['log' => $log, 'mode' => $mode]);
            }
            $log[] = $this->event("upload:{$i}", $upload['status'], $upload['body'], ['range' => $range]);

            if (! $upload['ok']) {
                $this->rejectUpload($account, $upload, $range, $log, $mode);
            }
        }

        return ['publish_id' => $init['publish_id'], 'mode' => $mode, 'log' => $log];
    }

    /**
     * An init TikTok refused: a dead token flags the account for reconnect,
     * anything else becomes the mapped user-facing message. Never returns.
     */
    private function rejectInit(SocialAccount $account, array $init, string $what, array $log, string $mode): never
    {
        $context = ['log' => $log, 'mode' => $mode];
        if ($this->flagReauth($account, $init['code'])) {
            throw new TikTokPublishException(self::REAUTH_MESSAGE, $context);
        }

        Log::error('TikTok init failed', ['mode' => $mode, 'status' => $init['status'], 'response' => $init['body']]);
        throw new TikTokPublishException(self::rejection($what, $init['code'], $init['status']), $context);
    }

    /**
     * A chunk PUT TikTok answered with a non-2xx: that IS its verdict (unlike
     * a connection error), so the post fails. Never returns.
     */
    private function rejectUpload(SocialAccount $account, array $upload, string $range, array $log, string $mode): never
    {
        $context = ['log' => $log, 'mode' => $mode];
        if ($this->flagReauth($account, $upload['code'])) {
            throw new TikTokPublishException(self::REAUTH_MESSAGE, $context);
        }

        Log::error('TikTok chunk upload failed', ['range' => $range, 'status' => $upload['status'], 'response' => $upload['body']]);
        $reason = $upload['code'] ? self::rejection('TikTok rejected the upload', $upload['code'], $upload['status'])
            : 'Uploading the video to TikTok failed (HTTP '.$upload['status'].').';
        throw new TikTokPublishException($reason, $context);
    }

    /**
     * Mark the account needs_reauth when TikTok says the token is invalid or
     * lacks the publish scopes. Without this every later scheduled post fails
     * the same way with nothing telling the user to reconnect.
     */
    private function flagReauth(SocialAccount $account, ?string $code): bool
    {
        if (! in_array($code, self::AUTH_CODES, true)) {
            return false;
        }

        Log::warning('TikTok rejected the token; account needs reconnect', ['account_id' => $account->id, 'code' => $code]);
        $account->markNeedsReauth(self::REAUTH_MESSAGE);

        return true;
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

        if (! $init['ok']) {
            $this->rejectInit($account, $init, 'TikTok rejected the post', $log, $mode);
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
        // 0 (the first frame) is a real choice, so only null means "unset".
        if (($options['video_cover_timestamp_ms'] ?? null) !== null) {
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

        if (! $init['ok']) {
            $this->rejectInit($account, $init, 'TikTok rejected the slideshow', $log, $mode);
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
     * Plain-English reason for a TikTok error code — the init `error.code`s
     * and the `fail_reason` values of a FAILED status share one vocabulary.
     * Null when the code is unknown.
     */
    public static function errorMessage(string $code): ?string
    {
        return match ($code) {
            'rate_limit_exceeded' => "TikTok's API rate limit was hit — try again later.",
            'file_format_check_failed' => "the video's file format is not supported — check TikTok's video specifications.",
            'duration_check_failed' => "the video's length is outside what this account can post — check the duration.",
            'frame_rate_check_failed' => "the video's frame rate is not supported — check TikTok's video specifications.",
            'picture_size_check_failed' => 'the media size is not supported — images up to 1080px on the shorter side, videos at least 360px on both sides.',
            'video_pull_failed' => 'TikTok could not download the video from our server — try again.',
            'photo_pull_failed' => 'TikTok could not download one of the images from our server — try again.',
            'url_ownership_unverified' => "TikTok only pulls media from a domain verified in its developer portal, and this media URL's domain is not — contact support.",
            'privacy_level_option_mismatch' => 'the chosen privacy level is not available for this account — pick another privacy option.',
            'unaudited_client_can_only_post_to_private_accounts' => 'this app is not yet approved by TikTok for public posting — choose the private ("Only me") privacy level.',
            'app_version_check_failed' => 'the TikTok app on your phone needs updating before it can receive uploads.',
            'spam_risk_text' => 'TikTok flagged the caption as possible spam — edit the text and try again.',
            'spam_risk_too_many_pending_share' => 'TikTok allows at most 5 pending posts in any 24 hours — finish or discard them in the TikTok app and try again later.',
            'invalid_file_upload' => "the uploaded file does not meet TikTok's specifications.",
            'invalid_params' => 'TikTok rejected the request parameters — check the caption and settings.',
            'internal_error', 'internal' => 'TikTok had a problem on its servers — try again later.',
            default => in_array($code, TikTokCreatorUnavailableException::CODES, true) ? self::cannotPostMessage($code)
                : (str_starts_with($code, 'spam_risk') ? 'TikTok flagged the post as possible spam.' : null),
        };
    }

    /**
     * "<what>: <reason>" for a rejected init or a FAILED status, falling back
     * to the bare code (never the raw JSON body) when it is unknown. The
     * creator-cannot-post codes keep their standalone "try again later" line.
     */
    public static function rejection(string $what, ?string $code, int $status = 0): string
    {
        if ($code === null || $code === '') {
            return $status > 0 ? "{$what} (HTTP {$status})." : "{$what}.";
        }
        if (in_array($code, TikTokCreatorUnavailableException::CODES, true)) {
            return self::cannotPostMessage($code);
        }

        return "{$what}: ".(self::errorMessage($code) ?? $code);
    }

    /**
     * Where the post lives on TikTok. Photo publish ids start with `p_`
     * (p_pub_…, p_inbox_…) and live under /photo/ — TikTok answers 403 for
     * /video/ on them. Until moderation hands out the public post id the
     * profile URL is the best link we have; null when we know no @handle.
     */
    public static function postUrl(SocialAccount $account, ?string $publishId, ?string $postId): ?string
    {
        $handle = $account->meta('creator_username') ?: $account->username;
        if (! $handle) {
            return null;
        }

        $profile = 'https://www.tiktok.com/@'.rawurlencode(ltrim($handle, '@'));
        if (! $postId) {
            return $profile;
        }

        return $profile.(str_starts_with((string) $publishId, 'p_') ? '/photo/' : '/video/').$postId;
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
        $response = self::retrying()->withToken($token)
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
     * @return array{ok: bool, status: int, body: string, code: ?string}
     */
    public function uploadVideoChunk(string $uploadUrl, string $bytes, int $start, int $end, int $total, string $mime = 'video/mp4'): array
    {
        // A chunk PUT carries its Content-Range, so re-sending it is safe.
        $response = self::retrying()->timeout(self::CHUNK_TIMEOUT)
            ->withHeaders([
                'Content-Type' => $mime,
                'Content-Range' => "bytes {$start}-{$end}/{$total}",
            ])
            ->withBody($bytes, $mime)
            ->put($uploadUrl);

        Log::info('TikTok chunk upload response', ['range' => "{$start}-{$end}/{$total}", 'status' => $response->status(), 'body' => $response->body()]);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->body(),
            'code' => $response->json('error.code'),
        ];
    }

    /**
     * Read exactly $len bytes from the bounded stream. The stream throws
     * MediaReadException on a stall or when the file ends before the declared
     * size, so a short return here is impossible.
     */
    private function readExact(BoundedMediaStream $stream, int $len): string
    {
        $buf = '';
        while ($len > 0) {
            $chunk = $stream->read((int) min(1024 * 1024, $len));
            $buf .= $chunk;
            $len -= strlen($chunk);
        }

        return $buf;
    }

    /**
     * Fetch the processing status of a publish_id.
     * status: PROCESSING_DOWNLOAD | PROCESSING_UPLOAD | SEND_TO_USER_INBOX |
     *         PUBLISH_COMPLETE | FAILED
     *
     * Only a dead token is a real failure here (TikTokPublishException, account
     * flagged for reconnect). Anything else — 5xx, 429, a connection error,
     * even an odd 4xx — is a TransientPublishException: the post may already
     * be live, so the caller keeps the target pending and asks again.
     */
    public function fetchStatus(SocialAccount $account, string $publishId): array
    {
        $token = $this->freshToken($account);

        try {
            $response = self::retrying()->withToken($token)
                ->contentType('application/json; charset=UTF-8')
                ->post(self::BASE.'/post/publish/status/fetch/', [
                    'publish_id' => $publishId,
                ]);
        } catch (ConnectionException $e) {
            throw new TransientPublishException('Could not reach TikTok to check the post status — checking again.');
        }

        if (! $response->successful()) {
            if ($this->flagReauth($account, $response->json('error.code'))) {
                throw new TikTokPublishException(self::REAUTH_MESSAGE);
            }

            Log::warning('TikTok status fetch failed; will poll again', ['status' => $response->status(), 'response' => $response->body()]);
            throw new TransientPublishException('TikTok could not report the post status (HTTP '.$response->status().') — checking again.');
        }

        return $response->json('data', []);
    }
}
