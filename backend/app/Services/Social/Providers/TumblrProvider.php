<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\OAuthResult;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\SocialConnectException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tumblr publishing via API v2 with OAuth 2.0 (authorization code) and the
 * Neue Post Format (NPF). Docs: https://www.tumblr.com/docs/en/api/v2 and
 * https://www.tumblr.com/docs/npf (source: github.com/tumblr/docs).
 *
 * One grant, many rows: a Tumblr user can post to several blogs, and every
 * blog becomes its own SocialAccount (like Facebook Pages), all carrying the
 * same token pair and the same metadata.grant_id. Refreshing one row
 * therefore rewrites every row of the grant, under one lock per grant: the
 * refresh response carries a new refresh token, so a sibling left with the
 * old one would fail its next refresh.
 *
 * Media goes up in the create call itself (multipart/form-data: a `json`
 * part plus one part per file, matched by the block's `identifier`), the way
 * the API docs' "User Uploaded Media" section describes. The docs never say
 * a remote URL in a media object is fetched, so files are downloaded here
 * (through SafeHttp) and sent as bytes.
 */
class TumblrProvider extends AbstractSocialProvider
{
    protected string $platform = 'tumblr';

    protected const API = 'https://api.tumblr.com/v2';

    protected const AUTHORIZE_URL = 'https://www.tumblr.com/oauth2/authorize';

    /**
     * Checked against the token response at connect. The docs' example
     * response lists "write offline_access" (basic is not echoed back).
     */
    public const PUBLISH_SCOPES = ['write', 'offline_access'];

    /** NPF: "The maximum length of a text block is 4,096 characters." */
    public const TEXT_BLOCK_LIMIT = 4096;

    /** NPF: at most 30 image blocks per post. */
    public const MAX_IMAGES = 30;

    /** NPF: at most 1 native (uploaded) video block per post. */
    public const MAX_VIDEOS = 1;

    /**
     * Access tokens live about 40 minutes (the docs' example expires_in is
     * 2520 s). Refresh a little early so a long video upload never starts
     * on a token about to lapse.
     */
    protected const REFRESH_MARGIN_SECONDS = 300;

    public const RATE_LIMIT_MESSAGE = 'Tumblr is limiting how many requests ViewsMax can make right now. Try again later.';

    /**
     * Tumblr's own error subcodes (errors[].code) that need their own message.
     * From the API docs' error table for the NPF create route.
     */
    protected const LIMIT_CODES = [
        8004 => 'Tumblr says this account has uploaded as much media as it can today. Try again tomorrow.',
        8011 => 'Tumblr says this account has uploaded as many videos as it can today (20 videos or 60 minutes). Try again tomorrow.',
        8022 => 'This Tumblr blog\'s queue is full. Publish or delete some queued posts on Tumblr, then try again.',
        8023 => 'Tumblr says this account has published as many posts as it can today (250). Try again tomorrow.',
    ];

    protected const CODE_VIDEO_TRANSCODING = 8010;

    protected const CODE_BAD_MEDIA = 8005;

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'scope' => $this->scopeString(' '),
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Connect every blog the Tumblr user may post to, one row each, all on
     * the same token pair.
     *
     * @throws SocialConnectException when the user has no blog to post to
     */
    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $tokens = $this->exchangeAuthorizationCode(self::API.'/oauth2/token', $code, $redirectUri);
        $this->assertGrantedScopes($tokens->scopes, self::PUBLISH_SCOPES);

        $info = Http::withToken((string) $tokens->accessToken)->acceptJson()->get(self::API.'/user/info');
        if (! $info->successful()) {
            $this->fail('user info', $info->status(), $this->bodyExcerpt($info));
        }

        // "Each item is a blog the user has permissions to post to."
        $blogs = array_values(array_filter((array) $info->json('response.user.blogs', []), fn ($b) => ! empty($b['name'])));
        if ($blogs === []) {
            throw new SocialConnectException('This Tumblr account has no blog it can post to. Create a blog on Tumblr, then connect again.');
        }

        $grantId = (string) Str::uuid();

        return collect($blogs)->map(fn (array $blog) => $this->storeAccount($user, [
            // The uuid survives a blog rename; the name does not.
            'platform_account_id' => (string) ($blog['uuid'] ?? $blog['name']),
            // Tumblr titles a blog nobody renamed "Untitled"; its name tells blogs apart.
            'name' => ! in_array(trim((string) ($blog['title'] ?? '')), ['', 'Untitled'], true) ? $blog['title'] : $blog['name'],
            'username' => $blog['name'],
            'avatar_url' => data_get($blog, 'avatar.0.url') ?: self::API.'/blog/'.rawurlencode($blog['name']).'/avatar/128',
            'profile_url' => $blog['url'] ?? 'https://www.tumblr.com/'.$blog['name'],
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scopes ?: $this->config('scopes', []),
            'metadata' => [
                'grant_id' => $grantId,
                'primary' => (bool) ($blog['primary'] ?? false),
                'blog_type' => $blog['type'] ?? null,
            ],
        ]));
    }

    public function ensureFreshToken(SocialAccount $account): SocialAccount
    {
        $stillFresh = fn (SocialAccount $a) => $a->hasValidToken()
            && ! ($a->token_expires_at !== null && $a->token_expires_at->lt(now()->addSeconds(self::REFRESH_MARGIN_SECONDS)));

        if ($stillFresh($account) || empty($account->refresh_token)) {
            return $account;
        }

        return $this->refreshingSafely(
            $account,
            refresh: fn (SocialAccount $account) => $this->refreshGrant($account),
            isFresh: $stillFresh,
            lockKey: $this->grantLockKey($account),
        );
    }

    /**
     * Runs inside the grant lock. A sibling that refreshed first has already
     * written this row, so refreshingSafely's re-read returns before here.
     */
    protected function refreshGrant(SocialAccount $account): SocialAccount
    {
        $response = Http::asForm()->acceptJson()->post(self::API.'/oauth2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $account->refresh_token,
        ]);

        if (! $response->successful()) {
            // The docs answer 401 for an expired refresh token. A refusal
            // while the access token still has a few minutes left only logs:
            // the next attempt (after it lapses) flags the grant.
            if ($this->isDefinitiveAuthFailure($response->status()) && ! $account->hasValidToken()) {
                Log::error('[Tumblr] token refresh rejected; grant needs reconnect', [
                    'account_id' => $account->id, 'status' => $response->status(), 'body' => $this->bodyExcerpt($response),
                ]);
                $this->markGrantNeedsReauth($account, 'Tumblr authorization expired or was revoked — reconnect Tumblr.');
            } else {
                Log::warning('[Tumblr] token refresh failed transiently', [
                    'account_id' => $account->id, 'status' => $response->status(), 'body' => $this->bodyExcerpt($response),
                ]);
            }

            return $account->fresh() ?? $account;
        }

        $tokens = OAuthResult::fromArray((array) $response->json());
        if (empty($tokens->accessToken)) {
            Log::error('[Tumblr] token refresh returned no access token', ['account_id' => $account->id]);

            return $account;
        }

        DB::transaction(function () use ($account, $tokens) {
            foreach ($this->grantRows($account) as $row) {
                $this->applyTokens($row, $tokens);
            }
        });

        return $account->fresh() ?? $account;
    }

    /**
     * Every row that shares this account's token pair (itself included).
     *
     * @return Collection<int, SocialAccount>
     */
    protected function grantRows(SocialAccount $account): Collection
    {
        $grantId = $account->meta('grant_id');
        if (! $grantId) {
            return collect([$account]);
        }

        return SocialAccount::where('user_id', $account->user_id)
            ->where('platform', $this->platform)
            ->where('metadata->grant_id', $grantId)
            ->get();
    }

    protected function grantLockKey(SocialAccount $account): string
    {
        $grantId = $account->meta('grant_id');

        return $grantId
            ? 'social:token-refresh:tumblr-grant:'.$grantId
            : 'social:token-refresh:'.$account->id;
    }

    /**
     * The token is shared, so a dead one is dead for every blog of the grant.
     * Only the row at hand sends the reconnect email (one email per grant,
     * not per blog); the siblings are flagged quietly.
     */
    protected function markGrantNeedsReauth(SocialAccount $account, string $message): void
    {
        $account->markNeedsReauth($message);

        foreach ($this->grantRows($account) as $row) {
            if ($row->id !== $account->id) {
                $row->update(['status' => SocialAccount::STATUS_NEEDS_REAUTH, 'last_error' => $message]);
            }
        }
    }

    /**
     * Publish a caption with optional images (up to 30) and one video, plus
     * the tags the user typed ($post->tags, a list of strings). Media blocks
     * come first, the caption after, one text block per paragraph.
     *
     * @throws TransientPublishException on a rate limit, an upstream error,
     *         or a video still transcoding on Tumblr's side
     */
    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        // ensureFreshToken leaves an expired token in place when the refresh
        // failed transiently; sending it would get a 401 and wrongly flag the
        // grant, so retry later instead.
        if (! $account->hasValidToken()) {
            if (empty($account->refresh_token)) {
                $this->markGrantNeedsReauth($account, 'Tumblr authorization expired — reconnect Tumblr.');

                return PublishResult::failure('Tumblr authorization expired — reconnect Tumblr.');
            }

            throw new TransientPublishException('Tumblr sign-in could not be renewed right now — retrying automatically.');
        }

        $blog = (string) ($account->platform_account_id ?: $account->username);
        $images = array_slice($this->mediaItems($post, 'image'), 0, self::MAX_IMAGES);
        $videos = array_slice($this->mediaItems($post, 'video'), 0, self::MAX_VIDEOS);
        $paragraphs = $this->paragraphs((string) $post->content);

        if ($images === [] && $videos === [] && $paragraphs === []) {
            return PublishResult::failure('A Tumblr post needs text, an image or a video.');
        }

        $blocks = [];
        $files = [];
        foreach (array_merge($images, $videos) as $i => $item) {
            $download = $this->fetchMedia($item['url'], $item['type']);
            if ($download === null) {
                return PublishResult::failure("Tumblr publish failed: the {$item['type']} at {$item['url']} could not be downloaded.");
            }

            $identifier = 'media-'.$i;
            $mime = $this->mimeOf($download['body'], $download['content_type'] ?? $item['mime'], $item['type']);
            $files[] = [$identifier, $download['body'], $mime];

            $media = ['type' => $mime, 'identifier' => $identifier];
            if ($item['type'] === 'video') {
                // The docs' video example sends media as one object.
                $blocks[] = ['type' => 'video', 'media' => $media];

                continue;
            }

            if ($size = @getimagesizefromstring($download['body'])) {
                $media += ['width' => $size[0], 'height' => $size[1]];
            }
            // An image block's media is a list of versions of one image.
            $blocks[] = array_filter([
                'type' => 'image',
                'media' => [$media],
                'alt_text' => trim((string) ($item['alt'] ?? '')),
            ], fn ($v) => $v !== '');
        }

        foreach ($paragraphs as $paragraph) {
            $blocks[] = ['type' => 'text', 'text' => $paragraph];
        }

        $payload = ['content' => $blocks];
        if ($tags = $this->tagString($post->tags ?? [])) {
            $payload['tags'] = $tags;
        }

        $url = self::API.'/blog/'.rawurlencode($blog).'/posts';
        $log = ['account_id' => $account->id, 'blog' => $account->username, 'images' => count($images), 'videos' => count($videos)];
        Log::info('[Tumblr] create post', $log);

        $request = Http::withToken((string) $account->access_token)->acceptJson()->timeout($videos ? 600 : 120);
        if ($files === []) {
            $response = $request->post($url, $payload);
        } else {
            $request = $request->asMultipart()
                ->attach('json', (string) json_encode($payload), null, ['Content-Type' => 'application/json']);
            foreach ($files as [$identifier, $body, $mime]) {
                $request = $request->attach($identifier, $body, $identifier.'.'.$this->extensionFor($mime), ['Content-Type' => $mime]);
            }
            $response = $request->post($url);
        }

        if (! $response->successful()) {
            return $this->rejected($account, $response, $log);
        }

        // The docs' 201 example: {"response": {"id": "1234567891234567"}}.
        $id = (string) $response->json('response.id');
        if ($id === '') {
            Log::error('[Tumblr] create post answered without a post id', $log + ['status' => $response->status(), 'body' => $this->bodyExcerpt($response)]);

            return PublishResult::failure('Tumblr accepted the post but returned no post id. Check the blog before retrying.', (array) $response->json());
        }

        // A video is still transcoding at this point; Tumblr has accepted it
        // and the post exists, so it counts as published.
        return PublishResult::success($id, 'https://www.tumblr.com/'.rawurlencode((string) $account->username).'/'.$id, (array) $response->json());
    }

    /**
     * Turn a failed create call into a PublishResult with a message the user
     * can act on, flag the grant on a dead token, and hand rate limits,
     * upstream errors and "video still transcoding" to the queue.
     *
     * @throws TransientPublishException
     */
    protected function rejected(SocialAccount $account, Response $response, array $log): PublishResult
    {
        $status = $response->status();
        $code = (int) $response->json('errors.0.code');
        $reason = $this->reason($response);
        $context = $log + [
            'status' => $status,
            'code' => $code ?: null,
            'reason' => $reason,
            'rate_limit' => $this->rateLimitHeaders($response),
        ];

        if ($status === 401) {
            Log::error('[Tumblr] create post rejected the token; grant needs reconnect', $context);
            $this->markGrantNeedsReauth($account, 'Tumblr authorization expired or was revoked — reconnect Tumblr.');

            return PublishResult::failure('Tumblr authorization expired or was revoked — reconnect Tumblr.', (array) $response->json());
        }

        if ($code === self::CODE_VIDEO_TRANSCODING) {
            Log::warning('[Tumblr] an earlier video is still transcoding; queue will retry', $context);

            throw new TransientPublishException('Tumblr is still processing an earlier video on this account — retrying automatically.');
        }

        if ($status === 429) {
            Log::warning('[Tumblr] rate limit hit; queue will retry', $context);

            throw new TransientPublishException(self::RATE_LIMIT_MESSAGE, 429);
        }

        $this->throwIfTransient($response, 'publish');

        $blog = (string) $account->username;
        $message = match (true) {
            isset(self::LIMIT_CODES[$code]) => self::LIMIT_CODES[$code],
            $code === self::CODE_BAD_MEDIA => 'Tumblr can\'t use this media format'.($reason ? ": {$reason}" : '.'),
            $status === 403 => "Tumblr won't let this account post to {$blog}. Check that you can still post to the blog on Tumblr and that it isn't suspended"
                .($reason ? " (Tumblr says: {$reason})." : '.'),
            $status === 404 => "Tumblr couldn't find the blog {$blog}. It may have been renamed or deleted — reconnect Tumblr.",
            $status === 400 => 'Tumblr refused the post'.($reason ? ": {$reason}" : '.'),
            default => "Tumblr publish failed (HTTP {$status})".($reason ? ": {$reason}" : '.'),
        };

        Log::error('[Tumblr] create post failed', $context);

        return PublishResult::failure($message, (array) $response->json());
    }

    /** Tumblr's own words for a failure: errors[0].detail, its title, or meta.msg. */
    protected function reason(Response $response): string
    {
        foreach (['errors.0.detail', 'errors.0.title', 'meta.msg'] as $path) {
            $value = $response->json($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /** Rate-limit headers (X-Ratelimit-*) for the log, when Tumblr sends them. */
    protected function rateLimitHeaders(Response $response): array
    {
        return collect($response->headers())
            ->filter(fn ($v, $name) => str_starts_with(strtolower((string) $name), 'x-ratelimit'))
            ->map(fn ($v) => is_array($v) ? implode(',', $v) : $v)
            ->all();
    }

    /**
     * The caption as NPF text blocks: one per paragraph (blank-line
     * separated), each at most TEXT_BLOCK_LIMIT characters. CaptionRules caps
     * the whole caption at that limit, so no paragraph can exceed it.
     *
     * @return list<string>
     */
    protected function paragraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return array_values(array_filter(
            array_map('trim', preg_split('/\n[ \t]*\n/', $text) ?: []),
            fn ($p) => $p !== ''
        ));
    }

    /**
     * Tags as Tumblr takes them: one comma-separated string. Commas inside a
     * tag would split it, a leading # is how people type tags, and blanks or
     * repeats are dropped. Empty when the user typed none.
     *
     * @param  mixed  $tags
     */
    public static function tagString($tags): string
    {
        return collect(is_array($tags) ? $tags : [])
            ->map(fn ($t) => trim(str_replace(',', ' ', ltrim(trim((string) $t), '#'))))
            ->filter(fn ($t) => $t !== '')
            ->unique(fn ($t) => mb_strtolower($t))
            ->implode(',');
    }

    protected function mimeOf(string $body, ?string $declared, string $type): string
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
        if (str_starts_with($detected, $type.'/')) {
            return $detected;
        }

        $declared = strtolower(trim(explode(';', (string) $declared)[0]));

        return str_starts_with($declared, $type.'/') ? $declared : ($type === 'video' ? 'video/mp4' : 'image/jpeg');
    }

    protected function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => str_starts_with($mime, 'video/') ? 'mp4' : 'jpg',
        };
    }
}
