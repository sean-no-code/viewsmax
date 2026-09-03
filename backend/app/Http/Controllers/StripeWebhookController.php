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

class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeService $stripeService,
        protected CreditService $creditService,
    ) {}

    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        $skipSignatureCheck = config('services.stripe.signature_check') === false;

        try {
            if ($skipSignatureCheck) {
                Log::info('Stripe webhook: Signature verification SKIPPED for testing');
                $event = json_decode($payload, true);
                if (! $event) {
                    return response()->json(['error' => 'Invalid payload'], 400);
                }
                $eventType = $event['type'] ?? null;
                $resource = $event['data']['object'] ?? [];
            } else {
                $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader ?? '');
                $eventType = $event->type;
                $resource = $event->data->object->toArray();
            }
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook: Invalid payload', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook: Signature verification failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook: Error', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Internal error'], 500);
        }

        Log::info('Stripe webhook received', [
            'event_type' => $eventType,
            'resource_id' => $resource['id'] ?? null,
        ]);

        try {
            switch ($eventType) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($resource);
                    break;

                case 'invoice.paid':
                case 'invoice.payment_succeeded':
                    $this->handleInvoicePaid($resource);
                    break;

                // Newer Stripe accounts report a paid invoice via this event
                // (object = InvoicePayment) and may NOT send legacy invoice.paid.
                // Resolve the invoice and run the same allocation (idempotent by
                // invoice id, so this is safe even when both events fire).
                case 'invoice_payment.paid':
                    $this->handleInvoicePaymentPaid($resource);
                    break;

                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($resource);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionCancelled($resource);
                    break;

                case 'invoice.payment_failed':
                    $this->handleInvoicePaymentFailed($resource);
                    break;

                default:
                    Log::info('Stripe webhook: Unhandled event type', ['event_type' => $eventType]);
                    break;
            }
        } catch (\Exception $e) {
            Log::error('Stripe webhook handler error', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Checkout session completed = brand new subscription signup.
     * The actual credit allocation happens via invoice.paid; here we attach the subscription
     * record to the user so subsequent invoice events have something to look up.
     */
    private function handleCheckoutCompleted(array $session): void
    {
        $subscriptionId = $session['subscription'] ?? null;
        // metadata.user_id is authoritative. client_reference_id now carries
        // the Rewardful referral UUID when the signup came through an
        // affiliate link — only trust it as a user id when it's numeric
        // (sessions created before the Rewardful integration).
        $clientRef = $session['client_reference_id'] ?? null;
        $userId = $session['metadata']['user_id']
            ?? (is_numeric($clientRef) ? $clientRef : null);
        $customerId = $session['customer'] ?? null;

        if (! $subscriptionId || ! $userId) {
            Log::error('Stripe webhook: checkout.session.completed missing subscription or user', [
                'session_id' => $session['id'] ?? null,
            ]);

            return;
        }

        $user = User::find($userId);
        if (! $user) {
            Log::error("Stripe webhook: User {$userId} not found for checkout session");

            return;
        }

        if ($customerId && empty($user->stripe_customer_id)) {
            $user->stripe_customer_id = $customerId;
            $user->save();
        }

        // Completed checkout = the card was actually entered. The Stripe
        // customer alone doesn't prove that (it's created before the card
        // screen), so admin reporting keys off this timestamp instead.
        if (! $user->card_added_at) {
            $user->forceFill(['card_added_at' => now()])->save();
        }

        if ($this->creditService->isSubscriptionAttached($user, $subscriptionId)) {
            Log::info('Stripe webhook: Subscription already attached', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $subDetails = $this->stripeService->getSubscription($subscriptionId);
        $priceId = $subDetails['items']['data'][0]['price']['id'] ?? null;
        $nextBilling = $this->extractNextBilling($subDetails);

        $this->creditService->ensureSubscriptionAttached($user, $subscriptionId, $priceId, $nextBilling);

        // Free trial ($0) - allocate credits now; paid signups will allocate on invoice.paid
        $hasTrial = $this->stripeService->subscriptionHasTrial($subDetails);
        $latestInvoiceAmount = (float) ($subDetails['latest_invoice']['amount_paid'] ?? 0);

        if ($hasTrial && $latestInvoiceAmount === 0.0) {
            $attachedPlan = $user->plans()->wherePivot('stripe_subscription_id', $subscriptionId)->first();
            if (! $attachedPlan) {
                return;
            }

            $syntheticId = 'ACTIVATION_'.$subscriptionId;
            $credits = $this->creditService->getSubscriptionCredits($attachedPlan, true);

            $this->creditService->allocateOrResetCredits(
                $user,
                $credits,
                $syntheticId,
                $subscriptionId,
                'trial_start'
            );
        }
    }

    /**
     * Invoice paid = initial paid signup OR recurring renewal.
     */
    private function handleInvoicePaid(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        $invoiceId = $invoice['id'] ?? null;
        $billingReason = $invoice['billing_reason'] ?? null;

        if (! $subscriptionId || ! $invoiceId) {
            Log::warning('Stripe webhook: invoice.paid missing subscription or invoice id', [
                'invoice_id' => $invoiceId,
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        DB::transaction(function () use ($subscriptionId, $invoiceId, $invoice, $billingReason) {
            $subDetails = $this->stripeService->getSubscription($subscriptionId);

            $priceId = $subDetails['items']['data'][0]['price']['id'] ?? null;
            $nextBilling = $this->extractNextBilling($subDetails);

            // Find user: by existing attachment, then by subscription metadata, then by customer
            $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
                $q->where('stripe_subscription_id', $subscriptionId);
            })->first();

            if (! $user) {
                $metaUserId = $subDetails['metadata']['user_id'] ?? null;
                if ($metaUserId) {
                    $user = User::find($metaUserId);
                }
            }

            if (! $user && ! empty($subDetails['customer'])) {
                $user = User::where('stripe_customer_id', $subDetails['customer'])->first();
            }

            if (! $user) {
                Log::error('Stripe webhook: User not found for invoice', [
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                ]);
                throw new \Exception("User not found for subscription {$subscriptionId}");
            }

            $this->creditService->ensureSubscriptionAttached($user, $subscriptionId, $priceId, $nextBilling);

            // Safety net for a missed checkout.session.completed: a paid
            // subscription invoice means a card exists.
            if (! $user->card_added_at) {
                $user->forceFill(['card_added_at' => now()])->save();
            }

            $targetPlan = $user->plans()->wherePivot('stripe_subscription_id', $subscriptionId)->first();
            if (! $targetPlan) {
                throw new \Exception("Failed to find attached plan for subscription {$subscriptionId}");
            }

            // Trial logic: subscription_create with $0 amount is trial; renewals (cycle) are not.
            $amountPaid = (float) ($invoice['amount_paid'] ?? 0);
            $isTrial = ($billingReason === 'subscription_create' && $amountPaid === 0.0)
                || ($subDetails['status'] ?? null) === 'trialing';

            $credits = $this->creditService->getSubscriptionCredits($targetPlan, $isTrial);
            $actionType = $isTrial ? 'trial_start' : 'subscription_credit';

            $allocated = $this->creditService->allocateOrResetCredits(
                $user,
                $credits,
                $invoiceId,
                $subscriptionId,
                $actionType
            );

            if (! $allocated) {
                Log::info('Stripe webhook: Invoice already processed', [
                    'user_id' => $user->id,
                    'invoice_id' => $invoiceId,
                ]);

                return;
            }

            // Update expiration + (re)activate the plan
            if (! $nextBilling) {
                throw new \Exception("Stripe subscription {$subscriptionId} missing current_period_end");
            }

            $newExpiresAt = Carbon::parse($nextBilling);

            // Preserve the real Stripe lifecycle status: a trial-start invoice
            // (the $0 invoice) must keep the row 'trialing', not flip it to
            // 'active'. Renewals report 'active' and set it accordingly.
            $stripeStatus = $subDetails['status'] ?? 'active';
            $localStatus = in_array($stripeStatus, ['active', 'trialing'], true) ? $stripeStatus : 'active';

            $user->plans()
                ->wherePivotIn('status', ['active', 'trialing'])
                ->each(function ($otherPlan) use ($user) {
                    if ($otherPlan->pivot->stripe_subscription_id) {
                        return; // leave the current sub alone
                    }
                    $user->plans()->updateExistingPivot($otherPlan->id, [
                        'status' => 'inactive',
                        'cancelled_at' => now(),
                    ]);
                });

            $user->plans()
                ->wherePivot('stripe_subscription_id', $subscriptionId)
                ->updateExistingPivot($targetPlan->id, [
                    'expires_at' => $newExpiresAt,
                    'cancelled_at' => null,
                    'status' => $localStatus,
                ]);

            Log::info('Stripe webhook: Credits allocated for invoice', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoiceId,
                'credits' => $credits,
                'is_trial' => $isTrial,
                'expires_at' => $newExpiresAt->toDateTimeString(),
            ]);
        });
    }

    /**
     * `invoice_payment.paid` (object = InvoicePayment) — the current-API way Stripe
     * reports a paid invoice. Resolve the underlying invoice and defer to the same
     * paid-invoice logic; the invoice-id idempotency in handleInvoicePaid makes this
     * safe to run alongside a legacy invoice.paid for the same invoice.
     */
    private function handleInvoicePaymentPaid(array $invoicePayment): void
    {
        $invoiceId = $invoicePayment['invoice']
            ?? $invoicePayment['invoice']['id']
            ?? null;

        if (! $invoiceId || ! is_string($invoiceId)) {
            Log::warning('Stripe webhook: invoice_payment.paid missing invoice id', [
                'invoice_payment_id' => $invoicePayment['id'] ?? null,
            ]);

            return;
        }

        $invoice = $this->stripeService->getInvoice($invoiceId);
        $this->handleInvoicePaid($invoice);
    }

    /**
     * Subscription updated - handle upgrades/downgrades by updating the stored price/plan.
     */
    private function handleSubscriptionUpdated(array $subscription): void
    {
        $subscriptionId = $subscription['id'] ?? null;
        if (! $subscriptionId) {
            return;
        }

        $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
            $q->where('stripe_subscription_id', $subscriptionId);
        })->first();

        if (! $user) {
            Log::info('Stripe webhook: subscription.updated for unknown subscription', [
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $priceId = $subscription['items']['data'][0]['price']['id'] ?? null;
        $nextBilling = $this->extractNextBilling($subscription);
        $status = $subscription['status'] ?? null;
        $cancelAtPeriodEnd = ! empty($subscription['cancel_at_period_end']);

        $updates = [];
        if ($priceId) {
            $updates['stripe_price_id'] = $priceId;
        }
        if ($nextBilling) {
            $updates['expires_at'] = Carbon::parse($nextBilling);
        }
        if ($cancelAtPeriodEnd) {
            $updates['cancelled_at'] = now();
        } elseif (in_array($status, ['active', 'trialing'], true)) {
            $updates['cancelled_at'] = null;
            $updates['status'] = $status; // 'active' or 'trialing'
        } elseif (in_array($status, ['past_due', 'unpaid'], true)) {
            $updates['status'] = 'past_due';
        } elseif (in_array($status, ['canceled', 'incomplete_expired'], true)) {
            $updates['status'] = 'cancelled';
        }

        if ($updates) {
            $user->plans()
                ->wherePivot('stripe_subscription_id', $subscriptionId)
                ->each(function ($plan) use ($user, $updates) {
                    $user->plans()->updateExistingPivot($plan->id, $updates);
                });
        }

        // On an up/downgrade the price changes — repoint the pivot's plan_id at
        // the plan that owns the new price so plan limits resolve correctly.
        if ($priceId) {
            $matchedPlan = Plan::where('stripe_price_id', $priceId)->first();
            if ($matchedPlan) {
                DB::table('user_plans')
                    ->where('user_id', $user->id)
                    ->where('stripe_subscription_id', $subscriptionId)
                    ->update(['plan_id' => $matchedPlan->id, 'updated_at' => now()]);
            }
        }

        Log::info('Stripe webhook: Subscription updated', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
            'updates' => $updates,
            'stripe_status' => $status,
        ]);
    }

    private function handleSubscriptionCancelled(array $subscription): void
    {
        $subscriptionId = $subscription['id'] ?? null;
        if (! $subscriptionId) {
            return;
        }

        $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
            $q->where('stripe_subscription_id', $subscriptionId);
        })->first();

        if (! $user) {
            Log::error('Stripe webhook: User not found for cancellation', [
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $pivotPlan = $user->plans()
            ->wherePivot('stripe_subscription_id', $subscriptionId)
            ->first();

        if (! $pivotPlan) {
            return;
        }

        if ($pivotPlan->pivot->cancelled_at) {
            Log::info('Stripe webhook: Subscription already cancelled (idempotent)', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        // Graceful cancel: mark cancelled_at, leave status active until expires_at passes
        $user->plans()->updateExistingPivot($pivotPlan->id, [
            'cancelled_at' => now(),
        ]);

        Log::info('Stripe webhook: Subscription marked cancelled', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
        ]);
    }

    /**
     * Invoice payment failed - mark the subscription past_due so has_active_subscription
     * reflects the lapse (e.g. when the card declines after the trial).
     */
    private function handleInvoicePaymentFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        if (! $subscriptionId) {
            return;
        }

        $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
            $q->where('stripe_subscription_id', $subscriptionId);
        })->first();

        if (! $user) {
            return;
        }

        $user->plans()
            ->wherePivot('stripe_subscription_id', $subscriptionId)
            ->each(function ($plan) use ($user) {
                $user->plans()->updateExistingPivot($plan->id, ['status' => 'past_due']);
            });

        Log::info('Stripe webhook: Subscription marked past_due', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
        ]);
    }

    /**
     * Stripe puts the next-billing timestamp either on the subscription's current_period_end
     * (legacy) or on each subscription item (new schema). Try both and return ISO8601 string.
     */
    private function extractNextBilling(array $subscription): ?string
    {
        $candidates = [
            $subscription['current_period_end'] ?? null,
            $subscription['items']['data'][0]['current_period_end'] ?? null,
            $subscription['trial_end'] ?? null,
        ];

        foreach ($candidates as $ts) {
            if ($ts) {
                return Carbon::createFromTimestamp($ts)->toIso8601String();
            }
        }

        return null;
    }
}
