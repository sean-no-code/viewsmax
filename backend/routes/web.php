<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Facades\Mcp;

Route::get('/', function () {
    return view('welcome');
});

// OAuth 2.1 discovery for the MCP server: protected-resource metadata,
// authorization-server metadata, and Dynamic Client Registration. These are
// well-known URIs and must live at the true root, not under /api's prefix —
// that's why this call is here rather than in routes/api.php.
Mcp::oauthRoutes();

// RFC 9728 path-suffixed variant: for a resource at /api/mcp, clients try
// /.well-known/oauth-protected-resource/api/mcp before the root document
// Mcp::oauthRoutes() serves. Here the resource field carries the full
// resource URL (including path), which must exactly match the URL clients
// were given.
Route::get('/.well-known/oauth-protected-resource/api/mcp', function () {
    return response()->json([
        'resource' => url('/api/mcp'),
        'authorization_server' => url('/.well-known/oauth-authorization-server'),
    ]);
});

// This app is otherwise API-only (the SPA does its own token-based login),
// but Passport's OAuth authorize/consent screen needs a real browser session
// (the 'web' guard) — this is the one place a session-based login is needed,
// so unauthenticated browser hits to /oauth/authorize land here instead of
// the SPA's token-based login.
Route::get('/login', function (Request $request) {
    if ($request->expectsJson()) {
        return response()->json([
            'message' => 'Authentication required. Please use the API login endpoint.',
            'api_login_url' => url('/api/login'),
        ], 401);
    }

    return view('auth.login');
})->name('login');

Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    if (! \Illuminate\Support\Facades\Auth::guard('web')->attempt($credentials)) {
        return back()->withErrors(['email' => 'Those credentials don\'t match an account.'])->onlyInput('email');
    }

    $request->session()->regenerate();

    return redirect()->intended('/');
});

Route::get('/oauth/callback', function (Request $request) {
    $code = $request->get('code');
    $error = $request->get('error');
    $state = $request->get('state');

    $frontendUrl = env('FRONTEND_URL', '<localhost:5173>');

    if ($error) {
        return redirect("{$frontendUrl}/oauth/callback?error=" . urlencode($error));
    }

    if ($code) {
        return redirect("{$frontendUrl}/oauth/callback?code=" . urlencode($code) . "&state=" . urlencode($state));
    }

    return redirect("{$frontendUrl}/oauth/callback");
});

// Tracked shortlink redirect — public, no auth (links live in social captions).
Route::get('/l/{slug}', \App\Http\Controllers\ShortLinkRedirectController::class)
    ->where('slug', '[A-Za-z0-9]+');

// Signed proxy that streams post media from R2 through our verified domain, so
// TikTok/Instagram PULL_FROM_URL can fetch it. The signature is the access
// control — external platforms fetch server-side and can't authenticate.
Route::get('/media/download/{ref}', \App\Http\Controllers\MediaDownloadController::class)
    ->middleware('signed')
    ->name('media.download');

// Public route to serve thumbnail images
Route::get('/thumbnails/{userId}/{thumbnailId}/{filename}', function ($userId, $thumbnailId, $filename) {
    $filePath = "thumbnails/{$userId}/{$thumbnailId}/{$filename}";
    
    if (!Storage::disk('public')->exists($filePath)) {
        abort(404, 'Thumbnail not found');
    }
    
    $file = Storage::disk('public')->get($filePath);
    $mimeType = Storage::disk('public')->mimeType($filePath);
    
    return response($file, 200)
        ->header('Content-Type', $mimeType)
        ->header('Cache-Control', 'public, max-age=3600');
})->name('thumbnails.public');

// Public route to serve AI model images
Route::get('/ai-models/{userId}/{filename}', function ($userId, $filename) {
    $filePath = "ai-models/{$userId}/{$filename}";
    
    if (!Storage::disk('public')->exists($filePath)) {
        abort(404, 'AI model image not found');
    }
    
    $file = Storage::disk('public')->get($filePath);
    $mimeType = Storage::disk('public')->mimeType($filePath);
    
    return response($file, 200)
        ->header('Content-Type', $mimeType)
        ->header('Cache-Control', 'public, max-age=3600');
})->name('ai-models.public');

// Public route to serve temporary zip files (for Replicate training)
Route::get('/storage/temp/{filename}', function ($filename) {
    $filePath = "temp/{$filename}";
    
    if (!Storage::disk('public')->exists($filePath)) {
        abort(404, 'Zip file not found');
    }
    
    $file = Storage::disk('public')->get($filePath);
    
    return response($file, 200)
        ->header('Content-Type', 'application/zip')
        ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
        ->header('Cache-Control', 'public, max-age=3600');
})->name('temp.zip.public');


