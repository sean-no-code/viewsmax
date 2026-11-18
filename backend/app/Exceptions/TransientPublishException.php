<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A publish attempt failed for a transient reason (rate limit, 5xx). Publish
 * jobs rethrow this so the queue retries; progress persisted before the throw
 * (e.g. X thread tweet ids) lets the retry resume where it left off.
 */
class TransientPublishException extends RuntimeException
{
    /**
     * Seconds the platform asked us to wait before retrying (its Retry-After
     * header), when it said. A job may release itself for that long instead
     * of using its fixed backoff.
     */
    public ?int $retryAfter = null;
}
