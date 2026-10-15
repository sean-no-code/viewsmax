<?php

namespace App\Services\Social\Data;

use App\Exceptions\TransientPublishException;
use InvalidArgumentException;

/**
 * What a Meta Graph error means for the caller (see MetaGraphErrors):
 *
 *  - fail        the post is rejected; show $message, do not retry
 *  - retry       transient; throwIfRetry() hands it to the queue
 *  - reauth      the token is dead; flag the account for reconnect
 *  - checkpoint  reauth, but the user must first log in at instagram.com /
 *                facebook.com and clear Meta's security check
 *
 * $message is safe to show the user; $raw is the compact reason for logs.
 */
final class MetaGraphOutcome
{
    public const FAIL = 'fail';

    public const RETRY = 'retry';

    public const REAUTH = 'reauth';

    public const CHECKPOINT = 'checkpoint';

    public const KINDS = [self::FAIL, self::RETRY, self::REAUTH, self::CHECKPOINT];

    public function __construct(
        public readonly string $kind,
        public readonly string $message,
        public readonly string $raw,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown Meta Graph outcome kind: {$kind}");
        }
    }

    public function isRetry(): bool
    {
        return $this->kind === self::RETRY;
    }

    /** True for both reauth and checkpoint: the account needs reconnecting either way. */
    public function needsReauth(): bool
    {
        return $this->kind === self::REAUTH || $this->kind === self::CHECKPOINT;
    }

    public function isCheckpoint(): bool
    {
        return $this->kind === self::CHECKPOINT;
    }

    /**
     * Hand a transient outcome to the queue. Publish jobs let
     * TransientPublishException propagate so $tries/$backoff retry the post.
     *
     * @throws TransientPublishException
     */
    public function throwIfRetry(): self
    {
        if ($this->isRetry()) {
            throw new TransientPublishException($this->message);
        }

        return $this;
    }
}
