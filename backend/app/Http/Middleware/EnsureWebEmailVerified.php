<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API host's signup flow mirrors the SPA: verify your email first, then
 * connect channels, then grant the agent access. Unverified web sessions are
 * parked on the "check your email" page.
 */
class EnsureWebEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user && is_null($user->email_verified_at)) {
            return redirect()->route('register.verify');
        }

        return $next($request);
    }
}
