<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The video exists but has no spoken audio to transcribe (music / sound
 * effects only). That is the provider's answer, not our bug, so callers show
 * the message to the user and do NOT report() it.
 */
class TranscriptUnavailableException extends RuntimeException
{
    public const DEFAULT_MESSAGE = 'No transcript is available for this video.';

    public function __construct(?string $message = null)
    {
        parent::__construct($message !== null && $message !== '' ? $message : self::DEFAULT_MESSAGE);
    }
}
