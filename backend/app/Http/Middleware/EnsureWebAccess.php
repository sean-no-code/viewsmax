<?php

namespace App\Http\Middleware;

use App\Support\UserAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web delivery of UserAccess for sessions on the 'web' guard (the agent
 * signup flow and Passport's consent screen). An unverified user is parked
 * on the "check your email" page and, once verified, lands back where they
 * were headed; an expired trial is sent to Billing in the app.
 *
 * Optional path patterns limit the check to matching requests, for routes
 * this app doesn't declare itself (Passport's /oauth/authorize).
 */
class EnsureWebAccess
{
    public function handle(Request $request, Closure $next, string ...$onlyPaths): Response
    {
        if ($onlyPaths && ! $request->is(...$onlyPaths)) {
            return $next($request);
        }

        $user = $request->user('web');
        $denial = $user ? UserAccess::denial($user) : null;

        if (! $denial) {
            return $next($request);
        }

        if ($denial->code === UserAccess::EMAIL_UNVERIFIED) {
            // guest() remembers a GET target (e.g. the consent screen) so the
            // signup flow returns there once the email is verified.
            return redirect()->guest(route('register.verify'));
        }

        return redirect()->away($denial->url);
    }
}
