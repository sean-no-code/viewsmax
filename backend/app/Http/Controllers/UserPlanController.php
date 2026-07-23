<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Services\CreditService;
use App\Services\StripeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserPlanController extends Controller
{
    public function getCurrentPlan(Request $request)
    {
        try {
            $user = $request->user();
            $planRow = $user->plans()
                ->wherePivotIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)
                ->first();

            if (! $planRow) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active plan found',
                ], 404);
            }

            $pivot = $planRow->pivot;

            // Expose Stripe-friendly aliases alongside the legacy fields so the SPA
            // can read either paypal_subscription_id/expires_at or
            // stripe_subscription_id/current_period_end.
            $pivotData = array_merge($pivot->toArray(), [
                'plan_id' => $pivot->stripe_price_id,
                'current_period_end' => $pivot->expires_at
                    ? Carbon::parse($pivot->expires_at)->toIso8601String()
                    : null,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'plan' => $planRow,
                    'pivot' => $pivotData,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch current plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start a new subscription via a Stripe Checkout Session.
     * Frontend redirects the user to the returned URL.
     */
    public function createCheckoutSession(Request $request, StripeService $stripeService)
    {
        $validator = Validator::make($request->all(), [
            'price_id' => 'required|string',
            'success_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $session = $stripeService->createCheckoutSession(
                $user,
                $request->input('price_id'),
                $request->input('success_url'),
                $request->input('cancel_url'),
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'url' => $session->url,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe checkout session creation error', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirm a subscription on the backend after Stripe Checkout completes.
     * This is idempotent and complements webhook delivery.
     */
    public function subscribe(Request $request, StripeService $stripeService, CreditService $creditService)
    {
        $validator = Validator::make($request->all(), [
            'stripe_subscription_id' => 'required|string',
            'stripe_price_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        Log::info('Subscribe request', ['request' => $request->all()]);

        try {
            $user = $request->user();
            $plan = Plan::where('name', Plan::getDefaultPlan())->first();
            $subscriptionId = $request->stripe_subscription_id;

            $existing = $user->plans()
                ->wherePivot('stripe_subscription_id', $subscriptionId)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Subscription already active',
                    'data' => [
                        'plan' => $plan,
                        'user' => $user->load('plans'),
                        'credits_allocated' => $creditService->getSubscriptionCredits($plan),
                    ],
                ]);
            }

            $subDetails = $stripeService->getSubscription($subscriptionId);
            $status = $subDetails['status'] ?? null;

            if (! in_array($status, ['active', 'trialing'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Subscription not active (status: {$status})",
                ], 422);
            }

            $hasTrial = $stripeService->subscriptionHasTrial($subDetails);
            $latestInvoiceId = $subDetails['latest_invoice']['id'] ?? null;
            $amountPaid = (float) ($subDetails['latest_invoice']['amount_paid'] ?? 0);

            if ($hasTrial && $amountPaid === 0.0) {
                $uniqueId = 'ACTIVATION_'.$subscriptionId;
                $actionType = 'trial_start';
            } elseif ($latestInvoiceId && $amountPaid > 0) {
                $uniqueId = $latestInvoiceId;
                $actionType = 'subscription_credit';
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment is being processed. Please check back in 1 minute.',
                    'processing' => true,
                ], 202);
            }

            $priceId = $request->stripe_price_id ?? ($subDetails['items']['data'][0]['price']['id'] ?? null);

            DB::transaction(function () use ($user, $plan, $subscriptionId, $priceId, $subDetails, $status) {
                $activeIds = $user->plans()
                    ->wherePivotIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)
                    ->pluck('plans.id')->toArray();
                if (! empty($activeIds)) {
                    $user->plans()->updateExistingPivot($activeIds, ['status' => 'inactive', 'cancelled_at' => now()]);
                }

                $nextBilling = $subDetails['current_period_end']
                    ?? $subDetails['items']['data'][0]['current_period_end']
                    ?? $subDetails['trial_end']
                    ?? null;

                $expiresAt = $nextBilling
                    ? Carbon::createFromTimestamp($nextBilling)
                    : ($plan->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth());

                $user->plans()->attach($plan->id, [
                    'stripe_subscription_id' => $subscriptionId,
                    'stripe_price_id' => $priceId,
                    // Honour the real Stripe status so a trial stays 'trialing'.
                    'status' => in_array($status, ['active', 'trialing'], true) ? $status : 'active',
                    'starts_at' => now(),
                    'expires_at' => $expiresAt,
                ]);
            });

            $credits = $creditService->getSubscriptionCredits($plan, $hasTrial);
            $creditService->allocateOrResetCredits($user, $credits, $uniqueId, $subscriptionId, $actionType);

            return response()->json([
                'success' => true,
                'message' => 'Successfully subscribed to plan',
                'data' => [
                    'plan' => $plan,
                    'plan_id' => $plan->id,
                    'user' => $user->load('plans'),
                    'credits_allocated' => $credits,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Subscription error', [
                'user_id' => $request->user()->id,
                'stripe_subscription_id' => $request->stripe_subscription_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to subscribe to plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upgrade or downgrade the user's current subscription to a new price.
     * Stripe handles proration; webhook customer.subscription.updated syncs the pivot.
     */
    public function changePlan(Request $request, StripeService $stripeService)
    {
        $validator = Validator::make($request->all(), [
            'price_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            // A subscription on its free trial has status 'trialing', not
            // 'active' — accept both so up/downgrades work during the trial.
            $activePlan = $user->plans()
                ->wherePivotIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)
                ->first();
            $subscriptionId = $activePlan?->pivot?->stripe_subscription_id;

            if (! $subscriptionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active Stripe subscription to update',
                ], 404);
            }

            // Block a downgrade that would leave the user over the target plan's
            // offer cap. They must delete offers down to the new limit first.
            $targetPlan = Plan::where('stripe_price_id', $request->price_id)->first();
            if ($targetPlan && $targetPlan->max_offers !== null) {
                $activeOffers = $user->offers()->count();
                if ($activeOffers > $targetPlan->max_offers) {
                    return response()->json([
                        'success' => false,
                        'message' => "The {$targetPlan->display_name} plan allows {$targetPlan->max_offers} offer(s), "
                            . "but you have {$activeOffers}. Please delete offers down to {$targetPlan->max_offers} "
                            . 'before downgrading.',
                    ], 422);
                }
            }

            $result = $stripeService->changeSubscriptionPrice($subscriptionId, $request->price_id);

            // Reflect the change locally right away so plan limits (e.g. the offer
            // cap) apply immediately — don't make the user wait on the webhook.
            // Repoint the active subscription row at the target plan + price.
            if ($targetPlan) {
                DB::table('user_plans')
                    ->where('user_id', $user->id)
                    ->where('stripe_subscription_id', $subscriptionId)
                    ->whereIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)
                    ->update([
                        'plan_id' => $targetPlan->id,
                        'stripe_price_id' => $targetPlan->stripe_price_id,
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Plan change requested. Stripe will finalize via webhook.',
                'data' => [
                    'subscription_id' => $subscriptionId,
                    'new_price_id' => $request->price_id,
                    'status' => $result['status'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Plan change error', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to change plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Locally cancel the active plan (does not call Stripe).
     */
    public function cancel(Request $request)
    {
        try {
            $user = $request->user();
            $activePlan = $user->activePlan();

            if (! $activePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active plan to cancel',
                ], 400);
            }

            $user->plans()->updateExistingPivot($activePlan->id, [
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Plan cancelled successfully',
                'data' => [
                    'cancelled_plan' => $activePlan,
                    'cancelled_at' => now(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a subscription with Stripe (graceful: cancel at period end by default).
     */
    public function cancelSubscriptionViaStripe(Request $request, string $subscriptionId)
    {
        Log::info('Cancel subscription via Stripe request', ['request' => $request->all()]);

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
            'immediately' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $reason = $request->input('reason', 'User requested cancellation');
            $immediately = (bool) $request->input('immediately', false);

            $userPlan = $user->plans()
                ->wherePivot('stripe_subscription_id', $subscriptionId)
                ->wherePivotIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)
                ->first();

            if (! $userPlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription not found or already cancelled',
                ], 404);
            }

            if ($userPlan->pivot->cancelled_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription is already cancelled',
                ], 422);
            }

            $stripeService = app(StripeService::class);
            $stripeService->cancelSubscription($subscriptionId, $reason, $immediately);

            $user->plans()->updateExistingPivot($userPlan->id, [
                'cancelled_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription cancelled successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getHistory(Request $request)
    {
        try {
            $user = $request->user();
            $plans = $user->plans()->withPivot(['status', 'starts_at', 'expires_at', 'cancelled_at'])->get();

            return response()->json([
                'success' => true,
                'data' => $plans,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch plan history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
