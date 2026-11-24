<?php

namespace App\Services\Social\Telegram;

use App\Exceptions\TransientPublishException;

/**
 * Telegram's flood control answered 429 before anything was posted. Carries
 * parameters.retry_after so PublishToTelegramJob releases itself for exactly
 * that long instead of its fixed backoff.
 */
class TelegramRateLimited extends TransientPublishException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Telegram rate limit hit — retrying automatically in {$retryAfter} seconds.");
    }
}
