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
        'read_default' => env('CREDITS_MCP_READ_DEFAULT', 0),           // viewing is free; outlier searches are charged below
        'write_default' => env('CREDITS_MCP_WRITE_DEFAULT', 5),
        'tools' => [
            'create_post' => env('CREDITS_MCP_CREATE_POST', 10),
            'upload_media' => env('CREDITS_MCP_UPLOAD_MEDIA', 5),
            'search_outliers' => env('CREDITS_MCP_SEARCH_OUTLIERS', 10),
            'fetch_outlier' => env('CREDITS_MCP_FETCH_OUTLIER', 10),
            'generate_outlier_breakdown' => env('CREDITS_MCP_GENERATE_OUTLIER_BREAKDOWN', 25),
            'add_outlier_channel' => env('CREDITS_MCP_ADD_OUTLIER_CHANNEL', 25),
            // Extra per X post with a link, per X account (X charges $0.20 vs $0.015;
            // App\Support\XLinkCharge): 10 + 25 = 35 for one X account.
            'x_link' => env('CREDITS_MCP_X_LINK', 25),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Website Action Costs
    |--------------------------------------------------------------------------
    |
    | Credits a website action costs (App\Http\Middleware\ChargeWebAction),
    | laid out like `mcp` above. Same rules as the AI: refused before running
    | when the balance is too low, charged only on success, never negative.
    | 0 = free. An action not listed in `tools` costs the write default, and
    | every GET costs the read default.
    |
    | Actions named after an AI tool are set to what the AI charges today.
    | read_default is per API request, not per page: one page load makes
    | several (often 5+), so 1 here can cost 5+ credits a page.
    |
    */
    'web' => [
        'read_default' => env('CREDITS_WEB_READ_DEFAULT', 0),
        'write_default' => env('CREDITS_WEB_WRITE_DEFAULT', 0),
        'tools' => [
            // Posts
            'create_post' => env('CREDITS_WEB_CREATE_POST', 10),           // drafts included
            'update_post' => env('CREDITS_WEB_UPDATE_POST', 5),            // every edit, incl. publishing a draft
            'delete_post' => env('CREDITS_WEB_DELETE_POST', 5),
            'upload_media' => env('CREDITS_WEB_UPLOAD_MEDIA', 5),          // per image/video, when the post is published or scheduled
            'x_link' => env('CREDITS_WEB_X_LINK', 25),                     // extra per X post with a link, per X account (as mcp)
            // Outliers
            'search_outliers' => env('CREDITS_WEB_SEARCH_OUTLIERS', 10),
            'fetch_outlier' => env('CREDITS_WEB_FETCH_OUTLIER', 10),
            'generate_outlier_breakdown' => env('CREDITS_WEB_GENERATE_OUTLIER_BREAKDOWN', 25),
            'add_outlier_channel' => env('CREDITS_WEB_ADD_OUTLIER_CHANNEL', 25),
            'save_outlier' => env('CREDITS_WEB_SAVE_OUTLIER', 5),
            'remove_saved_outlier' => env('CREDITS_WEB_REMOVE_SAVED_OUTLIER', 5),
            // Offers and tracking links
            'create_offer' => env('CREDITS_WEB_CREATE_OFFER', 5),
            'update_offer' => env('CREDITS_WEB_UPDATE_OFFER', 5),
            'delete_offer' => env('CREDITS_WEB_DELETE_OFFER', 5),
            'create_tracking_link' => env('CREDITS_WEB_CREATE_TRACKING_LINK', 5),
            // Accounts
            'get_connect_url' => env('CREDITS_WEB_GET_CONNECT_URL', 5),    // connecting an account
            'disconnect_account' => env('CREDITS_WEB_DISCONNECT_ACCOUNT', 5),
            // Feature requests
            'create_feature_request' => env('CREDITS_WEB_CREATE_FEATURE_REQUEST', 5),
            // Website-only actions (no AI tool) use write_default unless set here:
            // retry_post, update_saved_outlier, saved_filter, competitor,
            // refresh_outlier_media, update_tracking_link, delete_tracking_link,
            // upvote_feature_request.
        ],
    ],
];
