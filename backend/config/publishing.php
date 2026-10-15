<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-platform publish throughput
    |--------------------------------------------------------------------------
    |
    | How many publish jobs may START per minute for each platform. A job over
    | the cap is released back to the queue until the window resets, so a spike
    | on one platform cannot starve the others. 0 removes the cap for that
    | platform.
    |
    | A released job still counts as an attempt against the job's $tries, so
    | these are a generous backstop, not a scheduler. YouTube is the tighter
    | one because every upload also spends Data API quota.
    |
    */

    'youtube_per_minute' => (int) env('PUBLISH_YOUTUBE_PER_MINUTE', 30),

    'tiktok_per_minute' => (int) env('PUBLISH_TIKTOK_PER_MINUTE', 100),

];
