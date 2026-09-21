<?php

use App\Http\Controllers\AiModelController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use Laravel\Mcp\Server\Facades\Mcp;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ComfyUIWebhookController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\DefaultImageController;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\GoalTypeController;
use App\Http\Controllers\ImageGenerationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OutlierController;
use App\Http\Controllers\PixelController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostMediaController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PrivacyConsentController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\ThumbnailController;
use App\Http\Controllers\ThumbnailScoreController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\TitleScoreController;
use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use App\Http\Controllers\UserPlanController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\ViralTitleController;
use App\Http\Controllers\YouTubeOAuthController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\XUserSearchController;
use App\Http\Controllers\SocialPostController;
use App\Http\Controllers\SocialWebhookController;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Email verification (magic link) - public, no auth
Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/auth/resend-verification', [AuthController::class, 'resendVerification']);

// AI agent capability discovery (public, cached) — see AiDiscoveryController.
Route::get('/ai', [\App\Http\Controllers\AiDiscoveryController::class, 'index'])
    ->middleware('throttle:ai-discovery');

// Free transcript tools (public). Server-side so the CaptAPI key stays off the FE.
Route::post('/free-tools/transcript', [\App\Http\Controllers\FreeToolTranscriptController::class, 'store'])
    ->middleware('throttle:transcript');

// Health check endpoint (public)
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'service' => 'Title Embedding API',
        'version' => '1.0.0',
    ]);
});

// Test thumbnail generation endpoint (public for testing)
Route::post('/test/thumbnails', function (Illuminate\Http\Request $request) {
    $request->validate([
        'description' => 'required|string|max:1000',
    ]);

    $thumbnailHelper = app(\App\Services\ThumbnailHelper::class);
    $result = $thumbnailHelper->generateThumbnailsWithFallbackJson($request->description);

    return response()->json($result);
});

// Stripe webhooks (public - no authentication required)
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handleWebhook']);

// ViewsMax Public Tracking Endpoints
Route::post('/track/click', [PixelController::class, 'trackClick']);
Route::post('/track/conversion', [PixelController::class, 'trackConversion']);
Route::post('/track/pageview', [PixelController::class, 'trackPageView']);

// ComfyUI Webhook Endpoint (public - no authentication required)
Route::post('/webhooks/comfyui/completion', [ComfyUIWebhookController::class, 'handleCompletion']);

// MCP server (AI clients: Claude, Cursor, ...). Auth is an OAuth 2.1 access
// token or an MCP API key, both as a Bearer header — see McpAuth. Throttle
// runs first so invalid keys burn the per-IP budget.
Mcp::web('mcp', \App\Mcp\ViewsMaxServer::class)
    ->middleware(['throttle:mcp', 'mcp.auth', 'mcp.audit', 'mcp.notifications']);

// Meta platform lifecycle webhooks (Threads/Facebook/Instagram). Public — the
// signed_request signature is the authentication. Required callback URLs when
// configuring a Meta app's use case.
Route::match(['get', 'post'], '/social/{platform}/deauthorize', [SocialWebhookController::class, 'deauthorize']);
Route::match(['get', 'post'], '/social/{platform}/data-deletion', [SocialWebhookController::class, 'dataDeletion']);

// Protected routes (authentication required)
Route::middleware('api.auth')->group(function () {

    // Outlier Multiplier Endpoint
    Route::get('/multiplier/{videoId}', [OutlierController::class, 'getMultiplier']);

    // Authentication routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    // Per-user preference toggles (e.g. publish-failure emails)
    Route::get('/user/settings', [\App\Http\Controllers\UserSettingsController::class, 'show']);
    Route::patch('/user/settings', [\App\Http\Controllers\UserSettingsController::class, 'update']);

    // Boosts — like-threshold automations per connected X account
    Route::get('/boosts/settings', [\App\Http\Controllers\BoostSettingController::class, 'index']);
    Route::put('/boosts/settings/{socialAccountId}', [\App\Http\Controllers\BoostSettingController::class, 'update']);
    Route::get('/boosts/activity', [\App\Http\Controllers\BoostSettingController::class, 'activity']);

    // Legacy user route
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Title API Routes (protected)
    Route::prefix('titles')->group(function () {
        Route::get('/', [TitleController::class, 'index']);
        Route::post('/', [TitleController::class, 'store'])
            ->middleware(['restrict.free', 'check.credits:'.CreditService::TITLE_GENERATION_OPERATION]);
        Route::post('/search', [TitleController::class, 'search'])
            ->middleware(['restrict.free', 'check.credits:'.CreditService::TITLE_GENERATION_OPERATION]);
        Route::post('/enhance', [TitleController::class, 'enhanceTitlesWithChatGPT'])
            ->middleware(['restrict.free', 'check.credits:'.CreditService::TITLE_GENERATION_OPERATION]);
    });

    // Thumbnail API Routes (protected) — unified resource for generated + copied
    Route::prefix('thumbnails')->group(function () {
        Route::get('/', [ThumbnailController::class, 'index']);
        Route::post('/', [ThumbnailController::class, 'store'])->middleware(['restrict.free', 'check.credits:'.CreditService::THUMBNAIL_GENERATION_OPERATION]);
        Route::post('/copy', [ThumbnailController::class, 'copy'])->middleware(['restrict.free', 'check.credits:'.CreditService::FACE_SWAP_COPY_THUMBNAIL_OPERATION]);
        Route::get('/config', [ThumbnailController::class, 'config']);
        Route::post('/enhance-description', [ThumbnailController::class, 'enhanceDescription'])->middleware('restrict.free');
        Route::get('/{id}', [ThumbnailController::class, 'show']);
        Route::get('/{id}/status', [ThumbnailController::class, 'status']);
        Route::get('/{id}/download', [ThumbnailController::class, 'download']);
        Route::put('/{id}', [ThumbnailController::class, 'update'])->middleware(['restrict.free', 'check.credits:'.CreditService::THUMBNAIL_GENERATION_OPERATION]);
        Route::delete('/{id}', [ThumbnailController::class, 'destroy']);
    });

    // Script API Routes (protected)
    Route::prefix('scripts')->group(function () {
        Route::get('/', [ScriptController::class, 'index']);
        Route::post('/', [ScriptController::class, 'store'])->middleware(['restrict.free', 'check.credits:'.CreditService::SCRIPT_CREATE_OPERATION]);
        Route::get('/{id}', [ScriptController::class, 'show']);
        Route::get('/{id}/history', [ScriptController::class, 'history']);
        Route::get('/{id}/status', [ScriptController::class, 'status']);
        Route::put('/{id}/save', [ScriptController::class, 'save']); //
        Route::put('/{id}', [ScriptController::class, 'update'])->middleware(['restrict.free', 'check.credits:'.CreditService::SCRIPT_UPDATE_OPERATION]);
        Route::delete('/{id}', [ScriptController::class, 'destroy']);
    });

    Route::apiResource('library-components', App\Http\Controllers\LibraryComponentController::class);

    // Script Stages
    Route::put('scripts/{script}/research', [App\Http\Controllers\ScriptResearchController::class, 'update']);
    Route::post('scripts/{script}/research/generate', [App\Http\Controllers\ScriptResearchController::class, 'store']);
    Route::post('scripts/{script}/components', [App\Http\Controllers\ScriptComponentController::class, 'store']);
    Route::get('scripts/{script}/components', [App\Http\Controllers\ScriptComponentController::class, 'index']);
    Route::post('scripts/{script}/generate', [App\Http\Controllers\ScriptGenerationController::class, 'store']);

    // Video API Routes (protected)
    Route::prefix('videos')->group(function () {
        Route::get('/', [VideoController::class, 'index']);
        Route::post('/', [VideoController::class, 'store']);
        Route::get('/{id}', [VideoController::class, 'show']);
        Route::put('/{id}', [VideoController::class, 'update']);
        Route::delete('/{id}', [VideoController::class, 'destroy']);
        Route::post('/{id}/attach-titles', [VideoController::class, 'attachTitles']);
        Route::post('/{id}/detach-titles', [VideoController::class, 'detachTitles']);
        Route::post('/{id}/review', [VideoController::class, 'review'])->middleware('check.credits:'.CreditService::REVIEW_OPERATION);
    });

    // Outlier Search Routes (protected)
    Route::prefix('outliers')->group(function () {
        Route::get('/', [App\Http\Controllers\OutlierController::class, 'index']);
        Route::get('/channels', [App\Http\Controllers\OutlierController::class, 'channels']);
        Route::post('/search', [App\Http\Controllers\OutlierController::class, 'search']);
        Route::post('/fetch', [App\Http\Controllers\OutlierController::class, 'fetchByUrl']);

        // Saved filter presets (per-user)
        Route::get('/saved-filters', [App\Http\Controllers\OutlierSavedFilterController::class, 'index']);
        Route::post('/saved-filters', [App\Http\Controllers\OutlierSavedFilterController::class, 'store']);
        Route::delete('/saved-filters/{id}', [App\Http\Controllers\OutlierSavedFilterController::class, 'destroy']);

        // Competitor channels (per-user)
        Route::get('/competitors', [App\Http\Controllers\OutlierCompetitorController::class, 'index']);
        Route::post('/competitors', [App\Http\Controllers\OutlierCompetitorController::class, 'store']);
        Route::delete('/competitors/{channelId}', [App\Http\Controllers\OutlierCompetitorController::class, 'destroy'])->whereNumber('channelId');

        // Saved-outliers library + tags (per-user)
        Route::get('/tags', [App\Http\Controllers\SavedOutlierController::class, 'tags']);
        Route::get('/library', [App\Http\Controllers\SavedOutlierController::class, 'index']);
        Route::post('/library', [App\Http\Controllers\SavedOutlierController::class, 'store']);
        Route::patch('/library/{id}', [App\Http\Controllers\SavedOutlierController::class, 'update']);
        Route::delete('/library/{id}', [App\Http\Controllers\SavedOutlierController::class, 'destroy']);

        // Single video + AI breakdown (registered last; {platform} is constrained so
        // these can never shadow the literal routes above)
        Route::get('/{platform}/{videoId}', [App\Http\Controllers\OutlierController::class, 'show'])
            ->whereIn('platform', ['youtube', 'tiktok', 'instagram']);
        Route::post('/{platform}/{videoId}/refresh-media', [App\Http\Controllers\OutlierController::class, 'refreshMedia'])
            ->whereIn('platform', ['instagram']);
        Route::post('/{platform}/{videoId}/feature', [App\Http\Controllers\OutlierController::class, 'toggleFeature'])
            ->whereIn('platform', ['youtube', 'tiktok', 'instagram'])->middleware('role:admin');
        Route::get('/{platform}/{videoId}/breakdown', [App\Http\Controllers\OutlierBreakdownController::class, 'show'])
            ->whereIn('platform', ['youtube', 'tiktok', 'instagram']);
        Route::post('/{platform}/{videoId}/breakdown', [App\Http\Controllers\OutlierBreakdownController::class, 'store'])
            ->whereIn('platform', ['youtube', 'tiktok', 'instagram']);
    });

    // Analyzer Status Routes (protected)
    Route::prefix('title-scores')->group(function () {
        Route::get('/{id}/status', [TitleScoreController::class, 'getStatus']);
    });

    Route::prefix('thumbnail-scores')->group(function () {
        Route::get('/{id}/status', [ThumbnailScoreController::class, 'getStatus']);
    });

    // Viral Title API Routes (protected)
    Route::prefix('viral-titles')->group(function () {
        Route::get('/', [ViralTitleController::class, 'index']);
        Route::post('/', [ViralTitleController::class, 'store']);
        Route::get('/{id}', [ViralTitleController::class, 'show']);
        Route::put('/{id}', [ViralTitleController::class, 'update']);
        Route::delete('/{id}', [ViralTitleController::class, 'destroy']);
    });

    // Plan management routes (customer access)
    Route::prefix('plans')->group(function () {
        Route::get('/', [PlanController::class, 'index']);
        Route::get('/{id}', [PlanController::class, 'show']);
    });

    // User plan management routes (authenticated users)
    Route::prefix('user-plans')->group(function () {
        Route::get('/current', [UserPlanController::class, 'getCurrentPlan']);
        Route::post('/checkout', [UserPlanController::class, 'createCheckoutSession']);
        Route::post('/subscribe', [UserPlanController::class, 'subscribe']);
        Route::post('/change-plan', [UserPlanController::class, 'changePlan']);
        Route::post('/cancel', [UserPlanController::class, 'cancel']);
        Route::get('/history', [UserPlanController::class, 'getHistory']);
    });

    // Subscription management routes (authenticated users)
    Route::prefix('subscriptions')->group(function () {
        Route::post('/{subscriptionId}/cancel', [UserPlanController::class, 'cancelSubscriptionViaStripe']);
    });

    // YouTube OAuth routes (authenticated users)
    Route::prefix('auth/youtube')->group(function () {
        Route::post('/exchange', [YouTubeOAuthController::class, 'exchange']);
        Route::post('/refresh', [YouTubeOAuthController::class, 'refresh']);
        Route::get('/status', [YouTubeOAuthController::class, 'status']);
    });

    // Onboarding gate
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);

    // MCP API key (single key per user, rotate-to-invalidate).
    Route::get('/user/api-key', [ApiKeyController::class, 'show']);
    Route::post('/user/api-key/rotate', [ApiKeyController::class, 'rotate'])
        ->middleware('throttle:mcp-key-rotate');

    // MCP audit log — what the user's AI assistants did on their account.
    Route::get('/user/mcp-activity', [\App\Http\Controllers\McpActivityController::class, 'index']);

    // Connections (multi-provider OAuth). YouTube is handled by the explicit
    // route above; this generic exchange covers tiktok|instagram.
    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::delete('/connections/{id}', [ConnectionController::class, 'destroy']);

    // Beehiiv API-key connection (per user; key stored encrypted, never returned).
    Route::get('/beehiiv/connection', [\App\Http\Controllers\BeehiivController::class, 'show']);
    Route::post('/beehiiv/connection', [\App\Http\Controllers\BeehiivController::class, 'store']);
    Route::delete('/beehiiv/connection', [\App\Http\Controllers\BeehiivController::class, 'destroy']);
    Route::get('/beehiiv/posts', [\App\Http\Controllers\BeehiivController::class, 'posts']);
    Route::post('/auth/{provider}/exchange', [ConnectionController::class, 'exchange'])
        ->whereIn('provider', ['tiktok', 'instagram']);

    // TikTok publishing prerequisites: creator info drives the required privacy /
    // interaction options in the composer before a post can be published.
    Route::get('/connections/tiktok/creator-info', [ConnectionController::class, 'tiktokCreatorInfo']);

    // Stripe trial billing
    Route::post('/billing/stripe/setup-intent', [BillingController::class, 'setupIntent']);
    Route::post('/billing/stripe/subscribe', [BillingController::class, 'subscribe']);
    Route::post('/billing/stripe/portal', [BillingController::class, 'billingPortal']);
    Route::get('/billing/status', [BillingController::class, 'status']);

    // Feature requests
    Route::get('/feature-requests', [FeatureRequestController::class, 'index']);
    Route::post('/feature-requests', [FeatureRequestController::class, 'store']);
    Route::post('/feature-requests/{id}/upvote', [FeatureRequestController::class, 'upvote']);

    // Social media accounts: connect & manage (Facebook, Instagram, Threads,
    // LinkedIn, Bluesky, X, TikTok, YouTube, Google My Business).
    Route::prefix('social')->group(function () {
        // Supported platforms + whether each is configured.
        Route::get('/platforms', [SocialAccountController::class, 'platforms']);

        // Connected accounts.
        Route::get('/accounts', [SocialAccountController::class, 'index']);
        Route::delete('/accounts/{id}', [SocialAccountController::class, 'destroy']);

        // OAuth connect flow (frontend opens the URL, then posts back the code).
        Route::get('/{platform}/auth-url', [SocialAccountController::class, 'authUrl']);
        Route::post('/{platform}/exchange', [SocialAccountController::class, 'exchange']);

        // Credential-based connect (non-OAuth platforms, e.g. Bluesky).
        Route::post('/{platform}/connect', [SocialAccountController::class, 'connectWithCredentials']);

        // Published X posts (both stores) — for pinning tracking links.
        Route::get('/x/posts', [SocialPostController::class, 'xPosts']);

        // X user search for the composer's @mention typeahead.
        Route::get('/x/users/search', [XUserSearchController::class, 'search'])->middleware('throttle:x-mention');

        // Posting.
        Route::get('/posts', [SocialPostController::class, 'index']);
        Route::post('/posts', [SocialPostController::class, 'store']);
        Route::get('/posts/{id}', [SocialPostController::class, 'show']);
        Route::post('/posts/{id}/retry', [SocialPostController::class, 'retry']);
    });

    // Channel routes (authenticated users)
    Route::prefix('channels')->group(function () {
        Route::get('/', [ChannelController::class, 'index']);
        Route::get('/{id}', [ChannelController::class, 'show']);
        Route::get('/{id}/videos', [ChannelController::class, 'getVideos']);
        Route::post('/{id}/fetch-videos', [ChannelController::class, 'fetchVideos']);
        Route::post('/{channel}/refresh', [ChannelController::class, 'refresh']);
        Route::get('/{id}/analytics/comprehensive', [ChannelController::class, 'getComprehensiveAnalytics']);
        Route::get('/{id}/playlists', [ChannelController::class, 'getPlaylists']);
        Route::delete('/{id}', [ChannelController::class, 'destroy']);
    });

    // Privacy consent routes (authenticated users)
    Route::prefix('user/consent')->group(function () {
        Route::post('/check', [PrivacyConsentController::class, 'check']);
        Route::post('/record', [PrivacyConsentController::class, 'record']);
        Route::post('/withdraw', [PrivacyConsentController::class, 'withdraw']);
    });

    // AI Model API Routes (protected)
    Route::prefix('ai-models')->group(function () {
        Route::get('/', [AiModelController::class, 'index']);
        Route::post('/', [AiModelController::class, 'store']);
        Route::get('/types', [AiModelController::class, 'getModelTypes']);
        Route::get('/ethnicities', [AiModelController::class, 'getEthnicities']);
        Route::get('/{aiModel}', [AiModelController::class, 'show']);
        Route::delete('/{aiModel}', [AiModelController::class, 'destroy']);
    });

    // Prompt API Routes (protected)
    Route::prefix('prompts')->group(function () {
        Route::get('/', [PromptController::class, 'index']);
        Route::get('/types', [PromptController::class, 'getTypes']);
        Route::get('/type/{typeName}', [PromptController::class, 'getByType']);
        Route::get('/{prompt}', [PromptController::class, 'show']);
        Route::put('/{prompt}', [PromptController::class, 'update']);
    });

    // Image Generation API Routes (Flux 2) - protected
    Route::prefix('image/generate')->group(function () {
        Route::get('/config', [ImageGenerationController::class, 'config']);
        Route::get('/', [ImageGenerationController::class, 'index']);
        Route::post('/', [ImageGenerationController::class, 'store']);
        Route::get('/{id}', [ImageGenerationController::class, 'show']);
        Route::get('/{id}/status', [ImageGenerationController::class, 'status']);
        Route::get('/{id}/download', [ImageGenerationController::class, 'download']);
        Route::delete('/{id}', [ImageGenerationController::class, 'destroy']);
    });

    // User Default Reference Image Routes (protected)
    Route::prefix('user/default-image')->group(function () {
        Route::get('/', [DefaultImageController::class, 'show']);
        Route::post('/', [DefaultImageController::class, 'store']);
        Route::delete('/', [DefaultImageController::class, 'destroy']);
    });

    // Conversion Tracking (Protected/User)
    Route::get('tracking-events/offers', [TrackingEventController::class, 'getOffers']);
    Route::get('tracking-events/stats', [TrackingEventController::class, 'getStats']);
    Route::get('tracking-events/timeseries', [TrackingEventController::class, 'getTimeseries']);
    Route::get('tracking-events/sources', [TrackingEventController::class, 'getSources']);

    // Audience Growth: per-platform follower series + engagement-ranked posts.
    Route::get('analytics/audience', [\App\Http\Controllers\AnalyticsGrowthController::class, 'audience']);
    Route::get('analytics/posts', [\App\Http\Controllers\AnalyticsGrowthController::class, 'posts']);
    Route::get('goal-types', [GoalTypeController::class, 'index']); // Seeded conversion event types
    Route::apiResource('tracking-events', TrackingEventController::class);
    Route::apiResource('tracking-links', TrackingLinkController::class)->except(['index']); // Standard CRUD
    Route::get('/tracking-events/{event}/links', [TrackingLinkController::class, 'index']); // Nested List

    // Content (long-form body + optional media file), attachable to an Offer
    Route::get('contents/{id}/media', [ContentController::class, 'media']);
    Route::apiResource('contents', ContentController::class)->parameters(['contents' => 'id']);

    // Brands: named groups of connected accounts for one-click composer selection.
    Route::apiResource('brands', BrandController::class)->only(['index', 'store', 'update', 'destroy']);

    // Multi-platform Posts (compose + schedule)
    Route::post('/posts/media', [PostMediaController::class, 'store']);
    // Presigned direct-to-R2 media uploads (browser → bucket, no server relay).
    Route::post('/posts/media/direct', [\App\Http\Controllers\PostMediaDirectUploadController::class, 'store']);
    Route::post('/posts/media/direct/complete', [\App\Http\Controllers\PostMediaDirectUploadController::class, 'complete']);
    Route::post('/posts/media/direct/abort', [\App\Http\Controllers\PostMediaDirectUploadController::class, 'abort']);
    // Retry publishing for a single failed platform target (leaves siblings alone).
    Route::post('/posts/{post}/targets/{target}/retry', [PostController::class, 'retryTarget']);
    Route::apiResource('posts', PostController::class);

    // Admin-only routes
    Route::middleware('role:admin')->group(function () {
        // Publishing monitor — all clients' posts + per-platform outcomes.
        Route::get('/admin/posts', [\App\Http\Controllers\Admin\PostMonitorController::class, 'index']);
        Route::get('/admin/posts/stats', [\App\Http\Controllers\Admin\PostMonitorController::class, 'stats']);
        Route::post('/admin/posts/reconcile', [\App\Http\Controllers\Admin\PostMonitorController::class, 'reconcile']);
        Route::post('/admin/posts/{post}/requeue', [\App\Http\Controllers\Admin\PostMonitorController::class, 'requeue']);
        Route::get('/admin/posts/{post}', [\App\Http\Controllers\Admin\PostMonitorController::class, 'show']);

        // Users monitor — index + signup / added-card widgets over a date range.
        // Offers + links monitor across all clients.
        Route::get('/admin/offers', [\App\Http\Controllers\Admin\AnalyticsMonitorController::class, 'offers']);
        Route::get('/admin/links', [\App\Http\Controllers\Admin\AnalyticsMonitorController::class, 'links']);

        Route::get('/admin/users', [\App\Http\Controllers\Admin\UserAdminController::class, 'index']);
        Route::get('/admin/users/stats', [\App\Http\Controllers\Admin\UserAdminController::class, 'stats']);
        Route::get('/admin/users/suggest', [\App\Http\Controllers\Admin\UserAdminController::class, 'suggest']);
        Route::delete('/admin/users/{user}', [\App\Http\Controllers\Admin\UserAdminController::class, 'destroy']);
        Route::get('/admin/users/{user}/accounts', [\App\Http\Controllers\Admin\UserAdminController::class, 'accounts']);
        Route::get('/admin/users/{user}', [\App\Http\Controllers\Admin\UserAdminController::class, 'show'])->whereNumber('user');
        Route::put('/admin/users/{user}/role', [\App\Http\Controllers\Admin\UserAdminController::class, 'updateRole']);

        // Role management
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::post('/assign', [RoleController::class, 'assignRole']);
            Route::post('/remove', [RoleController::class, 'removeRole']);
            Route::get('/user/{userId}', [RoleController::class, 'getUserRoles']);
        });

        // Plan management (admin only)
        Route::prefix('admin/plans')->group(function () {
            Route::post('/', [PlanController::class, 'store']);
            Route::put('/{id}', [PlanController::class, 'update']);
            Route::delete('/{id}', [PlanController::class, 'destroy']);
        });

        // User plan status updates (admin sync helper)
        Route::post('/user-plans/update-status', [UserPlanController::class, 'updateStatus']);
    });
});
