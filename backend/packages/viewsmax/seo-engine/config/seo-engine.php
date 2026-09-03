<?php

return [

    // Master kill-switch: when false, every seo:* command exits immediately and
    // nothing is scheduled. Flip off to freeze the whole engine platform-wide.
    // Per-user control lives on each SeoProfile's `enabled` flag.
    'enabled' => env('SEO_ENGINE_ENABLED', false),

    // Host-app model backing "offers" (what an SEO profile promotes).
    'offer_model' => \App\Models\Offer::class,

    // API middleware — override in the host app if its auth stack differs.
    'route_middleware' => ['api', 'auth:sanctum'],

    // Keyword acceptance thresholds (DataForSEO metrics). Platform-wide;
    // per-user competitors/blog/cadence live on the SeoProfile.
    'keywords' => [
        'min_search_volume' => (int) env('SEO_MIN_VOLUME', 50),
        'max_difficulty' => (int) env('SEO_MAX_DIFFICULTY', 45),
        // High-intent modifiers: a keyword containing one scores as commercial.
        'intent_terms' => [
            'best', 'vs', 'versus', 'alternative', 'alternatives', 'review', 'reviews',
            'pricing', 'price', 'cost', 'tool', 'tools', 'software', 'app', 'scheduler',
            'how to', 'top', 'free', 'cheap', 'comparison',
        ],
    ],

    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com/v3'),
        'location_code' => (int) env('DATAFORSEO_LOCATION', 2840), // United States
        'language_code' => env('DATAFORSEO_LANGUAGE', 'en'),
    ],

    // Anthropic drafting — reuses the host app's services.anthropic config
    // unless overridden here.
    'writer' => [
        'model' => env('SEO_WRITER_MODEL'), // null -> services.anthropic.model
        'max_tokens' => (int) env('SEO_WRITER_MAX_TOKENS', 4096),
        'min_words' => (int) env('SEO_MIN_WORDS', 1200),
    ],

    // Article images — a featured (hero) image plus inline section figures,
    // generated with the OpenAI Images API (reuses services.openai config).
    // Generated files are stored on the configured disk; the WordPress
    // publisher sideloads the featured image into the WP media library.
    'images' => [
        'enabled' => env('SEO_IMAGES_ENABLED', true),
        // Inline <figure> images injected after section headings, in addition
        // to the featured image. 0 = featured image only.
        'per_article' => (int) env('SEO_IMAGES_PER_ARTICLE', 2),
        'model' => env('SEO_IMAGES_MODEL', 'gpt-image-1'),
        'size' => env('SEO_IMAGES_SIZE', '1536x1024'),
        'quality' => env('SEO_IMAGES_QUALITY', 'medium'),
        // Art direction appended to every image prompt. Keep "no text" — image
        // models garble lettering.
        'style' => env('SEO_IMAGES_STYLE', 'Clean, modern editorial illustration with soft lighting. No text, lettering, watermarks or logos.'),
        // Filesystem disk for the generated files — must be publicly readable
        // (use S3 in production). Defaults to Laravel's `public` disk.
        'disk' => env('SEO_IMAGES_DISK', 'public'),
        'path' => env('SEO_IMAGES_PATH', 'seo-articles'),
    ],

    'backlinks' => [
        // Max prospects to pull per competitor per run.
        'per_competitor' => (int) env('SEO_BACKLINKS_PER_COMPETITOR', 25),
        // Ignore prospect domains below this DataForSEO domain rank.
        'min_domain_rank' => (int) env('SEO_BACKLINKS_MIN_RANK', 100),
    ],
];
