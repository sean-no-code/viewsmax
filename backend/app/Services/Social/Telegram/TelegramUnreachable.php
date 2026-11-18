<?php

namespace App\Services\Social\Telegram;

use RuntimeException;

/**
 * No answer from the Bot API (network, timeout, TLS). The message is fixed
 * on purpose: the client's own message carries the request URL, which holds
 * the bot token.
 */
class TelegramUnreachable extends RuntimeException
{
    public function __construct(public readonly string $method)
    {
        parent::__construct("Telegram could not be reached ({$method}).");
    }
}
