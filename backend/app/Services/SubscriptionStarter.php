<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Support\AnalyticsAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Starts a Stripe subscription on a vaulted card and records it on the
 * user's plans. Shared by the SPA's checkout (BillingController::subscribe)
 * and the agent signup's card step on this host (WebBillingController), so
 * both charge the same terms: User::subscriptionTrialTerms.
 */
class SubscriptionStarter
{
    public function __construct(protected StripeService $stripe) {}

    /**
     * @return array{subscription_id: string, status: string, expires_at: Carbon, price_id: string, plan: ?Plan}
     *
     * @throws BillingNotConfiguredException when no price can be resolved
     * @throws \Exception from Stripe (e.g. a declined card)
     */
    public function start(User $user, string $paymentMethodId, ?string $priceId, ?string $referralId = null): array
    {
        // Subscribe to the tier the user actually selected. Only honour a
        // price that maps to an active plan; otherwise fall back to the
        // configured trial price + default plan.
        $targetPlan = $priceId
            ? Plan::where('stripe_price_id', $priceId)->where('is_active', true)->first()
            : null;
        $priceId = $targetPlan?->stripe_price_id ?? config('services.stripe.trial_price_id');
        if (! $priceId) {
            throw new BillingNotConfiguredException('Billing is not configured.');
        }

        $this->stripe->attachPaymentMethod($user, $paymentMethodId);

        $subscription = $this->stripe->createSubscription($user, $priceId, $user->subscriptionTrialTerms(), $referralId);

        $subscriptionId = $subscription['id'];
        $status = $subscription['status'] ?? 'trialing';
        $currentPeriodEnd = $subscription['current_period_end']
            ?? ($subscription['items']['data'][0]['current_period_end'] ?? null)
            ?? ($subscription['trial_end'] ?? null);
        $expiresAt = $currentPeriodEnd ? Carbon::createFromTimestamp($currentPeriodEnd) : now()->addDays(3);

        $plan = $targetPlan ?? Plan::where('name', Plan::getDefaultPlan())->first();

        DB::transaction(function () use ($user, $plan, $subscriptionId, $priceId, $status, $expiresAt) {
            // Deactivate any prior live plan rows.
            $activeIds = $user->plans()
                ->wherePivotIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)
                ->pluck('plans.id')->toArray();
            if (! empty($activeIds)) {
                $user->plans()->updateExistingPivot($activeIds, ['status' => 'inactive', 'cancelled_at' => now()]);
            }

            if ($plan) {
                $user->plans()->attach($plan->id, [
                    'stripe_subscription_id' => $subscriptionId,
                    'stripe_price_id' => $priceId,
                    'status' => $status,
                    'starts_at' => now(),
                    'expires_at' => $expiresAt,
                ]);
            }
        });

        // A plan that includes analytics backfills the imports the user missed
        // on a lower tier (the pivot fires no model events).
        AnalyticsAccess::backfill($user);

        return [
            'subscription_id' => $subscriptionId,
            'status' => $status,
            'expires_at' => $expiresAt,
            'price_id' => $priceId,
            'plan' => $plan,
        ];
    }
}
