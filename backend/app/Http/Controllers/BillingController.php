<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\BillingNotConfiguredException;
use App\Services\StripeService;
use App\Services\SubscriptionStarter;
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
     * Attach the vaulted PaymentMethod and start the subscription on the trial
     * terms the user is due (SubscriptionStarter / User::subscriptionTrialTerms:
     * the activation fee today while the free window is open, the plan price
     * today once it has closed). Persists the subscription against the user.
     */
    public function subscribe(Request $request, SubscriptionStarter $starter)
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

        try {
            $started = $starter->start(
                $request->user(),
                $request->input('payment_method_id'),
                $request->input('price_id'),
                $request->input('referral'),
            );

            return response()->json([
                'data' => [
                    'stripe_subscription_id' => $started['subscription_id'],
                    'status' => $started['status'],
                    'current_period_end' => $started['expires_at']->toIso8601String(),
                    'plan_id' => $started['price_id'],
                ],
            ]);
        } catch (BillingNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Stripe subscribe error', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => \App\Support\UserSafeError::withDetail('Failed to start subscription. ', $e)], 422);
        }
    }
}
