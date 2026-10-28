<?php

namespace App\Support\Quota;

use App\Mail\YouTubeQuotaAlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the admin when the day's YouTube API spend nears or hits Google's
 * allocation. Three events per resource, each at most once per Pacific day:
 *
 *   warning    the day's calls reached alert_at % of the daily limit
 *   exhausted  they reached 100 %
 *   refused    Google answered quotaExceeded — users are now being turned away
 *
 * Each is an error-level youtube_quota log line (Sentry) plus an email to
 * monitoring.youtube_quota.alert_email, sent synchronously the way the queue
 * heartbeat's is. Never throws: alerting must not break the call it observed.
 */
final class QuotaAlerts
{
    public const EVENT_WARNING = 'warning';

    public const EVENT_EXHAUSTED = 'exhausted';

    public const EVENT_REFUSED = 'refused';

    /**
     * @param int      $used           the day's calls after this one
     * @param bool     $quotaExceeded  Google refused this call for quota
     */
    public static function check(string $resource, int $used, bool $quotaExceeded = false): void
    {
        $cfg = config('monitoring.youtube_quota', []);
        if (! ($cfg['enabled'] ?? true)) {
            return;
        }

        $limit = self::dailyLimit($resource);
        if ($limit === null) {
            return;
        }

        $alertAt = $cfg['alert_at'] ?? null;
        $events = [];
        if ($quotaExceeded) {
            $events[] = self::EVENT_REFUSED;
        }
        if ($used >= $limit) {
            $events[] = self::EVENT_EXHAUSTED;
        } elseif ($alertAt !== null && $alertAt !== '' && (int) $alertAt > 0 && $used * 100 >= $limit * (int) $alertAt) {
            $events[] = self::EVENT_WARNING;
        }

        foreach ($events as $event) {
            self::fire($event, $resource, $used, $limit, (string) ($cfg['alert_email'] ?? ''));
        }
    }

    /** Google's daily allocation for a resource, or null when it isn't tracked against one. */
    public static function dailyLimit(string $resource): ?int
    {
        $value = config("services.youtube.quota.daily_limits.{$resource}");

        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function fire(string $event, string $resource, int $used, int $limit, string $email): void
    {
        try {
            // Cache::add is false when the flag already exists: once a day per event.
            if (! Cache::add('youtube_quota:alerted:'.$resource.':'.$event.':'.Keys::date(), 1, Keys::counterTtl())) {
                return;
            }

            $context = [
                'event' => $event,
                'resource' => $resource,
                'used' => $used,
                'limit' => $limit,
                'percent' => (int) floor($used * 100 / max(1, $limit)),
                'resets_at' => Keys::resetsAt()->format(DATE_ATOM),
            ];
            Log::channel(QuotaLog::CHANNEL)->error('youtube_quota.alert', $context);

            if ($email === '') {
                Log::channel(QuotaLog::CHANNEL)->warning("youtube quota {$event} for {$resource} but no alert email is configured (ADMIN_EMAIL / YOUTUBE_QUOTA_ALERT_EMAIL)");

                return;
            }

            Mail::to($email)->send(new YouTubeQuotaAlertMail($event, $context));
            Log::channel(QuotaLog::CHANNEL)->info("youtube quota {$event} email sent", ['to' => $email, 'resource' => $resource]);
        } catch (Throwable $e) {
            Log::channel(QuotaLog::CHANNEL)->error("youtube quota {$event} alert failed", ['resource' => $resource, 'error' => $e->getMessage()]);
            Log::error("YouTube quota: {$event} alert failed", ['resource' => $resource, 'error' => $e->getMessage()]);
        }
    }
}
