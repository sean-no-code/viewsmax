<?php

namespace App\Support\Quota;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Cache keys for the day's YouTube API call counters, and the Pacific-time
 * day they roll over on: Google resets the Data API quota at midnight in
 * Los Angeles, so "today" here is today there whatever the app's timezone.
 */
final class Keys
{
    public const TIMEZONE = 'America/Los_Angeles';

    /** The Pacific-time date (Y-m-d) an instant falls on; now by default. */
    public static function date(?DateTimeInterface $at = null): string
    {
        return Carbon::instance($at ?? now())->setTimezone(self::TIMEZONE)->toDateString();
    }

    /** The next midnight Pacific, in UTC. */
    public static function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->addDay()->startOfDay()->setTimezone('UTC');
    }

    /** The day's call counter for a resource. */
    public static function counter(string $resource, ?string $date = null): string
    {
        return 'youtube_quota:counter:'.$resource.':'.($date ?? self::date());
    }

    /** How many fresh fetches one user has triggered today. */
    public static function userCounter(int $userId, string $resource, ?string $date = null): string
    {
        return 'youtube_quota:user:'.$userId.':'.$resource.':'.($date ?? self::date());
    }

    /** Counters outlive their day by one so a late read still sees them. */
    public static function counterTtl(): CarbonImmutable
    {
        return self::resetsAt()->addDay();
    }
}
