<?php

namespace App\Services\Social\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only code that talks to the Telegram Bot API, for the one shared
 * ViewsMax bot (config social.platforms.telegram).
 *
 * The bot token is part of every request URL (https://api.telegram.org/bot<token>/<method>),
 * so the URL is never logged, never put in an exception message and never
 * returned: a failed connection becomes TelegramUnreachable with a fixed
 * message, and anything else that could carry the token goes through redact().
 * The HTTP client's events still see the URL; config/sentry.php scrubs it
 * from breadcrumbs and spans (App\Support\SentryScrubber).
 *
 * @see https://core.telegram.org/bots/api#making-requests
 */
class TelegramBotApi
{
    public const BASE_URL = 'https://api.telegram.org';

    /**
     * Call a Bot API method. Plain parameters go as JSON; with $files the
     * request is multipart and each file is [binary, filename] under its
     * field name (e.g. 'photo', or 'file0' for an album's attach://file0).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, array{0: string, 1: string}>  $files
     *
     * @throws TelegramUnreachable when no answer came back (network, timeout, TLS)
     */
    public function call(string $method, array $params = [], array $files = [], int $timeout = 30): TelegramResponse
    {
        $url = self::BASE_URL.'/bot'.$this->token().'/'.$method;

        try {
            $request = Http::timeout($timeout)->connectTimeout(10)->acceptJson();

            if ($files === []) {
                $response = $request->asJson()->post($url, $params);
            } else {
                $request = $request->asMultipart();
                foreach ($files as $field => [$body, $filename]) {
                    $request = $request->attach($field, $body, $filename);
                }
                $response = $request->post($url, self::multipartFields($params));
            }
        } catch (Throwable $e) {
            // ConnectionException's message ends with the full request URL.
            Log::warning('[telegram] Bot API unreachable', [
                'method' => $method,
                'error' => self::redact($e->getMessage()),
            ]);

            throw new TelegramUnreachable($method);
        }

        return TelegramResponse::from($response);
    }

    /** The bot's own user id (getMe), cached for a day; null when Telegram won't say. */
    public function botId(): ?int
    {
        $key = 'telegram:bot-id:'.hash('sha256', $this->token());
        if ($id = Cache::get($key)) {
            return (int) $id;
        }

        $me = $this->call('getMe');
        $id = $me->ok ? (int) ($me->result['id'] ?? 0) : 0;
        if ($id <= 0) {
            Log::error('[telegram] getMe failed; check TELEGRAM_BOT_TOKEN', [
                'status' => $me->status, 'description' => $me->description,
            ]);

            return null;
        }

        Cache::put($key, $id, now()->addDay());

        return $id;
    }

    /** The bot's @username without the "@", as configured. */
    public function botUsername(): string
    {
        return ltrim(trim((string) config('social.platforms.telegram.bot_username')), '@');
    }

    /**
     * The secret Telegram sends back in X-Telegram-Bot-Api-Secret-Token on
     * every webhook call. Derived from the bot token, so there is no extra
     * setting: whoever knows the token controls the bot anyway. 64 hex chars
     * (Telegram allows 1-256 of A-Z, a-z, 0-9, _ and -).
     */
    public function webhookSecret(): string
    {
        return hash_hmac('sha256', 'viewsmax-telegram-webhook', $this->token());
    }

    /**
     * Remove any bot token from a string bound for a log, an exception or a
     * stored error: the configured token wherever it appears, and anything
     * shaped like one in a Bot API URL ("bot123456:AA...", also URL-encoded).
     */
    public static function redact(string $text): string
    {
        $token = (string) config('social.platforms.telegram.bot_token');
        if ($token !== '') {
            $text = str_replace([$token, rawurlencode($token)], '[redacted]', $text);
        }

        return preg_replace('~bot\d+(?::|%3A)[A-Za-z0-9_-]+~i', 'bot[redacted]', $text) ?? '';
    }

    protected function token(): string
    {
        return trim((string) config('social.platforms.telegram.bot_token'));
    }

    /**
     * Multipart parts are strings: arrays (an album's media list, reply
     * markup) go as JSON, booleans as true/false, nulls are left out.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    protected static function multipartFields(array $params): array
    {
        $fields = [];
        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }
            $fields[$name] = match (true) {
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return $fields;
    }
}
