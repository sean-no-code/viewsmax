<?php

/*
|--------------------------------------------------------------------------
| Social Media Publishing
|--------------------------------------------------------------------------
|
| Credentials and OAuth settings for every platform the app can connect to
| and publish on. Each platform maps to a provider class under
| App\Services\Social\Providers. Fill the *_CLIENT_ID / *_CLIENT_SECRET env
| vars (see tasks/todo.md) before the connect flow will work.
|
| `redirect_uri` is the FRONTEND callback URL the OAuth provider redirects
| back to. The frontend forwards the returned `code` to the backend
| `POST /api/social/{platform}/exchange` endpoint. Keep these in sync with
| the redirect URIs registered in each developer console.
|
*/

$frontend = env('FRONTEND_URL', env('APP_URL'));

return [

    // Default callback path on the frontend. The frontend may override per-call.
    'default_redirect_uri' => $frontend ? rtrim($frontend, '/').'/social/callback' : null,

    'platforms' => [

        'facebook' => [
            'label' => 'Facebook',
            'enabled' => env('FACEBOOK_ENABLED', true),
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v21.0'),
            // Publishing to a Page requires these scopes + a Page selection.
            'scopes' => [
                'public_profile',
                'pages_show_list',
                'pages_manage_posts',
                'pages_read_engagement',
                'business_management',
            ],
        ],

        'instagram' => [
            'label' => 'Instagram',
            'enabled' => env('INSTAGRAM_ENABLED', true),
            // "Instagram API with Instagram Login" — connects directly to an
            // Instagram professional account (no Facebook Page). Uses the
            // Instagram app id/secret from the Instagram product settings,
            // NOT the Facebook app credentials.
            'client_id' => env('INSTAGRAM_CLIENT_ID'),
            'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
            'graph_version' => env('INSTAGRAM_GRAPH_VERSION', 'v21.0'),
            // Content-views/reach tracking for IG links. Off until Meta App Review
            // grants the insights scope; flipping it on adds the scope to the
            // connect flow (existing accounts must reconnect) and turns on the
            // baseline capture + daily refresh for instagram_media_id links.
            'reach_enabled' => env('INSTAGRAM_REACH_ENABLED', false),
            'scopes' => array_values(array_filter([
                'instagram_business_basic',
                'instagram_business_content_publish',
                env('INSTAGRAM_REACH_ENABLED', false) ? 'instagram_business_manage_insights' : null,
            ])),
        ],

        'threads' => [
            'label' => 'Threads',
            'enabled' => env('THREADS_ENABLED', true),
            'client_id' => env('THREADS_CLIENT_ID'),
            'client_secret' => env('THREADS_CLIENT_SECRET'),
            'api_version' => env('THREADS_API_VERSION', 'v1.0'),
            'scopes' => [
                'threads_basic',
                'threads_content_publish',
            ],
        ],

        'linkedin' => [
            'label' => 'LinkedIn',
            'enabled' => env('LINKEDIN_ENABLED', true),
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            // LinkedIn-Version header (YYYYMM). Versioned APIs stay active ~12
            // months, so bump this via env when LinkedIn retires the old one.
            'api_version' => env('LINKEDIN_API_VERSION', '202601'),
            'scopes' => [
                'openid',
                'profile',
                'email',
                'w_member_social',
            ],
        ],

        'bluesky' => [
            'label' => 'Bluesky',
            'enabled' => env('BLUESKY_ENABLED', true),
            // Bluesky (AT Protocol) authenticates with handle + app password,
            // not OAuth. The user supplies these at connect time.
            'service_url' => env('BLUESKY_SERVICE_URL', 'https://bsky.social'),
        ],

        'x' => [
            'label' => 'X',
            'enabled' => env('X_ENABLED', true),
            'client_id' => env('X_CLIENT_ID'),
            'client_secret' => env('X_CLIENT_SECRET'),
            // X uses OAuth 2.0 with PKCE.
            // NOTE: `media.write` is only valid for apps enrolled in v2 media
            // upload — including it otherwise makes X render a BLANK consent
            // screen after login. Re-add it once the app has media access
            // (image upload to X needs it; text tweets do not).
            'scopes' => [
                'tweet.read',
                'tweet.write',
                'users.read',
                'offline.access',
            ],
        ],

        'tiktok' => [
            'label' => 'TikTok',
            'enabled' => env('TIKTOK_ENABLED', true),
            'client_id' => env('TIKTOK_CLIENT_KEY'),
            'client_secret' => env('TIKTOK_CLIENT_SECRET'),
            // Audience-growth stats (follower count + per-video engagement). Off
            // until the TikTok app is approved for the user.info.stats + video.list
            // scopes; flipping it on adds those scopes to the connect flow
            // (existing accounts must reconnect) and enables the follower + post
            // metric fetch. Mirrors the Instagram reach flag.
            'stats_enabled' => env('TIKTOK_STATS_ENABLED', false),
            'scopes' => array_values(array_filter([
                'user.info.basic',
                'video.publish',
                'video.upload',
                env('TIKTOK_STATS_ENABLED', false) ? 'user.info.stats' : null,
                env('TIKTOK_STATS_ENABLED', false) ? 'video.list' : null,
            ])),
        ],

        'youtube' => [
            'label' => 'YouTube',
            'enabled' => env('YOUTUBE_PUBLISH_ENABLED', true),
            // Reuses the existing Google OAuth app (config/services.php → google).
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'scopes' => [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
            ],
        ],

        'google_business' => [
            'label' => 'Google My Business',
            'enabled' => env('GOOGLE_BUSINESS_ENABLED', true),
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'scopes' => [
                'https://www.googleapis.com/auth/business.manage',
            ],
        ],

    ],
];
