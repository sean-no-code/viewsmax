<?php

namespace App\Http\Middleware;

use App\Support\UserAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST delivery of UserAccess: a user who can't use ViewsMax right now
 * (unverified email, no card added yet, a subscription that ended, or a
 * free window that closed with no subscription since) is locked to the
 * endpoints needed to fix that — their own profile/session, plan listings,
 * checkout, and at the card step the rest of what the SPA's onboarding
 * wizard calls. Everything else answers with the denial (`code`, `message`,
 * `url`): HTTP 402 with an `error` object (`code`, `message`,
 * `upgrade_url`) when paying fixes it, 403 for an unverified email
 * (AccessDenial::status). A disabled account (users.disabled_at) gets a 403
 * on everything but logout. Applied after `api.auth` on the whole protected
 * group.
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

    /**
     * Also open at the card step (UserAccess::PAYMENT_REQUIRED): what the
     * onboarding wizard calls before the card — connecting channels and
     * finishing onboarding. Not posting, offers or analytics.
     */
    public const ONBOARDING_PATTERNS = [
        'api/onboarding/*',
        'api/connections', 'api/connections/*',
        'api/auth/*',
        'api/social/platforms',
        'api/social/accounts', 'api/social/accounts/*',
        'api/social/*/auth-url', 'api/social/*/exchange', 'api/social/*/connect',
    ];

    /**
     * All a disabled account (UserAccess::ACCOUNT_DISABLED) may still call:
     * signing out. Nothing it can do fixes the denial, so profile, plans and
     * billing answer with it too.
     */
    public const DISABLED_PATTERNS = ['api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $denial = $user ? UserAccess::denial($user) : null;
        $allowed = match ($denial?->code) {
            UserAccess::ACCOUNT_DISABLED => self::DISABLED_PATTERNS,
            UserAccess::PAYMENT_REQUIRED => [...self::ALLOWED_PATTERNS, ...self::ONBOARDING_PATTERNS],
            default => self::ALLOWED_PATTERNS,
        };

        if ($denial && ! $request->is(...$allowed)) {
            return response()->json($denial->toArray(), $denial->status());
        }

        return $next($request);
    }
}
