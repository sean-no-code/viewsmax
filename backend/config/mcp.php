<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP rate limits
    |--------------------------------------------------------------------------
    |
    | Per-token / per-IP throttles for the MCP server and API key endpoints.
    | All values are overridable via .env so limits can be tuned without a
    | deploy. See AppServiceProvider for the RateLimiter definitions.
    |
    */

    // Max size (MB) of a file the upload_media tool will download and host.
    'upload_max_mb' => env('MCP_UPLOAD_MAX_MB', 100),

    // SPA base URL, used by tools that hand the user a page to open
    // (e.g. get_connect_url -> the Connections page).
    'frontend_url' => env('FRONTEND_URL', env('APP_URL')),

    'rate_limits' => [
        // All MCP calls, per token.
        'per_minute' => env('MCP_RATE_PER_MINUTE', 120),

        // create_post tool, per token.
        'create_post_per_hour' => env('MCP_CREATE_POST_PER_HOUR', 180),

        // upload_media tool, per token (server downloads up to 512MB per call).
        'upload_media_per_hour' => env('MCP_UPLOAD_MEDIA_PER_HOUR', 40),

        // search_outliers tool, per token (each call queues a provider scrape).
        'search_outliers_per_hour' => env('MCP_SEARCH_OUTLIERS_PER_HOUR', 30),

        // fetch_outlier tool, per token (provider fetch / ingest job per call).
        'fetch_outlier_per_hour' => env('MCP_FETCH_OUTLIER_PER_HOUR', 60),

        // generate_outlier_breakdown tool, per token (transcript + LLM call).
        'generate_breakdown_per_hour' => env('MCP_GENERATE_BREAKDOWN_PER_HOUR', 30),

        // add_outlier_channel tool AND the matching REST endpoint, per user
        // (each add pulls ~30 videos from YouTube or CaptAPI).
        'add_outlier_channel_per_hour' => env('MCP_ADD_OUTLIER_CHANNEL_PER_HOUR', 10),

        // Failed auth attempts on /mcp, per IP (key brute-force protection).
        'failed_auth_per_minute' => env('MCP_FAILED_AUTH_PER_MINUTE', 20),

        // API key rotation, per user.
        'key_rotate_per_hour' => env('MCP_KEY_ROTATE_PER_HOUR', 12),
    ],

];
