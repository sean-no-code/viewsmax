<?php

namespace App\Services\Social;

use App\Services\Social\Data\MetaGraphOutcome;
use Illuminate\Http\Client\Response;

/**
 * Classifies a Meta Graph API error (Facebook, Instagram, Threads) into what
 * the caller should do. Providers
 * used to hand the raw JSON body to the user and return a failure on the
 * first attempt, so the queue never retried a 5xx and an expired token was
 * reported as a generic failure.
 *
 * Usage:
 *
 *     $outcome = MetaGraphErrors::fromResponse($response, self::ERRORS)->throwIfRetry();
 *     if ($outcome->needsReauth()) { $account->markNeedsReauth($outcome->message); }
 *     return PublishResult::failure($outcome->message, $response->json() ?? []);
 *
 * EXTENSION POINT — per-platform tables. Each provider owns a table of the
 * errors specific to it (Instagram container subcodes 2207xxx, Facebook
 * 1366046 "photo too large", …) and passes it as $table:
 *
 *     array<string|int, array{0: string, 1: string}>   key => [kind, message]
 *
 * The key is matched against the response in this order, first hit wins,
 * and a table hit always beats the generic rules below:
 *   1. error.error_subcode  == key (numeric keys)
 *   2. error.code           == key (numeric keys)
 *   3. key found in the raw body text; numeric keys only match a whole
 *      number (33 never matches 330 or 2207033), text keys match
 *      case-insensitively.
 * Kind is one of MetaGraphOutcome::FAIL / RETRY / REAUTH / CHECKPOINT.
 *
 * Generic rules (after the table), in order:
 *   - HTTP 429 / 5xx                                             → retry
 *   - "You cannot access the app till you log in to", "Session
 *     key is malformed"                                           → checkpoint
 *   - code 1 / 2, "An unknown error occurred"                     → retry
 *   - code 4 / 17 / 32 / 613, "Page request limit reached"        → fail (rate-limited today)
 *   - code 10, "(#200)", "Not enough permissions", a 190 whose
 *     message talks about missing permissions                    → fail (permissions)
 *   - code 190, subcode 460 / 463 / 467, "Error validating
 *     access token", "REVOKED_ACCESS_TOKEN", "session has been
 *     invalidated"                                               → reauth
 *   - anything else                                               → fail ("Meta rejected the post: <message>")
 */
final class MetaGraphErrors
{
    private const RETRY_CODES = [1, 2];

    private const RATE_LIMIT_CODES = [4, 17, 32, 613];

    private const PERMISSION_CODES = [10];

    private const REAUTH_SUBCODES = [460, 463, 467];

    private const CHECKPOINT_TEXTS = ['You cannot access the app till you log in to', 'Session key is malformed'];

    private const REAUTH_TEXTS = ['Error validating access token', 'REVOKED_ACCESS_TOKEN', 'session has been invalidated'];

    private const PERMISSION_TEXTS = ['(#200)', 'Not enough permissions', 'missing permission'];

    public const MESSAGE_REAUTH = 'Meta no longer accepts this account\'s access token. Reconnect the account in Settings, then retry the post.';

    public const MESSAGE_CHECKPOINT = 'Meta has put this account behind a security check. Log in at instagram.com or facebook.com, follow Meta\'s instructions, then reconnect the account in Settings and retry the post.';

    public const MESSAGE_RATE_LIMITED = 'Meta says posting is rate-limited for today for this account. Try again later.';

    public const MESSAGE_PERMISSIONS = 'Meta rejected the post because the account is missing permissions. Reconnect the account and tick every box on the permission screen.';

    /**
     * @param  array<string|int, array{0: string, 1: string}>  $table  per-platform overrides (see class docblock)
     */
    public static function fromResponse(Response $response, array $table = []): MetaGraphOutcome
    {
        return self::classify($response->status(), $response->json(), $table, $response->body());
    }

    /**
     * @param  array<string, mixed>|null  $body  decoded JSON body (null when Meta answered with HTML / nothing)
     * @param  array<string|int, array{0: string, 1: string}>  $table  per-platform overrides (see class docblock)
     * @param  string|null  $rawBody  the body text to substring-match; defaults to json_encode($body)
     */
    public static function classify(int $status, ?array $body, array $table = [], ?string $rawBody = null): MetaGraphOutcome
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $message = trim((string) ($error['message'] ?? ''));
        $haystack = $rawBody ?? ($body === null ? '' : (string) json_encode($body));
        $raw = self::raw($status, $code, $subcode, $error['type'] ?? null, $message, $haystack);

        if ($hit = self::matchTable($table, $code, $subcode, $haystack)) {
            return new MetaGraphOutcome($hit[0], $hit[1], $raw);
        }

        if ($status === 429 || $status >= 500) {
            return new MetaGraphOutcome(MetaGraphOutcome::RETRY, "Meta is temporarily unavailable (HTTP {$status}); the post will be retried.", $raw);
        }

        if (self::containsAny($haystack, self::CHECKPOINT_TEXTS)) {
            return new MetaGraphOutcome(MetaGraphOutcome::CHECKPOINT, self::MESSAGE_CHECKPOINT, $raw);
        }

        if (in_array($code, self::RETRY_CODES, true) || self::containsAny($haystack, ['An unknown error occurred'])) {
            return new MetaGraphOutcome(MetaGraphOutcome::RETRY, 'Meta reported a temporary error; the post will be retried.', $raw);
        }

        if (in_array($code, self::RATE_LIMIT_CODES, true) || self::containsAny($haystack, ['Page request limit reached'])) {
            return new MetaGraphOutcome(MetaGraphOutcome::FAIL, self::MESSAGE_RATE_LIMITED, $raw);
        }

        // Only a 190 whose message says so is a permissions problem — a plain
        // 190 is a dead token and needs a reconnect, not a permission re-tick.
        if (in_array($code, self::PERMISSION_CODES, true)
            || self::containsAny($haystack, self::PERMISSION_TEXTS)
            || ($code === 190 && stripos($message, 'permission') !== false)) {
            return new MetaGraphOutcome(MetaGraphOutcome::FAIL, self::MESSAGE_PERMISSIONS, $raw);
        }

        if ($code === 190 || in_array($subcode, self::REAUTH_SUBCODES, true) || self::containsAny($haystack, self::REAUTH_TEXTS)) {
            return new MetaGraphOutcome(MetaGraphOutcome::REAUTH, self::MESSAGE_REAUTH, $raw);
        }

        $reason = $message !== '' ? $message : "HTTP {$status}";

        return new MetaGraphOutcome(MetaGraphOutcome::FAIL, 'Meta rejected the post: '.$reason, $raw);
    }

    /**
     * @param  array<string|int, array{0: string, 1: string}>  $table
     * @return array{0: string, 1: string}|null
     */
    private static function matchTable(array $table, ?int $code, ?int $subcode, string $haystack): ?array
    {
        if ($table === []) {
            return null;
        }

        if ($subcode !== null && isset($table[$subcode])) {
            return $table[$subcode];
        }

        if ($code !== null && isset($table[$code])) {
            return $table[$code];
        }

        foreach ($table as $needle => $outcome) {
            if (self::contains($haystack, (string) $needle)) {
                return $outcome;
            }
        }

        return null;
    }

    /** Substring match; a purely numeric needle must stand alone (33 never matches 330). */
    private static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        if (ctype_digit($needle)) {
            return (bool) preg_match('/(?<!\d)'.$needle.'(?!\d)/', $haystack);
        }

        return stripos($haystack, $needle) !== false;
    }

    /** @param  array<int, string>  $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (self::contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Compact one-line reason for logs — never the full JSON. */
    private static function raw(int $status, ?int $code, ?int $subcode, ?string $type, string $message, string $haystack): string
    {
        $parts = ["HTTP {$status}"];
        if ($type !== null) {
            $parts[] = "type={$type}";
        }
        if ($code !== null) {
            $parts[] = "code={$code}";
        }
        if ($subcode !== null) {
            $parts[] = "subcode={$subcode}";
        }

        $text = $message !== '' ? $message : mb_substr(trim($haystack), 0, 200);

        return implode(' ', $parts).($text !== '' ? ': '.$text : '');
    }
}
