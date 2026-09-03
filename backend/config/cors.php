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

    /*
    | Browser origins allowed to call the API.
    |
    | Local dev origins are always allowed. FRONTEND_URL is added automatically,
    | and CORS_ALLOWED_ORIGINS takes a comma-separated list for anything else
    | (a staging host, a tunnel while testing OAuth, ...). Nothing deployment-
    | specific belongs in this file.
    |
    |   CORS_ALLOWED_ORIGINS=https://app.example.com,https://abc123.ngrok-free.app
    */
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        [
            'http://localhost:3000',
            'http://localhost:5173',
            'http://localhost:8000',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ],
        [rtrim((string) env('FRONTEND_URL', ''), '/')],
        array_map(
            fn (string $origin) => rtrim(trim($origin), '/'),
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
        ),
    )))),

    // The YouTube Studio integration calls the API from youtube.com pages.
    'allowed_origins_patterns' => [
        '/^https:\/\/([a-z0-9-]+\.)*youtube\.com$/i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
