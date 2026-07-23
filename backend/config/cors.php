<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    // Add your own deployed frontend via CORS_ALLOWED_ORIGINS (comma-separated).
    'allowed_origins' => array_merge(
        [
            'http://localhost:8080',
            'http://127.0.0.1:8080',
            'http://localhost:8000',
            'http://localhost:3000',
            'https://viewsmax.com',
            // YouTube origins are required by the browser extension / embeds.
            'https://studio.youtube.com',
            'https://www.youtube.com',
            'https://youtube.com',
        ],
        array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    ),

    // Allow any youtube.com subdomain (e.g., studio.youtube.com)
    'allowed_origins_patterns' => [
        '/^https:\/\/([a-z0-9-]+\.)*youtube\.com$/i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
