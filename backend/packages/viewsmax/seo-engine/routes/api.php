<?php

use Illuminate\Support\Facades\Route;
use ViewsMax\SeoEngine\Http\SeoEngineController;

// User-scoped SEO API. Middleware comes from config so a host app with a
// different auth stack can override it without forking the package.
Route::prefix('api/seo')
    ->middleware(config('seo-engine.route_middleware', ['api', 'auth:sanctum']))
    ->group(function () {
        Route::get('/profiles', [SeoEngineController::class, 'profiles']);
        Route::put('/profiles/offer/{offerId}', [SeoEngineController::class, 'upsertProfile']);
        Route::get('/profiles/{profileId}/keywords', [SeoEngineController::class, 'keywords']);
        Route::get('/profiles/{profileId}/articles', [SeoEngineController::class, 'articles']);
        Route::get('/profiles/{profileId}/prospects', [SeoEngineController::class, 'prospects']);
        Route::patch('/keywords/{id}', [SeoEngineController::class, 'updateKeyword']);
        Route::patch('/articles/{id}', [SeoEngineController::class, 'updateArticle']);
        Route::patch('/prospects/{id}', [SeoEngineController::class, 'updateProspect']);
        // Bulk CRM: one status for many prospects (checkbox multi-select in the UI).
        Route::patch('/prospects', [SeoEngineController::class, 'bulkUpdateProspects']);
    });
