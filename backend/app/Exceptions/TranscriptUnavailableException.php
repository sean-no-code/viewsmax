<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The transcript provider (CaptAPI) answered 404: the video exists but has no
 * captions / audio it can transcribe. That is the provider's answer, not our
 * bug, so callers show the message to the user and do NOT report() it.
 */
class TranscriptUnavailableException extends RuntimeException
{
    public const DEFAULT_MESSAGE = 'No transcript is available for this video.';

    public function __construct(?string $message = null)
    {
        parent::__construct($message !== null && $message !== '' ? $message : self::DEFAULT_MESSAGE);
    }
}
