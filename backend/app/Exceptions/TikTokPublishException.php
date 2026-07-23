<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a TikTok publish step fails. Carries a structured $context (the
 * audit log accumulated so far) so the job can persist the raw TikTok exchange
 * to the post target's meta even though the failure unwinds via an exception.
 */
class TikTokPublishException extends Exception
{
    /**
     * @param  array  $context  e.g. ['log' => [...events...], 'mode' => 'direct']
     */
    public function __construct(string $message, public array $context = [])
    {
        parent::__construct($message);
    }
}
