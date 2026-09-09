<?php

namespace App\Services\Social\Data;

/**
 * Outcome of a DM send (Instagram Messaging API). Carries the Graph error
 * code/subcode so the executor can tell "reconnect" from "outside the
 * messaging window" from "slow down" without re-parsing bodies.
 */
final class MessageResult
{
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
        public ?int $errorCode = null,
        public ?int $errorSubcode = null,
        public ?int $httpStatus = null,
        public array $raw = [],
    ) {}

    public static function success(?string $messageId, array $raw = []): self
    {
        return new self(true, $messageId, null, null, null, 200, $raw);
    }

    public static function failure(string $error, array $raw = [], ?int $httpStatus = null): self
    {
        $graph = is_array($raw['error'] ?? null) ? $raw['error'] : [];

        return new self(
            false,
            null,
            $error,
            isset($graph['code']) ? (int) $graph['code'] : null,
            isset($graph['error_subcode']) ? (int) $graph['error_subcode'] : null,
            $httpStatus,
            $raw,
        );
    }

    /** Expired / revoked token (OAuthException, code 190). */
    public function isAuthError(): bool
    {
        return $this->errorCode === 190
            || (($this->raw['error']['type'] ?? null) === 'OAuthException');
    }

    /** Sent outside the 24h messaging window (code 10 / subcode 2534022). */
    public function isOutsideWindow(): bool
    {
        if ($this->errorCode === 10 && $this->errorSubcode === 2534022) {
            return true;
        }

        $message = strtolower((string) ($this->raw['error']['message'] ?? $this->error ?? ''));

        return str_contains($message, 'outside of allowed window')
            || str_contains($message, 'messaging window');
    }

    /** Graph rate limiting: codes 4 / 17 / 32 / 613 or HTTP 429. */
    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429
            || in_array($this->errorCode, [4, 17, 32, 613], true);
    }
}
