<?php

namespace App\Services\Automations\Data;

use App\Models\Automation;

/** Outcome of matching one inbound event against live automations. */
final class MatchResult
{
    public const NO_ACCOUNT = 'no_account';
    public const NO_LIVE_AUTOMATIONS = 'no_live_automations';
    public const THREAD_REPLY = 'thread_reply';
    public const MEDIA_MISMATCH = 'media_mismatch';
    public const KEYWORD_MISMATCH = 'keyword_mismatch';
    public const COOLDOWN = 'cooldown';

    public function __construct(
        public readonly ?Automation $automation,
        public readonly ?string $keyword,
        public readonly ?string $ignoreReason,
    ) {}

    public static function matched(Automation $automation, string $keyword): self
    {
        return new self($automation, $keyword, null);
    }

    public static function ignored(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function isMatch(): bool
    {
        return $this->automation !== null;
    }
}
