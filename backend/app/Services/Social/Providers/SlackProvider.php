<?php

namespace App\Services\Social\Providers;

use App\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Data\PublishResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Slack publishing through a bot token (OAuth v2, granular bot scopes).
 *
 * The `incoming-webhook` scope makes Slack's own consent screen ask the user
 * for a channel, so one connected account is one (workspace, channel) pair:
 * platform_account_id = "{team_id}:{channel_id}". The webhook URL itself is
 * never used or stored; the bot token posts to that channel instead, which
 * returns a message ts (and so a permalink) and can upload files.
 *
 *  - text: chat.postMessage
 *  - images / video: files.getUploadURLExternal → POST the bytes to the
 *    returned URL → files.completeUploadExternal with the caption as the
 *    initial comment (files.upload is retired)
 *
 * Slack answers most failures with HTTP 200 and {"ok": false, "error": "..."},
 * so every call is judged by `ok` and the error string, never by status alone.
 * Bot tokens don't expire unless the app turns on token rotation, which this
 * integration refuses at connect time (see SLACK_SETUP.md).
 */
class SlackProvider extends AbstractSocialProvider
{
    protected string $platform = 'slack';

    public const API = 'https://slack.com/api/';

    public const AUTHORIZE_URL = 'https://slack.com/oauth/v2/authorize';

    /** Files shared in one message (completeUploadExternal takes a list). */
    public const MAX_FILES = 10;

    /** Scopes publishing can't work without; chat:write.public only widens where it can post. */
    public const REQUIRED_SCOPES = ['incoming-webhook', 'chat:write', 'files:write'];

    /** The token is dead or was removed with the app: the user must reconnect. */
    public const REAUTH_ERRORS = ['invalid_auth', 'not_authed', 'token_revoked', 'token_expired', 'account_inactive', 'missing_scope'];

    /** The bot can't see the channel (a private channel it isn't in, or a deleted one). */
    public const CHANNEL_ERRORS = ['not_in_channel', 'channel_not_found', 'invalid_channel'];

    /** A workspace setting or an admin stops the app from posting there. */
    public const PERMISSION_ERRORS = ['restricted_action', 'posting_to_channel_denied', 'no_permission', 'ekm_access_denied', 'team_access_not_granted', 'access_denied', 'user_is_external_guest'];

    /** Slack refused the content itself. */
    public const CONTENT_ERRORS = [
        'msg_too_long', 'msg_blocks_too_long', 'no_text', 'alt_txt_too_large',
        'file_type_not_allowed', 'blocked_file_type', 'file_uploads_disabled', 'file_uploads_except_images_disabled',
        'file_upload_size_restricted', 'storage_limit_reached',
        'slack_connect_blocked_file_type', 'slack_connect_file_upload_sharing_blocked', 'slack_connect_clip_sharing_blocked',
    ];

    /**
     * Retrying is safe: Slack did nothing. Excludes internal_error and
     * fatal_error, after which "partial success is possible" (Slack's docs) —
     * an automatic retry could post the message twice.
     */
    public const TRANSIENT_ERRORS = ['ratelimited', 'rate_limited', 'service_unavailable', 'request_timeout'];

    public function getAuthorizationUrl(string $redirectUri, string $state, array $options = []): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'scope' => $this->scopeString(','),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function connectFromCode(User $user, string $code, string $redirectUri, array $options = []): Collection
    {
        // Slack recommends HTTP Basic for the app credentials.
        $response = Http::asForm()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->acceptJson()
            ->timeout(30)
            ->post(self::API.'oauth.v2.access', [
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        $data = (array) ($response->json() ?? []);
        if (! $response->successful() || ($data['ok'] ?? false) !== true) {
            $error = (string) ($data['error'] ?? 'HTTP '.$response->status());
            Log::error('[slack] token exchange failed', ['status' => $response->status(), 'error' => $error]);

            throw new RuntimeException(match ($error) {
                'invalid_code', 'code_already_used' => 'The Slack approval expired before it was used. Connect again.',
                'bad_redirect_uri', 'oauth_authorization_url_mismatch', 'invalid_client_id', 'bad_client_secret' => 'Slack refused this app\'s settings ('.$error.'). Please contact support.',
                default => 'Slack refused the connection ('.$error.').',
            });
        }

        // Token rotation makes bot tokens expire after 12 hours and needs a
        // refresh flow this integration doesn't have; accepting it would leave
        // an account that quietly dies overnight.
        if (! empty($data['refresh_token'])) {
            Log::error('[slack] token rotation is on for the Slack app; turn it off (see SLACK_SETUP.md)');

            throw new RuntimeException('This Slack app has token rotation turned on, which ViewsMax does not support yet. Please contact support.');
        }

        $token = (string) ($data['access_token'] ?? '');
        if ($token === '' || ($data['token_type'] ?? 'bot') !== 'bot') {
            Log::error('[slack] token exchange returned no bot token', ['token_type' => $data['token_type'] ?? null]);

            throw new RuntimeException('Slack did not return a bot token. Connect again.');
        }

        $granted = array_values(array_filter(array_map('trim', explode(',', (string) ($data['scope'] ?? '')))));
        $this->assertGrantedScopes($granted, self::REQUIRED_SCOPES);

        // The channel picked on Slack's consent screen. An org-wide Enterprise
        // Grid install has none; there is nowhere to post, so it's refused.
        $hook = (array) ($data['incoming_webhook'] ?? []);
        $channelId = (string) ($hook['channel_id'] ?? '');
        $teamId = (string) (data_get($data, 'team.id') ?: data_get($data, 'enterprise.id') ?: '');
        if ($channelId === '' || $teamId === '') {
            Log::error('[slack] token exchange returned no channel or workspace', [
                'has_channel' => $channelId !== '', 'has_team' => $teamId !== '',
                'is_enterprise_install' => $data['is_enterprise_install'] ?? null,
            ]);

            throw new RuntimeException('Slack did not say which channel to post to. Connect again and pick a channel on Slack\'s permission screen (a workspace, not a whole organization).');
        }

        $channelName = ltrim((string) ($hook['channel'] ?? ''), '#') ?: $channelId;
        $teamName = (string) (data_get($data, 'team.name') ?: data_get($data, 'enterprise.name') ?: $teamId);

        $account = $this->storeAccount($user, [
            'platform_account_id' => $teamId.':'.$channelId,
            'name' => '#'.$channelName.' · '.$teamName,
            'username' => null,
            'avatar_url' => null,
            'profile_url' => 'https://app.slack.com/client/'.rawurlencode($teamId).'/'.rawurlencode($channelId),
            'access_token' => $token,
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => $granted ?: $this->config('scopes', []),
            'metadata' => array_filter([
                'team_id' => $teamId,
                'team_name' => $teamName,
                'channel_id' => $channelId,
                'channel_name' => $channelName,
                'bot_user_id' => $data['bot_user_id'] ?? null,
                'app_id' => $data['app_id'] ?? null,
                'enterprise_id' => data_get($data, 'enterprise.id'),
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        // One app install per workspace means one bot token, shared by every
        // channel connected from it. Keep the workspace's other channels on
        // the token Slack just issued, in case it differs from the stored one.
        $this->workspaceAccounts($teamId)
            ->whereKeyNot($account->id)
            ->get()
            ->each(function (SocialAccount $other) use ($token) {
                if ($other->access_token !== $token) {
                    $other->update(['access_token' => $token]);
                }
            });

        return collect([$account]);
    }

    /**
     * Disconnect one channel. The bot token belongs to the whole workspace
     * install, so it is revoked (best effort) only when no other connected
     * channel of that workspace — this user's or anyone's — still uses it.
     */
    public function disconnect(SocialAccount $account): void
    {
        $teamId = (string) $account->meta('team_id', strtok((string) $account->platform_account_id, ':'));
        $token = $account->access_token;
        $context = ['account_id' => $account->id, 'team_id' => $teamId];

        $account->delete();

        if (! $token || $teamId === '' || $this->workspaceAccounts($teamId)->exists()) {
            Log::info('[slack] channel disconnected; token kept', $context + ['reason' => $token ? 'workspace still connected' : 'no token']);

            return;
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->asForm()->post(self::API.'auth.revoke');
        } catch (\Throwable $e) {
            Log::error('[slack] auth.revoke request failed', $context + ['error' => $e->getMessage()]);

            return;
        }

        $error = $response->json('error');
        // A token Slack no longer knows (app removed, already revoked) is gone already.
        if ($response->json('ok') === true || in_array($error, ['invalid_auth', 'token_revoked', 'account_inactive', 'not_authed'], true)) {
            Log::info('[slack] bot token revoked', $context + ['revoked' => $response->json('revoked'), 'error' => $error]);

            return;
        }

        Log::error('[slack] auth.revoke refused', $context + ['status' => $response->status(), 'error' => $error]);
    }

    /** Every connected Slack channel (any user) of one workspace. */
    protected function workspaceAccounts(string $teamId)
    {
        return SocialAccount::query()
            ->where('platform', 'slack')
            ->where('platform_account_id', 'like', $teamId.':%');
    }

    public function publish(SocialAccount $account, SocialPost $post): PublishResult
    {
        $channel = (string) $account->meta('channel_id', '');
        if ($channel === '') {
            return PublishResult::failure('This Slack account has no channel on record. Reconnect it and pick the channel.');
        }

        $text = self::escapeText(trim((string) $post->content));
        $media = array_slice($this->mediaItems($post), 0, self::MAX_FILES);
        if ($text === '' && $media === []) {
            return PublishResult::failure('Slack needs a caption or media to post.');
        }

        $log = ['account_id' => $account->id, 'channel' => $channel, 'files' => count($media)];
        Log::info('[slack] publish', $log);

        if ($media === []) {
            $response = $this->api($account)->asJson()->post(self::API.'chat.postMessage', [
                'channel' => $channel,
                'text' => $text,
            ]);
            if ($failure = $this->failureFor($account, $response, 'post message')) {
                return $failure;
            }

            $ts = (string) $response->json('ts');
            $channel = (string) ($response->json('channel') ?: $channel);

            return PublishResult::success($ts, $this->permalink($account, $channel, $ts), (array) $response->json());
        }

        $files = [];
        foreach ($media as $item) {
            [$file, $failure] = $this->uploadFile($account, $item);
            if ($failure) {
                return $failure;
            }
            $files[] = $file;
        }

        $complete = $this->api($account)->asJson()->post(self::API.'files.completeUploadExternal', array_filter([
            'files' => $files,
            'channel_id' => $channel,
            'initial_comment' => $text !== '' ? $text : null,
        ]));
        if ($failure = $this->failureFor($account, $complete, 'share files')) {
            return $failure;
        }

        // Sharing can finish after this call returns, so the message ts is
        // only there when Slack already included the share. Without it the
        // first file stands in: its id and permalink.
        $first = (array) $complete->json('files.0', []);
        $ts = $this->shareTs($first, $channel);
        $url = $ts ? $this->permalink($account, $channel, $ts) : null;

        return PublishResult::success(
            $ts ?? (string) ($first['id'] ?? $files[0]['id']),
            $url ?? ($first['permalink'] ?? null),
            (array) $complete->json()
        );
    }

    /**
     * Slack treats &, < and > as control characters (<!channel>, <@U123>,
     * <url|label>). Escaping exactly those three keeps a caption literal: it
     * can't ping the channel or forge a link, and "a < b & c" reads as typed.
     * Slack decodes only these three entities, so nothing else is touched.
     */
    public static function escapeText(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    protected function api(SocialAccount $account): PendingRequest
    {
        return Http::withToken((string) $account->access_token)->acceptJson()->timeout(30);
    }

    /**
     * Download one media item (through SafeHttp: media links come from users),
     * reserve an upload, send the bytes. Returns [{id, title}, failure].
     *
     * @param  array{url:string,type:string,mime:?string,alt:?string}  $item
     * @return array{0: ?array{id:string, title:string}, 1: ?PublishResult}
     */
    protected function uploadFile(SocialAccount $account, array $item): array
    {
        $download = $this->fetchMedia($item['url'], $item['type']);
        if ($download === null) {
            return [null, PublishResult::failure('Slack upload failed: the '.$item['type'].' could not be downloaded from '.$item['url'].'.')];
        }

        $filename = $this->filenameFor($item['url'], $item['type']);
        $reserve = $this->api($account)->asForm()->post(self::API.'files.getUploadURLExternal', array_filter([
            'filename' => $filename,
            'length' => strlen($download['body']),
            'alt_txt' => $item['type'] === 'image' && ! empty($item['alt']) ? (string) $item['alt'] : null,
        ], fn ($v) => $v !== null));
        if ($failure = $this->failureFor($account, $reserve, 'start file upload')) {
            return [null, $failure];
        }

        $upload = Http::timeout(300)
            ->withBody($download['body'], 'application/octet-stream')
            ->post((string) $reserve->json('upload_url'));
        if (! $upload->successful()) {
            $this->throwIfTransient($upload, 'file upload');
            Log::error('[slack] file upload failed', ['account_id' => $account->id, 'status' => $upload->status(), 'body' => $this->bodyExcerpt($upload)]);

            return [null, PublishResult::failure('Slack file upload failed (HTTP '.$upload->status().').')];
        }

        return [['id' => (string) $reserve->json('file_id'), 'title' => $filename], null];
    }

    /** The ts of the file's share in $channel, when Slack's response already has it. */
    protected function shareTs(array $file, string $channel): ?string
    {
        foreach (['public', 'private'] as $kind) {
            $ts = data_get($file, "shares.{$kind}.{$channel}.0.ts");
            if (is_string($ts) && $ts !== '') {
                return $ts;
            }
        }

        return null;
    }

    /** The message's permalink; null when Slack won't say (the post itself is fine). */
    protected function permalink(SocialAccount $account, string $channel, string $ts): ?string
    {
        if ($ts === '') {
            return null;
        }

        $response = $this->api($account)->get(self::API.'chat.getPermalink', ['channel' => $channel, 'message_ts' => $ts]);
        if ($response->json('ok') === true) {
            return $response->json('permalink');
        }

        Log::error('[slack] permalink lookup failed after a successful post', [
            'account_id' => $account->id, 'status' => $response->status(), 'error' => $response->json('error'),
        ]);

        return null;
    }

    /**
     * Null when the call succeeded. Otherwise the user-facing failure for
     * Slack's error string, after flagging the account when its token is
     * dead. Rate limits and outages throw for the queue to retry.
     *
     * @throws TransientPublishException
     */
    protected function failureFor(SocialAccount $account, Response $response, string $action): ?PublishResult
    {
        if ($response->successful() && $response->json('ok') === true) {
            return null;
        }

        $error = (string) ($response->json('error') ?? '');
        $context = ['account_id' => $account->id, 'action' => $action, 'status' => $response->status(), 'error' => $error];

        if (AbstractSocialProvider::isTransientStatus($response->status()) || in_array($error, self::TRANSIENT_ERRORS, true)) {
            Log::warning('[slack] '.$action.' failed transiently; queue will retry', $context);
            $limited = $response->status() === 429 || in_array($error, ['ratelimited', 'rate_limited'], true);
            $e = new TransientPublishException($limited
                ? 'Slack rate limit hit during '.$action.' — retrying automatically.'
                : 'Slack returned a temporary error during '.$action.' — retrying automatically.', $response->status());
            $retryAfter = (int) $response->header('Retry-After');
            $e->retryAfter = $retryAfter > 0 ? $retryAfter : null;

            throw $e;
        }

        if ($this->failReauthIfUnauthorized($account, $response, $action, fn (Response $r) => in_array($r->json('error'), self::REAUTH_ERRORS, true))) {
            return PublishResult::failure(
                $error === 'missing_scope'
                    ? 'Slack says the app is missing a permission. Reconnect the Slack account to grant it.'
                    : 'Slack authorization expired or the app was removed from the workspace — reconnect the Slack account.',
                (array) $response->json()
            );
        }

        $channelName = '#'.$account->meta('channel_name', $account->meta('channel_id'));
        $message = match (true) {
            in_array($error, self::CHANNEL_ERRORS, true) => "The app can't post in {$channelName}. If it's a private channel, add the app to it in Slack (type /invite in the channel and pick the app), then retry. If the channel was deleted, connect another one.",
            $error === 'is_archived' => "{$channelName} is archived in Slack. Unarchive it, or connect another channel.",
            in_array($error, self::PERMISSION_ERRORS, true) => "Your Slack workspace settings don't let the app post in {$channelName} ({$error}). Ask a workspace admin.",
            in_array($error, ['msg_too_long', 'msg_blocks_too_long'], true) => 'The caption is too long for Slack. Shorten it and retry.',
            in_array($error, self::CONTENT_ERRORS, true) => "Slack refused the post's content ({$error}).",
            in_array($error, ['internal_error', 'fatal_error'], true) => "Slack had an internal error ({$error}). Check {$channelName} before retrying — the post may have gone through.",
            default => 'Slack refused the post ('.($error !== '' ? $error : 'HTTP '.$response->status()).').',
        };

        Log::error('[slack] '.$action.' failed', $context + ['body' => $this->bodyExcerpt($response)]);

        return PublishResult::failure($message, (array) ($response->json() ?? []));
    }

    /** A filename Slack can show (and infer the type from). */
    protected function filenameFor(string $url, string $type): string
    {
        $name = basename(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $name = preg_replace('/[^\w.\- ]+/u', '', $name) ?? '';

        return $name !== '' && str_contains($name, '.') ? mb_substr($name, -120) : ($type === 'video' ? 'video.mp4' : 'image.jpg');
    }
}
