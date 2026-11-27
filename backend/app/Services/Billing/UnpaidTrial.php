<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Services\CreditService;
use Illuminate\Support\Facades\DB;

/**
 * A trial that is cancelled before its first invoice is paid ends there. The
 * row is `trialing` until that invoice settles (invoice.paid makes it
 * `active`), so `trialing` is exactly "nothing was charged": there is no paid
 * period to run out, and the free window the trial ran on (the trial's end
 * date is users.promo_expires_at) closes with it.
 *
 * This only writes the local state. After it, the ordinary rules lock the
 * account (User::accessExpired -> UserAccess::denial): 402 on the REST API,
 * the "subscription has ended" tool result on MCP, no background work. Every
 * path that learns of such a cancel calls it: the app's cancel endpoint and
 * the customer.subscription.updated / .deleted webhooks. Ending the
 * subscription on Stripe is the caller's job.
 *
 * A paid (`active`) subscription never comes here: it keeps its graceful
 * cancel, access until the end of the period that was paid for.
 */
class UnpaidTrial
{
    /**
     * Close the user's rows of this subscription that are still an unpaid
     * trial. False when there were none (already closed, or it was paid), so
     * a redelivered webhook changes nothing.
     */
    public static function close(User $user, string $subscriptionId): bool
    {
        $now = now();

        $closed = DB::table('user_plans')
            ->where('user_id', $user->id)
            ->where('stripe_subscription_id', $subscriptionId)
            ->where('status', 'trialing')
            ->update([
                'status' => 'expired',
                'cancelled_at' => DB::raw("COALESCE(cancelled_at, '{$now->toDateTimeString()}')"),
                'expires_at' => $now,
                'updated_at' => $now,
            ]);

        if ($closed === 0) {
            return false;
        }

        // Another live plan (the subscription that replaced this one) keeps
        // its window and its credits.
        if (! $user->hasActivePlan()) {
            if ($user->freeWindowOpen()) {
                $user->forceFill(['promo_expires_at' => $now])->save();
            }

            // As the expiry runs do: an ended plan keeps no credits.
            app(CreditService::class)->resetBalance($user, 0);
        }

        BillingLog::info('Unpaid trial cancelled: access ended now', BillingLog::context($user, $subscriptionId));

        return true;
    }
}
