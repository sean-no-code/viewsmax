<?php

namespace App\Services;

class YouTubeCreditTracker
{
    private static int $totalCredits = 0;

    public static function add(int $credits): void
    {
        self::$totalCredits += $credits;
    }

    public static function get(): int
    {
        return self::$totalCredits;
    }

    public static function reset(): void
    {
        self::$totalCredits = 0;
    }
}
