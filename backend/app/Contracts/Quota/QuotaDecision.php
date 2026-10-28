<?php

namespace App\Contracts\Quota;

use DateTimeImmutable;

final class QuotaDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $resource,
        public readonly string $tier,
        /** Calls already made today, project-wide. */
        public readonly int $used,
        /** Calls this tier may reach today; null = unlimited. */
        public readonly ?int $cap,
        /** When the counter resets: midnight Pacific, like Google's quota. */
        public readonly DateTimeImmutable $resetsAt,
        public readonly string $reason = '',
        /** Which limit this is about: the shared day counter ('project') or the user's own allowance ('user'). */
        public readonly string $scope = self::SCOPE_PROJECT,
    ) {}

    public const SCOPE_PROJECT = 'project';

    public const SCOPE_USER = 'user';

    public const REASON_CAP = 'cap_reached';

    public const REASON_USER_LIMIT = 'user_limit';

    public static function allow(string $resource, string $tier, int $used, ?int $cap, DateTimeImmutable $resetsAt, string $reason = ''): self
    {
        return new self(true, $resource, $tier, $used, $cap, $resetsAt, $reason);
    }

    public static function deny(string $resource, string $tier, int $used, ?int $cap, DateTimeImmutable $resetsAt, string $reason = self::REASON_CAP): self
    {
        return new self(false, $resource, $tier, $used, $cap, $resetsAt, $reason);
    }

    /** Refused by the user's own daily allowance; $used / $cap are theirs. */
    public static function denyUser(string $resource, string $tier, int $used, int $cap, DateTimeImmutable $resetsAt): self
    {
        return new self(false, $resource, $tier, $used, $cap, $resetsAt, self::REASON_USER_LIMIT, self::SCOPE_USER);
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'resource' => $this->resource,
            'tier' => $this->tier,
            'used' => $this->used,
            'cap' => $this->cap,
            'resets_at' => $this->resetsAt->format(DATE_ATOM),
            'reason' => $this->reason,
            'scope' => $this->scope,
        ];
    }
}
