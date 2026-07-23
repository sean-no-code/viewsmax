<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Publish scheduled posts whose time has arrived. The overlap lock is
        // bounded to 10 minutes so a crashed run can't silently wedge the command
        // for the default 24h; onFailure surfaces a failed tick in the log.
        $schedule->command('posts:publish-due')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('posts:publish-due scheduled run failed'));

        // Keep the reach denominator (content views) fresh for the views-based
        // conversion rate. Daily to stay well within YouTube API quota.
        $schedule->command('tracking:refresh-reach')
            ->daily()
            ->withoutOverlapping(30)
            ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('tracking:refresh-reach scheduled run failed'));

        // Requeue targets orphaned in pending/publishing by a worker outage so
        // a restart doesn't strand posts that already flipped to "posted".
        $schedule->command('posts:reconcile-stuck --requeue')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('posts:reconcile-stuck scheduled run failed'));

        // Downgrade users whose paid subscription lapsed back to the free plan.
        $schedule->command('subscriptions:process-expired')
            ->daily()
            ->withoutOverlapping(30)
            ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('subscriptions:process-expired scheduled run failed'));

        // Boost like-threshold checks (auto repost / auto promo). Checks run
        // on a 6-hour cadence, so a 10-minute scan keeps API traffic smooth.
        $schedule->command('boosts:run')
            ->everyTenMinutes()
            ->withoutOverlapping(10)
            ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('boosts:run scheduled run failed'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Ensure CORS runs globally and at the start of the API stack
        $middleware->append([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->prepend([
            \App\Http\Middleware\EnsurePublicTracking::class,
        ]);
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ],
        append: [
            \App\Http\Middleware\AttachCreditsToResponse::class,
        ]);
        
        $middleware->alias([
            'plan' => \App\Http\Middleware\CheckPlan::class,
            'api.auth' => \App\Http\Middleware\ApiAuth::class,
            'mcp.auth' => \App\Http\Middleware\McpAuth::class,
            'mcp.audit' => \App\Http\Middleware\McpAuditLog::class,
            'check.credits' => \App\Http\Middleware\CheckCredits::class,
            'restrict.free' => \App\Http\Middleware\RestrictFreePlan::class,
        ]);

        // Dynamic Client Registration (RFC 7591) is a plain JSON API call made
        // directly by an OAuth client (e.g. Claude), not a browser form
        // submission — it has no CSRF token and shouldn't need one. It's
        // registered via Mcp::oauthRoutes() in routes/web.php so it inherits
        // the 'web' group's CSRF middleware unless excluded here.
        $middleware->validateCsrfTokens(except: [
            'oauth/register',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ensure API routes always return JSON responses
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                // Handle validation exceptions properly
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    // Log validation errors to both file and Slack
                    \Illuminate\Support\Facades\Log::error('Validation Error (422)', [
                        'url' => $request->url(),
                        'method' => $request->method(),
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'user_id' => auth()->id(),
                        'validation_errors' => $e->errors(),
                        'input_data' => $request->except(['password', 'password_confirmation', 'token']),
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                        'errors' => $e->errors()
                    ], 422);
                }
                
                // Log other exceptions
                \Illuminate\Support\Facades\Log::error('API Error', [
                    'url' => $request->url(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'user_id' => auth()->id(),
                    'exception_message' => $e->getMessage(),
                    'exception_code' => $e->getCode(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                ]);
                
                // Resolve a valid integer HTTP status. Never use $e->getCode()
                // directly: DB exceptions (QueryException) return a SQLSTATE string
                // like "HY000", which is truthy and crashes JsonResponse (expects int).
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $status = $e->getStatusCode();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $status = 401;
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $status = 404;
                } else {
                    $code = $e->getCode();
                    $status = (is_int($code) && $code >= 400 && $code < 600) ? $code : 500;
                }

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => config('app.debug') ? [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ] : null
                ], $status);
            }
        });
    })->create();
