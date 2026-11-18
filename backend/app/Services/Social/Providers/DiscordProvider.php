<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\Discord\RateLimitedException;
use App\Services\Social\SocialConnectException;
use App\Support\SafeHttp;
use App\Support\UnsafeUrlException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discord publishing through an incoming webhook.
 *
 * Connecting uses OAuth2 with the `webhook.incoming` scope: Discord's own
 * consent screen has the user pick a server and a channel, and the token
 * response carries a webhook for that channel. One connected account is one
 * channel; connecting again adds another channel (or replaces the webhook of
 * a channel already connected). There is no bot user.
 *
 * The webhook token is a secret equal to posting rights in that channel. It is
 * stored only in the encrypted access_token column, and since it is part of
 * the execute URL, URLs are never logged or put into error text.
 *
 * Publishing is one POST /webhooks/{id}/{token}?wait=true: text as `content`,
 * images and video as uploaded files (multipart, files[n] + payload_json).
 */
class DiscordProvider extends AbstractSocialProvider
{
    protected string $platform = 'discord';

    public const API_BASE = 'https://discord.com/api/v10';

    public const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';

    public const TOKEN_URL = 'https://discord.com/api/oauth2/token';

    /** Message `content` limit (Execute Webhook). */
    public const CONTENT_LIMIT = 2000;

    /** Files per message. */
    public const MAX_FILES = 10;

    /** Per-file upload limit for servers without boosts ("Uploading Files": 20 MiB). */
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /** Whole request limit when sending a message (Create Message: 25 MiB). */
    public const MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    /** Attachment description (alt text) limit. */
    public const ALT_TEXT_LIMIT = 1024;

    /**
     * Mentions Discord may turn into pings. Only a deliberate user mention
     * (<@id>) pings; "@everyone", "@here" and role mentions in a caption stay
     * plain text, so a post can never mass-ping a server by accident.
     */
    public const ALLOWED_MENTIONS = ['parse' => ['users']];

    public const WEBHOOK_GONE = 'The Discord webhook for this channel no longer exists (it, or the channel, was deleted in Discord). Reconnect the channel to keep posting.';

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopeString(' '),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        $tokens = $this->exchangeAuthorizationCode(self::TOKEN_URL, $code, $redirectUri);

        $webhook = (array) ($tokens->raw['webhook'] ?? []);
        $webhookId = (string) ($webhook['id'] ?? '');
        $webhookToken = (string) ($webhook['token'] ?? '');
        $channelId = (string) ($webhook['channel_id'] ?? '');
        $guildId = (string) ($webhook['guild_id'] ?? '');

        if ($webhookId === '' || $webhookToken === '' || $channelId === '') {
            Log::error('[discord] token response carried no webhook', [
                'keys' => array_keys($tokens->raw),
                'webhook_keys' => array_keys($webhook),
                'scopes' => $tokens->scopes,
            ]);

            throw new SocialConnectException("Discord didn't return a channel to post to. Connect again and pick a server and a channel on Discord's screen.");
        }

        $guild = $this->guild((string) $tokens->accessToken, $guildId);
        $guildName = trim((string) ($guild['name'] ?? ''));

        // Reconnecting a channel replaces its webhook: Discord makes a new one
        // on every authorization, so the old one is deleted once the new one
        // is stored.
        $existing = $user->socialAccounts()
            ->where('platform', $this->platform)
            ->where('platform_account_id', $channelId)
            ->first();
        $previousId = (string) ($existing?->meta('webhook_id') ?? '');
        $previousToken = (string) ($existing?->access_token ?? '');

        $account = $this->storeAccount($user, [
            'platform_account_id' => $channelId,
            'name' => ($guildName !== '' ? $guildName : 'Discord server').' · channel …'.substr($channelId, -4),
            'username' => null,
            'avatar_url' => ! empty($guild['icon']) && $guildId !== ''
                ? "https://cdn.discordapp.com/icons/{$guildId}/{$guild['icon']}.png"
                : null,
            'profile_url' => $guildId !== '' ? "https://discord.com/channels/{$guildId}/{$channelId}" : null,
            'access_token' => $webhookToken,
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => $tokens->scopes ?: $this->config('scopes', []),
            // Ids only: the webhook's url embeds its token and is never stored.
            'metadata' => array_filter([
                'webhook_id' => $webhookId,
                'channel_id' => $channelId,
                'guild_id' => $guildId,
                'guild_name' => $guildName,
            ], fn ($v) => $v !== ''),
        ]);

        if ($previousId !== '' && $previousId !== $webhookId && $previousToken !== '') {
            $this->deleteWebhook($previousId, $previousToken);
        }

        return collect([$account]);
    }

    /**
     * The connected server ({id, name, icon}) from the user's server list, read
     * once with the short-lived OAuth token, which is not kept. Empty when it
     * can't be read; the account is still connected, just named generically.
     */
    protected function guild(string $accessToken, string $guildId): array
    {
        if ($accessToken === '' || $guildId === '') {
            return [];
        }

        try {
            $response = Http::withToken($accessToken)->acceptJson()->get(self::API_BASE.'/users/@me/guilds');
        } catch (\Throwable $e) {
            Log::error('[discord] server lookup failed', ['guild_id' => $guildId, 'error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('[discord] server lookup failed', [
                'guild_id' => $guildId, 'status' => $response->status(), 'body' => $this->bodyExcerpt($response),
            ]);

            return [];
        }

        foreach ((array) $response->json() as $guild) {
            if (is_array($guild) && (string) ($guild['id'] ?? '') === $guildId) {
                return $guild;
            }
        }

        return [];
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $webhookId = (string) $account->meta('webhook_id', '');
        $token = (string) $account->access_token;
        if ($webhookId === '' || $token === '') {
            $account->markNeedsReauth(self::WEBHOOK_GONE);

            return PublishResult::failure(self::WEBHOOK_GONE);
        }

        $content = trim((string) $post->content);
        $length = mb_strlen($content);
        if ($length > self::CONTENT_LIMIT) {
            return PublishResult::failure('Discord messages are limited to '.self::CONTENT_LIMIT." characters; this one has {$length}.");
        }

        $media = $this->mediaItems($post);
        if (count($media) > self::MAX_FILES) {
            return PublishResult::failure('Discord takes at most '.self::MAX_FILES.' files per message; this post has '.count($media).'.');
        }
        if ($content === '' && $media === []) {
            return PublishResult::failure('Discord needs text or at least one image or video.');
        }

        [$files, $error] = $this->downloadFiles($media);
        if ($error !== null) {
            return PublishResult::failure($error);
        }

        $payload = ['allowed_mentions' => self::ALLOWED_MENTIONS];
        if ($content !== '') {
            $payload['content'] = $content;
        }
        if ($files !== []) {
            $payload['attachments'] = array_map(fn (array $file, int $i) => ['id' => $i, 'filename' => $file['filename']]
                + ($file['alt'] !== null ? ['description' => $file['alt']] : []), $files, array_keys($files));
        }

        $log = ['account_id' => $account->id, 'webhook_id' => $webhookId, 'files' => count($files)];
        $url = $this->webhookUrl($webhookId, $token).'?wait=true';

        try {
            if ($files === []) {
                $response = Http::acceptJson()->post($url, $payload);
            } else {
                $request = Http::acceptJson()->timeout(180)->asMultipart();
                foreach ($files as $i => $file) {
                    $request = $request->attach("files[{$i}]", $file['body'], $file['filename'], ['Content-Type' => $file['mime']]);
                }
                $response = $request->post($url, ['payload_json' => json_encode($payload)]);
            }
        } catch (ConnectionException $e) {
            // The exception text carries the URL, and with it the token.
            Log::warning('[discord] could not reach Discord; queue will retry', $log + ['error' => self::redact($e->getMessage())]);

            throw new TransientPublishException('Could not reach Discord — retrying automatically.');
        }

        if (! $response->successful()) {
            return $this->refusal($account, $response, $log);
        }

        $messageId = (string) $response->json('id');
        $channelId = (string) ($response->json('channel_id') ?: $account->meta('channel_id', ''));
        $guildId = (string) $account->meta('guild_id', '');
        Log::info('[discord] message posted', $log + ['message_id' => $messageId]);

        return PublishResult::success(
            $messageId !== '' ? $messageId : null,
            $messageId !== '' && $channelId !== '' && $guildId !== ''
                ? "https://discord.com/channels/{$guildId}/{$channelId}/{$messageId}"
                : null,
            ['id' => $messageId, 'channel_id' => $channelId],
        );
    }

    /**
     * Map a refused execute call to a user message, logging Discord's own
     * reason. Throws for a rate limit (with Discord's retry_after) and for
     * upstream errors, so the job retries.
     */
    protected function refusal(SocialAccount $account, Response $response, array $log): PublishResult
    {
        $status = $response->status();
        $code = (int) $response->json('code');
        $reason = (string) $response->json('message');
        $context = $log + ['status' => $status, 'code' => $code, 'reason' => $reason, 'errors' => $response->json('errors')];

        if ($status === 429) {
            $retryAfter = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 5);
            Log::warning('[discord] rate limited; job will retry', $context + ['retry_after' => $retryAfter, 'global' => $response->json('global')]);

            throw new RateLimitedException('Discord rate limit hit — retrying in '.(int) ceil($retryAfter).' seconds.', $retryAfter);
        }

        $this->throwIfTransient($response, 'publish');

        // Unknown Webhook / Unknown Channel / Invalid Webhook Token: deleted in
        // Discord. Discord asks apps to stop calling a webhook that 404s, so the
        // account is flagged and later posts fail before any call.
        if (in_array($status, [401, 404], true) || in_array($code, [10003, 10015, 50027], true)) {
            Log::warning('[discord] webhook is gone; account needs reconnect', $context);
            $account->markNeedsReauth(self::WEBHOOK_GONE);

            return PublishResult::failure(self::WEBHOOK_GONE);
        }

        $message = match (true) {
            in_array($code, [220001, 220003, 40058], true) => 'This Discord channel is a forum or media channel, where every post needs its own thread. ViewsMax posts to text and announcement channels: connect one of those instead.',
            $status === 413 || in_array($code, [40005, 50045], true) => 'Discord refused the files as too large. '.self::limitsSentence(),
            in_array($code, [200000, 240000, 20009], true) => "Discord's moderation blocked this message".($reason !== '' ? " ({$reason})" : '').'. Edit the post and try again.',
            $code === 20016 => "This Discord channel's slowmode refused the post. Try again later.",
            $code === 50006 => 'Discord refused an empty message. Add text or a file.',
            $status === 403 || in_array($code, [50001, 50013], true) => "Discord refused the post: the webhook isn't allowed to post in this channel. Check the channel's permissions in Discord, or reconnect it.",
            default => null,
        };

        if ($message !== null) {
            Log::warning('[discord] publish refused', $context);

            return PublishResult::failure($message, $response->json() ?? []);
        }

        Log::error('[discord] publish failed', $context);

        return PublishResult::failure(
            "Discord refused the post (HTTP {$status}".($reason !== '' ? ": {$reason}" : '').').',
            $response->json() ?? [],
        );
    }

    /**
     * Download each media item (through SafeHttp: the links come from users)
     * within Discord's file limits. Returns [files, error].
     *
     * @param  array<int, array{url:string,type:string,mime:?string,alt:?string}>  $media
     * @return array{0: array<int, array{body:string, filename:string, mime:string, alt:?string}>, 1: ?string}
     */
    protected function downloadFiles(array $media): array
    {
        $files = [];
        $total = 0;

        foreach ($media as $i => $item) {
            $n = $i + 1;

            try {
                $download = SafeHttp::get($item['url'], static::MAX_FILE_BYTES, $item['type'] === 'video' ? 120 : 60);
            } catch (UnsafeUrlException $e) {
                Log::warning('[discord] media fetch refused', ['url' => $item['url'], 'reason' => $e->getMessage()]);

                return [[], str_contains($e->getMessage(), 'too large')
                    ? "File {$n} is too large for Discord. ".self::limitsSentence()
                    : "File {$n} could not be downloaded for Discord: ".$e->getMessage()];
            }

            $total += strlen($download['body']);
            if ($total > static::MAX_MESSAGE_BYTES) {
                return [[], 'These files are too large together for one Discord message. '.self::limitsSentence()];
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($download['body']) ?: 'application/octet-stream';
            $alt = trim((string) ($item['alt'] ?? ''));

            $files[] = [
                'body' => $download['body'],
                'filename' => $this->filename($item['url'], $mime, $n),
                'mime' => $mime,
                'alt' => $alt !== '' ? mb_substr($alt, 0, self::ALT_TEXT_LIMIT) : null,
            ];
        }

        return [$files, null];
    }

    /**
     * Discord decides how to show a file (inline image, video player) from
     * its name, so the extension follows the detected type. The number keeps
     * names unique within the message.
     */
    protected function filename(string $url, string $mime, int $n): string
    {
        $name = pathinfo(basename((string) parse_url($url, PHP_URL_PATH)), PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-');
        $name = substr($name !== '' ? $name : 'file', 0, 80);

        $ext = self::EXTENSIONS[$mime]
            ?? strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)));

        return "{$n}-{$name}".($ext !== '' ? ".{$ext}" : '');
    }

    public static function limitsSentence(): string
    {
        return 'Discord takes files up to '.(static::MAX_FILE_BYTES / 1024 / 1024).' MB each and '
            .(static::MAX_MESSAGE_BYTES / 1024 / 1024).' MB per message.';
    }

    /**
     * Delete a webhook in Discord (best effort): true when it is gone,
     * including when Discord says it no longer exists.
     */
    public function deleteWebhook(string $webhookId, string $token): bool
    {
        try {
            $response = Http::acceptJson()->delete($this->webhookUrl($webhookId, $token));
        } catch (\Throwable $e) {
            Log::error('[discord] webhook delete failed', ['webhook_id' => $webhookId, 'error' => self::redact($e->getMessage())]);

            return false;
        }

        if ($response->successful() || $response->status() === 404) {
            return true;
        }

        Log::error('[discord] webhook delete refused', [
            'webhook_id' => $webhookId, 'status' => $response->status(), 'body' => $this->bodyExcerpt($response),
        ]);

        return false;
    }

    protected function webhookUrl(string $webhookId, string $token): string
    {
        return self::API_BASE.'/webhooks/'.rawurlencode($webhookId).'/'.rawurlencode($token);
    }

    /**
     * Text with any webhook token taken out of webhook URLs
     * (/webhooks/{id}/{token} → /webhooks/{id}/[redacted]). Used on exception
     * text and by App\Support\SentryScrubber.
     */
    public static function redact(string $text): string
    {
        return preg_replace('~(/webhooks/\d+/)[A-Za-z0-9._%\-]+~', '$1[redacted]', $text) ?? $text;
    }
}
