<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\CaptionRules;
use App\Services\Social\Data\PublishResult;
use App\Services\Social\HandleClaimedException;
use App\Services\Social\HandleClaims;
use App\Services\Social\SocialConnectPending;
use App\Services\Social\SocialConnectRefused;
use App\Services\Social\Telegram\TelegramBotApi;
use App\Services\Social\Telegram\TelegramConnectCodes;
use App\Services\Social\Telegram\TelegramRateLimited;
use App\Services\Social\Telegram\TelegramResponse;
use App\Services\Social\Telegram\TelegramUnreachable;
use App\Support\SafeHttp;
use App\Support\UnsafeUrlException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Telegram channels and groups through ViewsMax's one shared bot.
 *
 * Connecting (no OAuth): the user asks for a one-time code, adds the bot to
 * the chat as an admin and posts "/connect <code>" there. The bot's webhook
 * records which chat the code appeared in (handleUpdate); the user's connect
 * call then checks that the message really came from an admin of that chat
 * and that the bot may post there, and only then creates the account. A chat
 * id or @username typed by the user is never accepted: the bot is an admin in
 * every connected chat, so that would let anyone post into anyone's chat.
 *
 * Publishing: text → sendMessage; one photo / video → sendPhoto / sendVideo
 * with the text as caption; 2-10 → sendMediaGroup, caption on the first item.
 * Media is downloaded (SafeHttp) and uploaded, never passed as a URL:
 * Telegram's limits are 10 MB photos / 50 MB other files for uploads but only
 * 5 MB / 20 MB when it fetches a URL itself. A caption longer than 1024
 * characters goes out as a text message right after the media.
 *
 * @see https://core.telegram.org/bots/api
 */
class TelegramProvider extends AbstractSocialProvider
{
    protected string $platform = 'telegram';

    public const TEXT_LIMIT = 4096;

    public const CAPTION_LIMIT = 1024;

    public const ALBUM_MAX = 10;

    public const PHOTO_MAX_BYTES = 10 * 1024 * 1024;

    public const VIDEO_MAX_BYTES = 50 * 1024 * 1024;

    /** Chat types the bot can publish to. Private chats with the bot are not a destination. */
    private const CHAT_TYPES = ['channel', 'group', 'supergroup'];

    public function __construct(protected TelegramBotApi $api, protected TelegramConnectCodes $codes) {}

    public function usesOAuth(): bool
    {
        return false;
    }

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        throw new RuntimeException('Telegram connects with a one-time code, not OAuth.');
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        throw new RuntimeException('Telegram connects with a one-time code, not OAuth.');
    }

    // ---------------------------------------------------------------- connect

    /**
     * One update from the bot's webhook. Only "/connect <code>" messages
     * carrying a live code are kept (as that code's sighting); everything
     * else the bot sees in its chats is ignored and never stored or logged.
     *
     * @param  array<string, mixed>  $update
     */
    public function handleUpdate(array $update): bool
    {
        $kind = isset($update['channel_post']) ? 'channel_post' : 'message';
        $message = $update[$kind] ?? null;
        if (! is_array($message) || ! isset($message['chat']['id'], $message['message_id'])) {
            return false;
        }

        $code = TelegramConnectCodes::codeFromCommand($message['text'] ?? null, $this->api->botUsername());
        if ($code === null || $this->codes->ownerOf($code) === null) {
            return false;
        }

        $chat = $message['chat'];

        return $this->codes->recordSighting($code, [
            'kind' => $kind,
            'chat_id' => (string) $chat['id'],
            'chat_type' => (string) ($chat['type'] ?? ''),
            'chat_title' => isset($chat['title']) ? (string) $chat['title'] : null,
            'chat_username' => isset($chat['username']) ? (string) $chat['username'] : null,
            'message_id' => (int) $message['message_id'],
            'from_id' => isset($message['from']['id']) ? (int) $message['from']['id'] : null,
            'sender_chat_id' => isset($message['sender_chat']['id']) ? (string) $message['sender_chat']['id'] : null,
            'automatic_forward' => (bool) ($message['is_automatic_forward'] ?? false),
        ]);
    }

    /**
     * Finish a connect: $credentials['code'] is the user's own one-time code.
     *
     * @param  array{code?: string}  $credentials
     *
     * @throws SocialConnectPending when the code hasn't been posted yet
     * @throws SocialConnectRefused with a message for the user
     */
    public function connectWithCredentials(User $user, array $credentials): Collection
    {
        $code = TelegramConnectCodes::normalize($credentials['code'] ?? null);
        if ($code === '') {
            throw new SocialConnectRefused('Get a connection code first, then post it in your channel or group.');
        }

        // One check per code at a time (the dialog's poll and a click can
        // overlap); everything below reads the code's state inside the lock.
        $lock = Cache::lock('telegram:connect:check:'.$code, 30);
        if (! $lock->get()) {
            throw new SocialConnectPending('Checking the code…');
        }

        try {
            return $this->finishConnect($user, $code);
        } finally {
            $lock->release();
        }
    }

    protected function finishConnect(User $user, string $code): Collection
    {
        // Codes are bound to the user who asked for them; someone else's
        // code (or a guessed one) connects nothing.
        if ($this->codes->ownerOf($code) !== $user->id) {
            throw new SocialConnectRefused('This connection code has expired or belongs to another account. Get a new code and try again.');
        }

        $seen = $this->codes->sighting($code);
        if ($seen === null) {
            throw new SocialConnectPending("We haven't seen /connect {$code} yet. Post it in your channel or group after adding {$this->bot()} as an admin.");
        }

        try {
            $this->assertDestination($code, $seen);
            $this->assertBotCanPost($seen);
            $this->assertPostedByAnAdmin($code, $seen);
        } catch (TelegramUnreachable) {
            throw new SocialConnectRefused('Telegram could not be reached. Try again in a moment.');
        }

        $username = $seen['chat_username'] ?: null;
        $account = $this->storeAccount($user, [
            'platform_account_id' => $seen['chat_id'],
            'name' => $seen['chat_title'] ?: ($username ? '@'.$username : 'Telegram chat'),
            'username' => $username,
            'profile_url' => $username ? 'https://t.me/'.$username : null,
            'access_token' => null,
            'token_expires_at' => null,
            'metadata' => ['chat_type' => $seen['chat_type']],
        ]);

        $this->codes->consume($code);
        $this->deleteConnectMessage($seen);

        Log::info('[telegram] chat connected', [
            'user_id' => $user->id, 'account_id' => $account->id, 'chat_type' => $seen['chat_type'],
        ]);

        return collect([$account]);
    }

    /** A channel or group; the code in a private chat with the bot proves nothing about a destination. */
    protected function assertDestination(string $code, array $seen): void
    {
        if (! in_array($seen['chat_type'], self::CHAT_TYPES, true)) {
            $this->codes->forgetSighting($code);

            throw new SocialConnectRefused('Post the code in the channel or group you want to publish to. ViewsMax can\'t publish to a private chat.');
        }
    }

    /**
     * The bot must be an admin of the chat, and in a channel it needs the
     * "Post messages" right. A failure here keeps the sighting: the user
     * fixes the bot's rights and presses Check again without reposting.
     */
    protected function assertBotCanPost(array $seen): void
    {
        $botId = $this->api->botId();
        if ($botId === null) {
            throw new SocialConnectRefused('Telegram publishing is not available right now. Try again later.');
        }

        $member = $this->api->call('getChatMember', ['chat_id' => $seen['chat_id'], 'user_id' => $botId]);
        $where = $this->chatName($seen);

        if (! $member->ok) {
            Log::warning('[telegram] connect: bot membership check failed', [
                'status' => $member->status, 'description' => $member->description,
            ]);

            throw new SocialConnectRefused(AbstractSocialProvider::isTransientStatus($member->status)
                ? 'Telegram is busy right now. Try again in a moment.'
                : "{$this->bot()} is not in {$where}. Add it as an administrator, then press Check again.");
        }

        $status = $member->result['status'] ?? null;
        if ($status !== 'administrator' && $status !== 'creator') {
            throw new SocialConnectRefused("Make {$this->bot()} an administrator of {$where}, then press Check again.");
        }

        if ($seen['chat_type'] === 'channel' && $status === 'administrator' && ($member->result['can_post_messages'] ?? false) !== true) {
            throw new SocialConnectRefused("Give {$this->bot()} the \"Post messages\" admin right in {$where}, then press Check again.");
        }
    }

    /**
     * Proof that the person holding the code controls the chat:
     *  - channel: only admins with posting rights can post (channel_post);
     *  - group: the sender must be an admin (getChatMember), or an anonymous
     *    admin, whose message is sent on behalf of the group itself. A message
     *    sent as some other channel, or auto-forwarded from a linked channel,
     *    proves nothing about the group.
     */
    protected function assertPostedByAnAdmin(string $code, array $seen): void
    {
        $where = $this->chatName($seen);
        $refuse = function () use ($code, $where) {
            $this->codes->forgetSighting($code);

            return new SocialConnectRefused("The code must be posted by an administrator of {$where}. Ask an admin to post it, or post it yourself if you are one.");
        };

        if ($seen['chat_type'] === 'channel') {
            if ($seen['kind'] !== 'channel_post') {
                throw $refuse();
            }

            return;
        }

        if ($seen['automatic_forward']) {
            throw $refuse();
        }

        if ($seen['sender_chat_id'] !== null) {
            if ($seen['sender_chat_id'] !== $seen['chat_id']) {
                throw $refuse();
            }

            return; // anonymous admin
        }

        if ($seen['from_id'] === null) {
            throw $refuse();
        }

        $member = $this->api->call('getChatMember', ['chat_id' => $seen['chat_id'], 'user_id' => $seen['from_id']]);
        if (! $member->ok) {
            Log::warning('[telegram] connect: sender membership check failed', [
                'status' => $member->status, 'description' => $member->description,
            ]);

            throw new SocialConnectRefused('Could not check who posted the code. Try again in a moment.');
        }

        if (! in_array($member->result['status'] ?? null, ['creator', 'administrator'], true)) {
            throw $refuse();
        }
    }

    /** Best effort: the code has done its job, so take it out of the chat. */
    protected function deleteConnectMessage(array $seen): void
    {
        try {
            $deleted = $this->api->call('deleteMessage', ['chat_id' => $seen['chat_id'], 'message_id' => $seen['message_id']]);
            if (! $deleted->ok) {
                Log::info('[telegram] could not delete the /connect message', ['description' => $deleted->description]);
            }
        } catch (TelegramUnreachable) {
            // Already logged by the client; the account is connected either way.
        }
    }

    // ---------------------------------------------------------------- publish

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $text = (string) $post->content;
        $hasText = trim($text) !== '';
        $length = CaptionRules::lengthFor('telegram', $text);
        $media = array_values(array_filter(
            $this->mediaItems($post),
            fn (array $m) => in_array($m['type'], ['image', 'video'], true),
        ));

        if (! $hasText && $media === []) {
            return PublishResult::failure('Add text, a photo or a video — there is nothing to post to Telegram.');
        }
        if (count($media) > self::ALBUM_MAX) {
            return PublishResult::failure('Telegram albums hold at most '.self::ALBUM_MAX.' photos and videos; this post has '.count($media).'. Remove some and try again.');
        }
        if ($length > self::TEXT_LIMIT) {
            return PublishResult::failure('Telegram messages are limited to '.self::TEXT_LIMIT." characters; this one has {$length}.");
        }

        // With media, the text rides along as the caption when it fits;
        // otherwise it follows the media as its own message.
        $caption = $hasText && $length <= self::CAPTION_LIMIT ? $text : null;
        $textAfterMedia = $media !== [] && $hasText && $caption === null;

        try {
            if ($media === []) {
                [$response, $chatId] = $this->send($account, 'sendMessage', ['text' => $text]);
            } else {
                $files = [];
                foreach ($media as $item) {
                    $file = $this->download($item);
                    if (is_string($file)) {
                        return PublishResult::failure($file);
                    }
                    $files[] = $file;
                }
                [$response, $chatId] = $this->sendMedia($account, $files, $caption);
            }
        } catch (TelegramUnreachable) {
            throw new TransientPublishException('Telegram could not be reached — retrying automatically.');
        }

        if (! $response->ok) {
            return $this->publishFailure($account, $response);
        }

        // From here on nothing throws: the post is live.
        $messages = array_is_list((array) $response->result) ? (array) $response->result : [$response->result];
        $messageId = (string) ($messages[0]['message_id'] ?? '');

        if ($textAfterMedia && ($error = $this->sendTextAfterMedia($account, $chatId, $text)) !== null) {
            return PublishResult::failure($error, $response->body);
        }

        return PublishResult::success(
            $messageId !== '' ? $messageId : null,
            $this->messageUrl($account, $chatId, $messageId),
            $response->body,
        );
    }

    /**
     * Call a send method on the account's chat. A basic group that became a
     * supergroup answers with migrate_to_chat_id: the account moves to the
     * new id and the call is made once more there.
     *
     * @param  array<string, array{0: string, 1: string}>  $files
     * @return array{0: TelegramResponse, 1: string} the response and the chat id it went to
     */
    protected function send(SocialAccount $account, string $method, array $params, array $files = []): array
    {
        $chatId = (string) $account->platform_account_id;
        $timeout = $files === [] ? 30 : 300;
        $response = $this->api->call($method, ['chat_id' => $chatId] + $params, $files, $timeout);

        if (! $response->ok && $response->migrateToChatId !== null) {
            $chatId = $this->moveToSupergroup($account, $response->migrateToChatId);
            $response = $this->api->call($method, ['chat_id' => $chatId] + $params, $files, $timeout);
        }

        return [$response, $chatId];
    }

    /**
     * @param  list<array{body: string, filename: string, type: string}>  $files
     * @return array{0: TelegramResponse, 1: string}
     */
    protected function sendMedia(SocialAccount $account, array $files, ?string $caption): array
    {
        if (count($files) === 1) {
            $file = $files[0];
            $field = $file['type'] === 'video' ? 'video' : 'photo';

            return $this->send($account, $file['type'] === 'video' ? 'sendVideo' : 'sendPhoto', array_filter([
                'caption' => $caption,
                'supports_streaming' => $file['type'] === 'video' ? true : null,
            ], fn ($v) => $v !== null), [$field => [$file['body'], $file['filename']]]);
        }

        $items = [];
        $attachments = [];
        foreach ($files as $i => $file) {
            $items[] = array_filter([
                'type' => $file['type'] === 'video' ? 'video' : 'photo',
                'media' => "attach://file{$i}",
                'caption' => $i === 0 ? $caption : null,
                'supports_streaming' => $file['type'] === 'video' ? true : null,
            ], fn ($v) => $v !== null);
            $attachments["file{$i}"] = [$file['body'], $file['filename']];
        }

        return $this->send($account, 'sendMediaGroup', ['media' => $items], $attachments);
    }

    /**
     * The long caption, as a message right after the media. The media is
     * already live, so nothing may throw; one short wait is allowed when
     * flood control (one message a second per chat) answers 429.
     *
     * @return string|null an error for the user, or null when the text went out
     */
    protected function sendTextAfterMedia(SocialAccount $account, string $chatId, string $text): ?string
    {
        $response = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $this->api->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
            } catch (TelegramUnreachable) {
                $response = null;
            }

            if ($response?->ok) {
                return null;
            }
            if ($attempt === 1 && $response?->status === 429 && ($response->retryAfter ?? PHP_INT_MAX) <= 10) {
                Sleep::for(max(1, $response->retryAfter))->seconds();

                continue;
            }
            break;
        }

        $reason = $response?->description ?? 'Telegram could not be reached';
        Log::error('[telegram] media posted but the text after it failed', [
            'account_id' => $account->id, 'status' => $response?->status, 'description' => $reason,
        ]);

        return "The media was posted to Telegram, but the text that follows it could not be sent ({$reason}). "
            .'Post the text in the chat yourself: retrying would post the media again.';
    }

    /**
     * Download one photo or video to upload it. Through SafeHttp (media links
     * come from users), capped at Telegram's upload limit.
     *
     * @param  array{url: string, type: string}  $item
     * @return array{body: string, filename: string, type: string}|string the file, or an error for the user
     */
    protected function download(array $item): array|string
    {
        $isVideo = $item['type'] === 'video';
        $noun = $isVideo ? 'video' : 'photo';

        try {
            $file = SafeHttp::get($item['url'], $isVideo ? self::VIDEO_MAX_BYTES : self::PHOTO_MAX_BYTES, $isVideo ? 300 : 60);
        } catch (UnsafeUrlException $e) {
            Log::warning('[telegram] media download refused', ['url' => $item['url'], 'reason' => $e->getMessage()]);
            $limit = str_contains(strtolower($e->getMessage()), 'too large')
                ? ($isVideo ? ' Telegram bots can send videos up to 50 MB.' : ' Telegram bots can send photos up to 10 MB.')
                : '';

            return "Could not use the {$noun} at {$item['url']}, nothing was published: {$e->getMessage()}{$limit}";
        }

        return ['body' => $file['body'], 'filename' => $noun.'.'.$this->extension($file['content_type'], $isVideo), 'type' => $item['type']];
    }

    protected function extension(?string $contentType, bool $isVideo): string
    {
        $mime = strtolower(trim(explode(';', (string) $contentType)[0]));

        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => $isVideo ? 'mp4' : 'jpg',
        };
    }

    /**
     * Turn a refused send into a result: retry on flood control / outages,
     * flag the account when the bot lost its place in the chat, otherwise a
     * failure carrying Telegram's own reason.
     */
    protected function publishFailure(SocialAccount $account, TelegramResponse $response): PublishResult
    {
        $context = ['account_id' => $account->id, 'status' => $response->status, 'description' => $response->description];

        if ($response->status === 429) {
            Log::warning('[telegram] flood control; job will retry', $context + ['retry_after' => $response->retryAfter]);

            throw new TelegramRateLimited(max(1, $response->retryAfter ?? 30));
        }

        if (static::isTransientStatus($response->status)) {
            Log::warning('[telegram] temporary error; job will retry', $context);

            throw new TransientPublishException("Telegram returned a temporary error (HTTP {$response->status}) — retrying automatically.");
        }

        // 401 (token revoked or wrong) / 404 (no such bot): our configuration,
        // not the user's chat. The account stays connected.
        if (in_array($response->status, [401, 404], true)) {
            Log::error('[telegram] the Bot API rejected the bot token', $context);

            return PublishResult::failure('Telegram rejected the ViewsMax bot, so nothing was posted. This is a problem on our side; please try again later.');
        }

        if ($this->botLostAccess($response)) {
            Log::warning('[telegram] bot can no longer post in the chat; account needs reconnect', $context);
            $message = "{$this->bot()} can no longer post in {$this->accountName($account)} ({$response->description}). "
                ."Add {$this->bot()} back as an administrator that can post messages, then reconnect the chat in ViewsMax.";
            $account->markNeedsReauth($message);

            return PublishResult::failure($message, $response->body);
        }

        if ($response->status === 413) {
            Log::warning('[telegram] upload too large', $context);

            return PublishResult::failure('That file is too large for Telegram (photos up to 10 MB, videos up to 50 MB). Nothing was posted.', $response->body);
        }

        Log::error('[telegram] post refused', $context);

        return PublishResult::failure("Telegram refused the post, nothing was published: {$response->description}", $response->body);
    }

    /**
     * Telegram's answers for "the bot isn't allowed here anymore": 403 (kicked,
     * not a member) and 400s about the chat or the bot's rights. A bot removed
     * from a basic group can get 400 PEER_ID_INVALID instead of 403.
     */
    protected function botLostAccess(TelegramResponse $response): bool
    {
        if ($response->status === 403) {
            return true;
        }

        return $response->status === 400 && (bool) preg_match(
            '/chat not found|not enough rights|need administrator rights|have no rights|bot is not a member|bot was kicked|chat_write_forbidden|chat_admin_required|chat_restricted|group chat was deactivated|peer_id_invalid/i',
            $response->description,
        );
    }

    /**
     * Point the account at the supergroup a basic group became. Keeps the
     * old row when the user already connected the supergroup separately.
     */
    protected function moveToSupergroup(SocialAccount $account, string $newChatId): string
    {
        $exists = SocialAccount::where('user_id', $account->user_id)
            ->where('platform', $this->platform)
            ->where('platform_account_id', $newChatId)
            ->exists();

        if (! $exists && $account->user) {
            try {
                HandleClaims::claim($account->user, $this->platform, $newChatId, $account->username ?: $account->name);
                $account->update([
                    'platform_account_id' => $newChatId,
                    'metadata' => array_merge($account->metadata ?? [], ['chat_type' => 'supergroup']),
                ]);
            } catch (HandleClaimedException) {
                // Someone else connected the new chat; still post where Telegram says the chat lives now.
            }
        }

        Log::info('[telegram] group became a supergroup', ['account_id' => $account->id, 'moved' => ! $exists]);

        return $newChatId;
    }

    /** Link to a message: t.me/<username>/<id> for public chats, t.me/c/<id>/<id> for private supergroups and channels. */
    protected function messageUrl(SocialAccount $account, string $chatId, string $messageId): ?string
    {
        if ($messageId === '') {
            return null;
        }
        if ($account->username) {
            return "https://t.me/{$account->username}/{$messageId}";
        }
        if (str_starts_with($chatId, '-100')) {
            return 'https://t.me/c/'.substr($chatId, 4)."/{$messageId}";
        }

        return null; // basic groups have no message links
    }

    protected function bot(): string
    {
        return '@'.$this->api->botUsername();
    }

    protected function chatName(array $seen): string
    {
        return $seen['chat_title'] ? "\"{$seen['chat_title']}\"" : 'that chat';
    }

    protected function accountName(SocialAccount $account): string
    {
        return $account->name ? "\"{$account->name}\"" : 'the chat';
    }
}
