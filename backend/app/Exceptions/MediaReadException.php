<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Reading a post's media file from storage stalled or ended early while it was
 * being streamed to a platform. The message is the user-facing one the publish
 * jobs store on the target; the technical detail goes to the log only.
 */
class MediaReadException extends RuntimeException
{
    public const MESSAGE = 'We could not read the media file for this post; re-upload it and try again.';

    public function __construct(public readonly string $detail)
    {
        parent::__construct(self::MESSAGE);
    }
}
