<?php

namespace App\Exceptions;

use Exception;

/**
 * The creator cannot post to TikTok right now — their daily cap is reached, the
 * account is restricted, or the client's active-user quota is used up.
 *
 * Required UX 1(b) of the Content Sharing Guidelines says the publishing attempt
 * must stop and the user must be prompted to try again later, so this is kept
 * distinct from a generic creator_info failure: the composer shows the retry
 * prompt for this, and a plain error for anything else.
 */
class TikTokCreatorUnavailableException extends Exception
{
    /** Codes TikTok returns with HTTP 200 to mean "not right now". */
    public const CODES = [
        'spam_risk_too_many_posts',
        'spam_risk_user_banned_from_posting',
        'reached_active_user_cap',
    ];

    /** @param string $errorCode the TikTok error.code that triggered this */
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
