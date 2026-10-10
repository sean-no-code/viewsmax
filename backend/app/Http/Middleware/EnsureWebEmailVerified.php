<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API host's signup flow mirrors the SPA: verify your email first, then
 * connect channels, then grant the agent access. Unverified web sessions are
 * parked on the "check your email" page.
 *
 * Optional path patterns limit the check to matching requests, for routes
 * this app doesn't declare itself (Passport's /oauth/authorize).
 */
class EnsureWebEmailVerified
{
    public function handle(Request $request, Closure $next, string ...$onlyPaths): Response
    {
        if ($onlyPaths && ! $request->is(...$onlyPaths)) {
            return $next($request);
        }

        $user = $request->user('web');

        if ($user && is_null($user->email_verified_at)) {
            // guest() remembers a GET target (e.g. the consent screen) so the
            // signup flow returns there once the email is verified.
            return redirect()->guest(route('register.verify'));
        }

        return $next($request);
    }
}
