<?php

namespace App\Services\Social\Discord;

use App\Exceptions\TransientPublishException;

/**
 * Discord answered 429. Carries Discord's own retry_after (seconds) so the
 * publish job can come back exactly when Discord says, instead of after its
 * fixed backoff.
 */
class RateLimitedException extends TransientPublishException
{
    public function __construct(string $message, public readonly float $retryAfter)
    {
        parent::__construct($message);
    }
}
