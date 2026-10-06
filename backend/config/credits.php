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
    | Monthly credit allowance per plan tier, keyed by plans.name. A tier that
    | is missing from `plans` falls back to `default`; the trial always gets
    | its own flat amount regardless of tier. Tune a tier by setting its env
    | var — nothing lives on the plan row or in the seeder.
    |
    */
    'subscription_credits' => [
        // Fallback for a paid plan whose name has no entry below.
        'default' => env('CREDITS_SUBSCRIPTION', 1000),
        // Flat credits during the free trial.
        'trial' => env('CREDITS_TRIAL', 250),
        'plans' => [
            'free' => env('CREDITS_SUBSCRIPTION_FREE', 0),
            'starter' => env('CREDITS_SUBSCRIPTION_STARTER', 1000),
            'creator' => env('CREDITS_SUBSCRIPTION_CREATOR', 2500),
            'pro' => env('CREDITS_SUBSCRIPTION_PRO', 5000),
            'agency' => env('CREDITS_SUBSCRIPTION_AGENCY', 10000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Tool Costs
    |--------------------------------------------------------------------------
    |
    | Every successful MCP tool call deducts credits (App\Mcp\Methods\SafeCallTool).
    | A tool named in `tools` costs that amount; any other tool costs the
    | read or write default depending on whether it mutates account state
    | (ViewsMaxTool::isWrite). Heavy tools (provider scrapes, LLM calls) are
    | listed explicitly. A call is refused before running when the balance is
    | below the cost; the balance never goes negative from MCP charges.
    |
    */
    'mcp' => [
        'read_default' => env('CREDITS_MCP_READ_DEFAULT', 1),
        'write_default' => env('CREDITS_MCP_WRITE_DEFAULT', 5),
        'tools' => [
            'create_post' => env('CREDITS_MCP_CREATE_POST', 10),
            'upload_media' => env('CREDITS_MCP_UPLOAD_MEDIA', 5),
            'search_outliers' => env('CREDITS_MCP_SEARCH_OUTLIERS', 10),
            'fetch_outlier' => env('CREDITS_MCP_FETCH_OUTLIER', 10),
            'generate_outlier_breakdown' => env('CREDITS_MCP_GENERATE_OUTLIER_BREAKDOWN', 25),
            'add_outlier_channel' => env('CREDITS_MCP_ADD_OUTLIER_CHANNEL', 25),
        ],
    ],
];
