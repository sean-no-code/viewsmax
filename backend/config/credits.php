<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registration Bonus
    |--------------------------------------------------------------------------
    |
    | The amount of credits given to a user when they register a free account.
    |
    */
    'registration_bonus' => env('CREDITS_REGISTRATION_BONUS', 100),

    /*
    |--------------------------------------------------------------------------
    | Operation Costs
    |--------------------------------------------------------------------------
    |
    | The cost in credits for various operations.
    | Cost dollar wild guess
    0.11 per thumbnail
    0.35 per script
    0.2 per title
    0.2 per review
    margin 80%
    */
    'costs' => [
        'create_thumbnail' => env('CREDITS_COST_CREATE_THUMBNAIL', 25),
        'title' => env('CREDITS_COST_TITLE', 10),
        'script_create' => env('CREDITS_COST_SCRIPT_CREATE', 50),
        'script_update' => env('CREDITS_COST_SCRIPT_UPDATE', 10),
        'review' => env('CREDITS_COST_REVIEW', 10),
        'image_generation' => env('CREDITS_COST_IMAGE_GENERATION', 50),
        'face_swap_copy_thumbnail' => env('CREDITS_COST_FACE_SWAP_COPY_THUMBNAIL', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Credits
    |--------------------------------------------------------------------------
    |
    | Credits aren't differentiated per tier yet (per client). Every paid plan
    | gets the same `default` amount; the trial gets its own flat amount. When
    | per-tier credits are needed, expand this map (or move onto the plan row).
    |
    */
    'subscription_credits' => [
        // Flat monthly credits for any paid plan (uniform across tiers).
        'default' => env('CREDITS_SUBSCRIPTION', 1000),
        // Flat credits during the free trial.
        'trial' => env('CREDITS_TRIAL', 250),
    ],
];
