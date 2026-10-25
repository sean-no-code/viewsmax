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
 * were headed; a missing card is sent to the card step (on this host for
 * agent signups) and an expired trial to Billing in the app.
 *
 * Optional path patterns limit the check to matching requests, for routes
 * this app doesn't declare itself (Passport's /oauth/authorize).
 */
class EnsureWebAccess
{
    /** Open at the card step (UserAccess::PAYMENT_REQUIRED): the steps before it. */
    public const ONBOARDING_ROUTES = ['register.setup', 'connect.*'];

    public function handle(Request $request, Closure $next, string ...$onlyPaths): Response
    {
        if ($onlyPaths && ! $request->is(...$onlyPaths)) {
            return $next($request);
        }

        $user = $request->user('web');
        $denial = $user ? UserAccess::denial($user) : null;

        // Already where the denial sends them: the card step page and its POST.
        if (! $denial || $request->url() === $denial->url) {
            return $next($request);
        }

        // Connecting channels comes before the card; consent and continue don't.
        if ($denial->code === UserAccess::PAYMENT_REQUIRED && $request->routeIs(...self::ONBOARDING_ROUTES)) {
            return $next($request);
        }

        if ($denial->code === UserAccess::EMAIL_UNVERIFIED) {
            // guest() remembers a GET target (e.g. the consent screen) so the
            // signup flow returns there once the email is verified.
            return redirect()->guest(route('register.verify'));
        }

        if ($denial->code === UserAccess::PAYMENT_REQUIRED) {
            // Same: the card step continues to the remembered consent screen.
            return redirect()->guest($denial->url);
        }

        return redirect()->away($denial->url);
    }
}
