<?php

namespace App\Http\Controllers;

use App\Events\SubscriptionStarted;
use App\Models\Plan;
use App\Services\StripeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BillingController extends Controller
{
    /**
     * Create (or reuse) the Stripe customer and a SetupIntent so the SPA can
     * vault a card with no charge today.
     */
    public function setupIntent(Request $request, StripeService $stripeService)
    {
        try {
            $user = $request->user();
            $setupIntent = $stripeService->createSetupIntent($user);

            return response()->json([
                'data' => [
                    'client_secret' => $setupIntent->client_secret,
                    'customer_id' => $user->fresh()->stripe_customer_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe setup intent error', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to create setup intent.'], 500);
        }
    }

    /**
     * Lightweight billing-status read for the SPA. Unlike getCurrentPlan (which
     * is scoped to active/trialing so it correctly gates features), this surfaces
     * the latest real subscription *including* past_due — so the billing page can
     * show a "payment failed, update your card" banner. Read-only; never gates.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $planRow = $user->plans()
            ->wherePivotNotNull('stripe_subscription_id')
            ->wherePivotNotIn('status', ['inactive', 'expired'])
            ->orderByPivot('starts_at', 'desc')
            ->first();

        if (! $planRow) {
            return response()->json([
                'success' => true,
                'data' => ['has_subscription' => false],
            ]);
        }

        $pivot = $planRow->pivot;

        return response()->json([
            'success' => true,
            'data' => [
                'has_subscription' => true,
                'status' => $pivot->status,
                'plan_display_name' => $planRow->display_name,
                'plan_id' => $pivot->stripe_price_id,
                'subscription_id' => $pivot->stripe_subscription_id,
                'expires_at' => $pivot->expires_at
                    ? Carbon::parse($pivot->expires_at)->toIso8601String()
                    : null,
            ],
        ]);
    }

    /**
     * Mint a Stripe Billing Portal session URL for the SPA to redirect to. This
     * is how a past_due (or any) subscriber self-serves a card update / pays the
     * open invoice to recover — the past_due -> active flip is then driven by the
     * invoice_payment.paid webhook. Requires an existing Stripe customer.
     */
    public function billingPortal(Request $request, StripeService $stripeService)
    {
        $user = $request->user();

        if (empty($user->stripe_customer_id)) {
            return response()->json([
                'success' => false,
                'message' => 'No billing account to manage yet. Start a subscription first.',
            ], 422);
        }

        try {
            $returnUrl = config('services.stripe.portal_return_url')
                ?? config('services.stripe.success_url')
                ?? config('app.url');

            $session = $stripeService->createBillingPortalSession($user, $returnUrl);

            return response()->json([
                'success' => true,
                'data' => ['url' => $session->url],
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe billing portal error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to open the billing portal.',
            ], 500);
        }
    }

    /**
     * Attach the vaulted PaymentMethod and start the $29/mo subscription with a
     * 3-day trial (no charge today). Persists the subscription against the user.
     */
    public function subscribe(Request $request, StripeService $stripeService)
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|string',
            'price_id' => 'nullable|string',
            // Rewardful affiliate referral UUID, captured client-side.
            'referral' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Subscribe to the tier the user actually selected. Only honour a
        // price that maps to an active plan; otherwise fall back to the
        // configured trial price + default plan.
        $targetPlan = null;
        if ($request->filled('price_id')) {
            $targetPlan = Plan::where('stripe_price_id', $request->input('price_id'))
                ->where('is_active', true)
                ->first();
        }

        $priceId = $targetPlan?->stripe_price_id ?? config('services.stripe.trial_price_id');
        if (! $priceId) {
            return response()->json(['message' => 'Billing is not configured.'], 500);
        }

        try {
            $user = $request->user();

            $stripeService->attachPaymentMethod($user, $request->input('payment_method_id'));

            $subscription = $stripeService->createTrialSubscription(
                $user,
                $priceId,
                (int) config('services.stripe.trial_period_days', 3),
                $request->input('referral'),
            );

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
                    ->wherePivotIn('status', \App\Models\User::ACTIVE_SUBSCRIPTION_STATUSES)
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

            // Card added → subscription live. Drive Kit off this (converted tag +
            // abandoned-tag removal) via a queued listener. Dispatched AFTER the
            // transaction commits (queue after_commit=false, so an in-transaction
            // dispatch could fire before commit / on a row that rolled back).
            SubscriptionStarted::dispatch($user);

            return response()->json([
                'data' => [
                    'stripe_subscription_id' => $subscriptionId,
                    'status' => $status,
                    'current_period_end' => $expiresAt->toIso8601String(),
                    'plan_id' => $priceId,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe subscribe error', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to start subscription. '.$e->getMessage()], 422);
        }
    }
}
