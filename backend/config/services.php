<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
        'fetch_period_days' => env('YOUTUBE_FETCH_PERIOD_DAYS', 2),
        'video_cache_duration_hours' => env('YOUTUBE_VIDEO_CACHE_DURATION_HOURS', 1),
        'channel_cache_duration_hours' => env('YOUTUBE_CHANNEL_CACHE_DURATION_HOURS', 24),
        // Shorts detection via HEAD youtube.com/shorts/{id}. Kill switch → duration fallback.
        'format_probe_enabled' => env('YOUTUBE_FORMAT_PROBE_ENABLED', true),
        'format_probe_delay_ms' => env('YOUTUBE_FORMAT_PROBE_DELAY_MS', 250),
        'sample_size' => env('YOUTUBE_AVERAGE_SAMPLE_SIZE', 30),
        'max_pages' => env('YOUTUBE_MAX_PAGES', 5),
        'max_search_results' => env('YOUTUBE_MAX_SEARCH_RESULTS', 50),
        // search.list pages per duration bucket (each page = 100 quota units;
        // a term costs 2 buckets × this many pages).
        'search_pages' => env('YOUTUBE_SEARCH_PAGES', 2),
        'always_fetch_api_for_multiplier' => env('ALWAYS_FETCH_API_FOR_MULTIPLIER', false),
        'min_outlier_score' => env('YOUTUBE_MIN_OUTLIER_SCORE', 20),
    ],

    'outliers' => [
        // "Add a creator channel by @handle" (REST endpoint + MCP tools). Kill switch:
        // each add spends YouTube quota or CaptAPI credits.
        'channel_ingest_enabled' => env('OUTLIER_CHANNEL_INGEST_ENABLED', true),
        // Copy TikTok/Instagram thumbnails onto the media disk at ingest. Their CDN
        // URLs are signed and expire within days, and fbcdn is on most tracker
        // blocklists, so the raw URLs don't render reliably in the app.
        'rehost_thumbnails' => env('OUTLIER_REHOST_THUMBNAILS', true),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'chat_api_url' => env('OPENAI_CHAT_API_URL', 'https://api.openai.com/v1/chat/completions'),
        'image_api_url' => env('OPENAI_IMAGE_API_URL', 'https://api.openai.com/v1/images/generations'),
        'model' => env('OPENAI_MODEL', 'gpt-4-turbo'), // Default to gpt-4-turbo for larger context window (128k tokens)
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'max_output_tokens' => env('ANTHROPIC_MAX_OUTPUT_TOKENS', 16384),
        'timeout' => env('ANTHROPIC_TIMEOUT', 600),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'api_url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        'model' => env('GEMINI_MODEL', 'gemini-3-pro-image-preview'),
    ],

    'thumbnail' => [
        'default_service' => env('THUMBNAIL_SERVICE', 'openai'), // 'openai', 'gemini', 'flux', or 'flux2'
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    'replicates' => [
        'api_key' => env('REPLICATES_API_KEY'),
        'api_url' => env('REPLICATES_API_URL', 'https://api.replicate.com/v1'),
        'model_version' => env('REPLICATES_MODEL_VERSION', 'your-model-version'),
        'destination_namespace' => env('REPLICATES_DESTINATION_NAMESPACE', 'iclicksee'),
    ],

    'huggingface' => [
        'api_key' => env('HUGGINGFACE_API_KEY'),
        'api_url' => env('HUGGINGFACE_API_URL', 'https://huggingface.co/api'),
        'namespace' => env('HUGGINGFACE_NAMESPACE', 'iclicksee'),
        'model_type' => env('HUGGINGFACE_MODEL_TYPE', 'lora'),
    ],

    'youtube_transcript' => [
        'api_key' => env('YOUTUBE_TRANSCRIPT_API_KEY'),
        'api_url' => env('YOUTUBE_TRANSCRIPT_API_URL', 'https://www.youtube-transcript.io/api'),
    ],

    'comfyui' => [
        'server_url' => env('COMFYUI_SERVER_URL'),
        'enabled' => env('COMFYUI_ENABLED', true),
        'lora_path' => env('COMFYUI_LORA_PATH', 'models/loras'),
        // Steps: 30-40 recommended (more steps = sharper but slower)
        'default_steps' => env('COMFYUI_DEFAULT_STEPS', 35),
        // CFG/Guidance: 3.5-5.0 for Flux (too low = blank images, too high = artifacts)
        'default_cfg' => env('COMFYUI_DEFAULT_CFG', 3.5),
        'default_width' => env('COMFYUI_DEFAULT_WIDTH', 1280),
        'default_height' => env('COMFYUI_DEFAULT_HEIGHT', 720),
        // SSH settings for uploading LoRA files via SCP (used by model:push-to-comfyui command)
        'ssh_host' => env('COMFYUI_SSH_HOST'),
        'ssh_user' => env('COMFYUI_SSH_USER', 'ubuntu'),
        'ssh_key_path' => env('COMFYUI_SSH_KEY_PATH'),
        'remote_lora_path' => env('COMFYUI_REMOTE_LORA_PATH', '/home/ubuntu/ComfyUI/models/loras'),
    ],

    // Choose which service to use for custom AI model thumbnail generation
    // Options: 'replicate', 'comfyui'
    'ai_model_thumbnail' => [
        'default_service' => env('AI_MODEL_THUMBNAIL_SERVICE', 'comfyui'),
    ],

    // Copy Thumbnail feature configuration (ComfyUI-based face/style transfer)
    'copy_thumbnail' => [
        'enabled' => env('COPY_THUMBNAIL_ENABLED', true),
        // Output resolution (applied to workflow nodes 175, 399, 420)
        'default_width' => env('COPY_THUMBNAIL_WIDTH', 1024),
        'default_height' => env('COPY_THUMBNAIL_HEIGHT', 1024),
        // DW Pose Estimator toggle (node 475)
        'dw_pose_enabled' => env('COPY_THUMBNAIL_DW_POSE_ENABLED', false),
        // Sampler settings (node 346)
        'default_steps' => env('COPY_THUMBNAIL_STEPS', 25),
        'default_cfg' => env('COPY_THUMBNAIL_CFG', 1),
        'default_denoise' => env('COPY_THUMBNAIL_DENOISE', .85),
        // FluxGuidance (node 345)
        'guidance' => env('COPY_THUMBNAIL_GUIDANCE', 3.5),
    ],

    // Flux 2 Image Generation feature configuration
    'flux2' => [
        'enabled' => env('FLUX2_ENABLED', true),
        // Quality presets (megapixel values)
        'quality_presets' => [
            'fast' => 0.3,
            'normal' => 0.5,
            'high' => 1.0,
            'very_high' => 2.0,
        ],
        'default_quality' => env('FLUX2_DEFAULT_QUALITY', 'fast'),
        // Steps configuration
        'default_steps' => env('FLUX2_DEFAULT_STEPS', 4),
        // Refine option (uses node 172 for refinement pass)
        'refine_enabled' => env('FLUX2_REFINE_ENABLED', false),
        // Inpainting switch (node 162)
        'inpainting_enabled' => env('FLUX2_INPAINTING_ENABLED', true),
        // LoRA files for different generation methods
        'head_swap_lora' => env('FLUX2_HEAD_SWAP_LORA', 'bfs_head_v1_flux-klein_9b_step3500_rank128.safetensors'),
        'generation_lora' => env('FLUX2_GENERATION_LORA', 'bfs_head_v1_flux-klein_9b_step3500_rank128.safetensors'), // Default to head swap LoRA since it exists
        // Timeout and polling settings (seconds)
        'timeout' => env('FLUX2_TIMEOUT', 360), // 6 minutes max
        'poll_base_time' => env('FLUX2_POLL_BASE_TIME', 20), // Base poll time in seconds (~20s for fast quality)
        'poll_step_multiplier' => env('FLUX2_POLL_STEP_MULTIPLIER', 5), // Additional seconds per step
        // Webhook settings
        'webhook_enabled' => env('FLUX2_WEBHOOK_ENABLED', false), // Webhook feature disabled by default
        'webhook_url' => env('FLUX2_WEBHOOK_URL'), // Custom webhook URL (for ngrok, etc.)
        'webhook_timeout' => env('FLUX2_WEBHOOK_TIMEOUT', 30), // Webhook timeout in seconds
        // Polling interval when webhooks are disabled (seconds)
        'poll_interval' => env('FLUX2_POLL_INTERVAL', 1), // Poll every 1 second when webhooks disabled
        // Number of images (batch size)
        'default_number_of_images' => env('FLUX2_DEFAULT_NUMBER_OF_IMAGES', 1),
        'max_number_of_images' => env('FLUX2_MAX_NUMBER_OF_IMAGES', 10),
        // Prompts appended to user input
        'generation_prompt_suffix' => "realistic shadow contact, natural skin texture, and uniform sharpness.\nPhotorealistic, high quality, sharp details, 4K",
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'signature_check' => env('STRIPE_SIGNATURE_CHECK', true),
        // Normalized to an int here: a set-but-empty env var comes through as
        // '' (env() only defaults when the var is absent), and consumers do
        // date math with this value.
        'subscription_grace_period_hours' => is_numeric(env('SUBSCRIPTION_GRACE_PERIOD_HOURS', 24))
            ? (int) env('SUBSCRIPTION_GRACE_PERIOD_HOURS', 24)
            : 24,
        'default_plan' => env('STRIPE_DEFAULT_PLAN', 'creator_pro'),
        'success_url' => env('STRIPE_SUCCESS_URL'),
        'cancel_url' => env('STRIPE_CANCEL_URL'),
        // Where the Billing Portal returns the user after they update a card /
        // pay an invoice. Falls back to the billing page in the SPA.
        'portal_return_url' => env('STRIPE_PORTAL_RETURN_URL'),
        // $29/mo recurring price used for the onboarding trial subscription.
        'trial_price_id' => env('STRIPE_TRIAL_PRICE_ID'),
        'trial_period_days' => env('STRIPE_TRIAL_PERIOD_DAYS', 7),
        // How many hours before a trial ends to send the "card about to be charged"
        // reminder. Guarded like subscription_grace_period_hours: a set-but-empty env
        // arrives as '' and would TypeError in the command's date math.
        'trial_reminder_hours_before' => is_numeric(env('STRIPE_TRIAL_REMINDER_HOURS_BEFORE', 48))
            ? (int) env('STRIPE_TRIAL_REMINDER_HOURS_BEFORE', 48)
            : 48,
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        // Content Sharing Guidelines, Technical Consideration 2(d): video that
        // already lives on our storage must be pulled by TikTok, not pushed as
        // chunks. Defaults on, so compliance does not depend on remembering to
        // set it; turn it off only where TikTok cannot reach the app to pull.
        'video_pull_from_url' => env('TIKTOK_VIDEO_PULL_FROM_URL', true),
    ],

    'meta' => [
        'client_id' => env('META_CLIENT_ID'),
        'client_secret' => env('META_CLIENT_SECRET'),
    ],

    'perplexity' => [
        'api_key' => env('PERPLEXITY_API_KEY'),
        'api_url' => env('PERPLEXITY_API_URL', 'https://api.perplexity.ai/chat/completions'),
        'model' => env('PERPLEXITY_MODEL', 'sonar-reasoning-pro'),
        'timeout' => env('PERPLEXITY_TIMEOUT', 600),
    ],

    'kit' => [
        'api_key' => env('KIT_API_KEY'),
        'api_secret' => env('KIT_API_SECRET'),
        // Converted tag: applied when a user starts a subscription (see SyncKitOnSubscription).
        'tag' => env('KIT_TAG', 'viewsmax: new subscriber'),
        // Whether to push subscribers to the live Kit list. Unset (null) means
        // "production only", so local/dev/staging signups don't pollute the real
        // newsletter. Set KIT_ENABLED=true/false to force it on/off in any env.
        'enabled' => env('KIT_ENABLED'),
        // Abandoned-cart tag: applied by kit:tag-abandoned-carts to users who signed
        // up but never started a subscription. Removed again on conversion.
        'abandoned_cart_tag' => env('KIT_ABANDONED_CART_TAG', 'viewsmax: abandoned cart'),
        // Eligibility slice for kit:tag-abandoned-carts. window_hours MUST equal the
        // scheduler cadence (everyThreeHours in bootstrap/app.php) so consecutive runs
        // tile the created_at timeline and each user is tagged exactly once (no DB flag).
        'abandoned_cart_window_hours' => (int) env('KIT_ABANDONED_CART_WINDOW_HOURS', 3),
        'abandoned_cart_min_age_hours' => (int) env('KIT_ABANDONED_CART_MIN_AGE_HOURS', 1),
    ],

    'captapi' => [
        // Transcript provider for the free tools. Key is server-side only.
        'api_key' => env('CAPTAPIKEY'),
        'base_url' => env('CAPTAPI_BASE_URL', 'https://api.captapi.com/v1'),
    ],

];
