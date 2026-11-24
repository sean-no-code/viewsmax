<?php

namespace App\Services\Social\Telegram;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One-time codes that prove a ViewsMax user controls a Telegram chat.
 *
 * A code is random, lives TTL_MINUTES, belongs to the user who asked for it
 * and is kept in the cache only (no table). Asking again replaces the user's
 * previous code. When the bot's webhook sees "/connect <code>" in a chat, the
 * chat is remembered against that code (a "sighting"); the owner's connect
 * call then verifies it and consumes the code.
 *
 * Every code has its own sighting slot, so any number of users can be
 * connecting at once without touching each other's state.
 */
class TelegramConnectCodes
{
    public const TTL_MINUTES = 15;

    /** No 0/O or 1/I/L: the code is read off a screen and typed into Telegram. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    /**
     * @return array{code: string, expires_at: Carbon}
     */
    public function issue(User $user): array
    {
        if ($previous = Cache::get($this->userKey($user->id))) {
            $this->consume((string) $previous);
        }

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        do {
            $code = $this->randomCode();
        } while (! Cache::add($this->codeKey($code), ['user_id' => $user->id, 'expires_at' => $expiresAt->getTimestamp()], $expiresAt));

        Cache::put($this->userKey($user->id), $code, $expiresAt);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * The user's live code, or a new one. For a page that renders the code
     * on every load (the agent setup page): a reload must not invalidate a
     * code the user has already posted.
     *
     * @return array{code: string, expires_at: Carbon}
     */
    public function currentOrIssue(User $user): array
    {
        $code = Cache::get($this->userKey($user->id));
        $entry = is_string($code) ? Cache::get($this->codeKey($code)) : null;

        if (is_array($entry) && $this->ownerOf($code) === $user->id) {
            return ['code' => $code, 'expires_at' => Carbon::createFromTimestamp((int) $entry['expires_at'])];
        }

        return $this->issue($user);
    }

    /** The id of the user the live code belongs to, or null (unknown or expired). */
    public function ownerOf(string $code): ?int
    {
        $entry = $code === '' ? null : Cache::get($this->codeKey($code));

        return is_array($entry) && ($entry['expires_at'] ?? 0) > now()->getTimestamp()
            ? (int) $entry['user_id']
            : null;
    }

    /**
     * Remember where the code was posted. The first sighting wins: a code is
     * shown only to its owner, so a second one is the owner posting twice.
     *
     * @param  array<string, mixed>  $sighting
     */
    public function recordSighting(string $code, array $sighting): bool
    {
        $entry = Cache::get($this->codeKey($code));
        if (! is_array($entry)) {
            return false;
        }

        return Cache::add($this->sightingKey($code), $sighting, Carbon::createFromTimestamp((int) $entry['expires_at']));
    }

    /** @return array<string, mixed>|null */
    public function sighting(string $code): ?array
    {
        $sighting = $code === '' ? null : Cache::get($this->sightingKey($code));

        return is_array($sighting) ? $sighting : null;
    }

    /** Drop a sighting that can never pass, so a new post of the same code is picked up. */
    public function forgetSighting(string $code): void
    {
        Cache::forget($this->sightingKey($code));
    }

    /** Spend the code: it and its sighting are gone, and it can never connect again. */
    public function consume(string $code): void
    {
        $entry = Cache::get($this->codeKey($code));
        if (is_array($entry) && Cache::get($this->userKey((int) $entry['user_id'])) === $code) {
            Cache::forget($this->userKey((int) $entry['user_id']));
        }

        Cache::forget($this->codeKey($code));
        Cache::forget($this->sightingKey($code));
    }

    /** How a typed code is compared: upper case, no spaces. */
    public static function normalize(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code) ?? '');
    }

    /**
     * The code in a "/connect <code>" message, or null. "/connect@<bot> <code>"
     * counts only when <bot> is our bot: in a group with several bots it is
     * addressed to that bot.
     */
    public static function codeFromCommand(?string $text, string $botUsername): ?string
    {
        if (! is_string($text) || ! preg_match('~^/connect(?:@([A-Za-z0-9_]+))?\s+([A-Za-z0-9]{4,32})\s*$~', trim($text), $m)) {
            return null;
        }

        if ($m[1] !== '' && strcasecmp($m[1], $botUsername) !== 0) {
            return null;
        }

        return self::normalize($m[2]);
    }

    private function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    private function codeKey(string $code): string
    {
        return 'telegram:connect:code:'.$code;
    }

    private function sightingKey(string $code): string
    {
        return 'telegram:connect:seen:'.$code;
    }

    private function userKey(int $userId): string
    {
        return 'telegram:connect:user:'.$userId;
    }
}
