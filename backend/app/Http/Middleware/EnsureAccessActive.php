<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks a user whose free access has ended (a self-signup who used up their
 * free credits, or a promotional customer whose window closed) and who has not
 * subscribed since to the endpoints needed to pick a plan. Everything else
 * answers 403 with `code: access_expired` so the SPA can send them to Billing.
 * Applied after `api.auth` on the whole protected group.
 */
class EnsureAccessActive
{
    /**
     * Routes an expired user may still call: their own profile/session, plan
     * listings, and the Stripe checkout/portal endpoints. Patterns go to
     * Request::is(), so wildcards are explicit.
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
     * Minutes a free-credit user who just spent their last credits can still
     * view things (GET only), so what they paid for finishes loading: search
     * results, a breakdown being generated, a channel import. The SPA sends
     * them to Billing on their next click.
     */
    public const LAST_CHARGE_VIEW_MINUTES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->accessExpired() && ! $request->is(...self::ALLOWED_PATTERNS)
            && ! $this->viewingWhatTheyPaidFor($request, $user)) {
            return response()->json([
                'success' => false,
                'code' => 'access_expired',
                'message' => $user->onFreeCredits()
                    ? "You've used all your free credits. Choose a plan to keep using ViewsMax."
                    : 'Your free access has ended. Choose a plan to keep using ViewsMax.',
            ], 403);
        }

        return $next($request);
    }

    private function viewingWhatTheyPaidFor(Request $request, User $user): bool
    {
        if (! $request->isMethod('GET') || ! $user->onFreeCredits()) {
            return false;
        }

        $lastCharge = $user->transactions()->where('type', 'withdraw')->latest('id')->value('created_at');

        return $lastCharge !== null
            && Carbon::parse($lastCharge)->gt(now()->subMinutes(self::LAST_CHARGE_VIEW_MINUTES));
    }
}
