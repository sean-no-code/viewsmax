<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\SocialAccount;
use App\Services\Automations\Data\InboundEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every automations log line goes through here so it lands on the dedicated
 * `automations` channel (storage/logs/automations-*.log, see
 * config/logging.php) with the same context keys, and never leaks a token.
 * AUTOMATIONS_LOG_ENABLED=false silences the channel entirely.
 */
class AutomationLog
{
    public const CHANNEL = 'automations';

    /** Context keys whose values must never reach the log. */
    private const REDACT = ['access_token', 'client_secret', 'refresh_token', 'x-hub-signature-256', 'signature', 'token'];

    /** Long string fields are truncated to keep lines readable. */
    private const TRUNCATE = ['body', 'payload', 'raw', 'response'];

    private const MAX_STRING = 2000;

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * Standard context for a log line: whichever of automation / run / event /
     * account is known. Nulls are dropped so lines stay compact.
     *
     * @return array<string, mixed>
     */
    public static function context(
        ?Automation $automation = null,
        ?AutomationRun $run = null,
        ?InboundEvent $event = null,
        ?SocialAccount $account = null,
    ): array {
        $account ??= $automation?->socialAccount;

        return array_filter([
            'automation_id' => $automation?->id ?? $run?->automation_id,
            'run_id' => $run?->id,
            'event_id' => $event?->eventId ?? $run?->event_id,
            'event_type' => $event?->type ?? $run?->trigger_type ?? $automation?->trigger_type,
            'account_id' => $account?->id ?? $automation?->social_account_id,
            'ig_user_id' => $event?->accountPlatformId ?? $account?->platform_account_id,
            'user_id' => $automation?->user_id ?? $run?->user_id ?? $account?->user_id,
        ], fn ($v) => $v !== null);
    }

    /**
     * Strip secrets and truncate oversized strings (recursively).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);

            if (in_array($lower, self::REDACT, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_string($value) && in_array($lower, self::TRUNCATE, true) && mb_strlen($value) > self::MAX_STRING) {
                $context[$key] = Str::limit($value, self::MAX_STRING, '…[truncated]');
            }
        }

        return $context;
    }

    private static function write(string $level, string $message, array $context): void
    {
        Log::channel(self::CHANNEL)->{$level}('[Automations] '.$message, self::redact($context));
    }
}
