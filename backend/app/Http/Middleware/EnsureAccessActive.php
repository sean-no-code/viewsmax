<?php

namespace App\Http\Middleware;

use App\Support\UserAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST delivery of UserAccess: a user who can't use ViewsMax right now
 * (unverified email, or a free window that closed with no subscription
 * since) is locked to the endpoints needed to fix that — their own
 * profile/session, plan listings, checkout. Everything else answers 403 with
 * the denial (`code`, `message`, `url`); the SPA keys off `code`
 * (`access_expired` sends them to Billing). Applied after `api.auth` on the
 * whole protected group.
 */
class EnsureAccessActive
{
    /**
     * Routes a denied user may still call. Patterns go to Request::is(), so
     * wildcards are explicit.
     */
    public const ALLOWED_PATTERNS = [
        'api/profile',
        'api/logout',
        'api/refresh',
        'api/plans', 'api/plans/*',
        'api/user-plans/*',
        'api/billing/*',
        'api/subscriptions/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $denial = $user ? UserAccess::denial($user) : null;

        if ($denial && ! $request->is(...self::ALLOWED_PATTERNS)) {
            return response()->json($denial->toArray(), 403);
        }

        return $next($request);
    }
}
